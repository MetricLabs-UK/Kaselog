<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\ClientSource;
use App\Enums\LeadStatus;
use App\Enums\MatterStatus;
use App\Http\Controllers\Controller;
use App\Models\CallNote;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Matter;
use App\Models\RetellCallLog;
use App\Models\Tenant;
use App\Support\PhoneNumber;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class RetellWebhookController extends Controller
{
    /**
     * Only call_analyzed carries call_analysis (summary + custom_analysis_data) —
     * call_started/call_ended are logged for audit but have nothing to act on yet.
     */
    private const ANALYZED_EVENT = 'call_analyzed';

    /**
     * Matter statuses still considered "open" for auto-attaching a call note.
     */
    private const OPEN_MATTER_STATUSES = [MatterStatus::Active, MatterStatus::Suspended];

    public function handle(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $signature = $request->header('X-Retell-Signature');

        if (blank($signature) || ! $this->hasValidSignature($rawBody, $signature)) {
            Log::warning('Retell webhook: invalid or missing signature.');

            return response()->json(['status' => 'invalid signature'], 401);
        }

        $payload = json_decode($rawBody, true) ?? [];
        $call = $payload['call'] ?? [];
        $callId = $call['call_id'] ?? null;
        $agentId = $call['agent_id'] ?? null;
        $eventType = $payload['event'] ?? null;

        // Every record this webhook can create (Lead, CallNote) requires a
        // tenant, and there's no session/panel context to resolve one from —
        // it has to come from which brand's Retell agent placed this call.
        // Everything downstream (client phone matching, matter lookups) must
        // run inside this tenant's scope too, or it could cross-match another
        // tenant's data by coincidence (e.g. a shared phone number).
        $tenant = filled($agentId) ? Tenant::findByRetellAgentId($agentId) : null;
        $previousTenant = CurrentTenant::get();
        CurrentTenant::set($tenant);

        try {
            return $this->processWebhook($payload, $call, $callId, $agentId, $eventType, $tenant);
        } finally {
            CurrentTenant::set($previousTenant);
        }
    }

    private function processWebhook(
        array $payload,
        array $call,
        ?string $callId,
        ?string $agentId,
        ?string $eventType,
        ?Tenant $tenant,
    ): JsonResponse {
        try {
            $log = RetellCallLog::create([
                'call_id' => $callId,
                'agent_id' => $agentId,
                'event_type' => $eventType,
                'raw_payload' => $payload,
            ]);
        } catch (Throwable $e) {
            Log::error('Retell webhook: failed to log payload.', [
                'exception' => $e->getMessage(),
            ]);

            return response()->json(['status' => 'ok']);
        }

        if ($eventType !== self::ANALYZED_EVENT) {
            $log->update(['processed_at' => now()]);

            return response()->json(['status' => 'ok']);
        }

        if ($tenant === null) {
            Log::warning('Retell webhook: agent_id is not mapped to any tenant, skipping business processing.', [
                'agent_id' => $agentId,
                'call_id' => $callId,
            ]);
            $log->update(['processed_at' => now()]);

            return response()->json(['status' => 'ok']);
        }

        try {
            if (filled($callId) && CallNote::where('call_id', $callId)->exists()) {
                Log::info('Retell webhook: call_analyzed already processed, skipping.', [
                    'call_id' => $callId,
                ]);
            } else {
                $this->processAnalyzedCall($call);
            }

            $log->update(['processed_at' => now()]);
        } catch (Throwable $e) {
            Log::error('Retell webhook: failed to process call_analyzed event.', [
                'call_id' => $callId,
                'exception' => $e->getMessage(),
            ]);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Verifies X-Retell-Signature ("v={timestamp},d={hmac}"): HMAC-SHA256 of
     * raw_body.timestamp using the account API key, rejecting anything older
     * than 5 minutes. See https://docs.retellai.com/features/secure-webhook.
     */
    private function hasValidSignature(string $rawBody, string $signature): bool
    {
        $apiKey = config('services.retell.api_key');

        if (blank($apiKey)) {
            return false;
        }

        if (! preg_match('/v=(\d+),d=(.*)/', $signature, $matches)) {
            return false;
        }

        [, $timestamp, $digest] = $matches;

        $now = (int) round(microtime(true) * 1000);
        if (abs($now - (int) $timestamp) > 5 * 60 * 1000) {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody.$timestamp, (string) $apiKey);

        return hash_equals($expected, $digest);
    }

    private function processAnalyzedCall(array $call): void
    {
        $callId = $call['call_id'] ?? null;
        $transcript = $call['transcript'] ?? null;
        $analysis = $call['call_analysis'] ?? [];
        $summary = $analysis['call_summary'] ?? null;
        $customData = $analysis['custom_analysis_data'] ?? [];

        // 1. Known outbound call — dynamic variables tell us which Matter this
        // belongs to. Attach directly and skip client-matching entirely.
        $matterId = $call['retell_llm_dynamic_variables']['matter_id'] ?? null;

        if (filled($matterId)) {
            $matter = Matter::find($matterId);

            CallNote::create([
                'matter_id' => $matter?->id,
                'call_id' => $callId,
                'transcript' => $transcript,
                'summary' => $summary,
                'needs_review' => $matter === null,
                'review_reason' => $matter === null
                    ? "Outbound call referenced matter_id {$matterId}, which was not found."
                    : null,
            ]);

            return;
        }

        // 2. Inbound call claiming to be an existing client — match by phone.
        if (($customData['is_existing_client'] ?? false) === true) {
            $this->handleExistingClientCall($callId, $transcript, $summary, $customData);

            return;
        }

        // 3. Everything else: treat as a new enquiry.
        $this->createLeadFromCall($callId, $transcript, $summary, $customData);
    }

    private function handleExistingClientCall(?string $callId, ?string $transcript, ?string $summary, array $customData): void
    {
        $normalizedPhone = PhoneNumber::normalize($customData['phone_number'] ?? null);

        $matches = blank($normalizedPhone)
            ? collect()
            : Client::all()->filter(fn (Client $client) => PhoneNumber::normalize($client->phone) === $normalizedPhone);

        if ($matches->count() === 1) {
            $client = $matches->first();

            $openMatters = $client->matters()
                ->whereIn('status', self::OPEN_MATTER_STATUSES)
                ->get();

            if ($openMatters->count() === 1) {
                CallNote::create([
                    'matter_id' => $openMatters->first()->id,
                    'client_id' => $client->id,
                    'call_id' => $callId,
                    'transcript' => $transcript,
                    'summary' => $summary,
                ]);

                return;
            }

            if ($openMatters->count() > 1) {
                $caseCategory = $customData['case_category'] ?? null;
                $categoryMatches = $this->matchMattersByCategory($openMatters, $caseCategory);

                if ($categoryMatches->count() === 1) {
                    CallNote::create([
                        'matter_id' => $categoryMatches->first()->id,
                        'client_id' => $client->id,
                        'call_id' => $callId,
                        'transcript' => $transcript,
                        'summary' => $summary,
                    ]);

                    return;
                }

                $categoryLabel = filled($caseCategory) ? $caseCategory : 'none provided';

                CallNote::create([
                    'client_id' => $client->id,
                    'call_id' => $callId,
                    'transcript' => $transcript,
                    'summary' => $summary,
                    'needs_review' => true,
                    'review_reason' => $categoryMatches->isEmpty()
                        ? "Existing client matched by phone with {$openMatters->count()} open matters; case_category \"{$categoryLabel}\" did not match any of them — needs manual matter assignment."
                        : "Existing client matched by phone with {$openMatters->count()} open matters; case_category \"{$categoryLabel}\" matched {$categoryMatches->count()} of them — needs manual matter assignment.",
                ]);

                return;
            }

            CallNote::create([
                'client_id' => $client->id,
                'call_id' => $callId,
                'transcript' => $transcript,
                'summary' => $summary,
                'needs_review' => true,
                'review_reason' => 'Existing client matched by phone but has no open matter — needs manual matter assignment.',
            ]);

            return;
        }

        Log::warning('Retell webhook: existing-client call could not be matched to a Client.', [
            'call_id' => $callId,
            'normalized_phone' => $normalizedPhone,
            'candidate_matches' => $matches->count(),
        ]);

        CallNote::create([
            'call_id' => $callId,
            'transcript' => $transcript,
            'summary' => $summary,
            'needs_review' => true,
            'review_reason' => $matches->count() > 1
                ? 'Caller claimed to be an existing client but more than one Client record matched the phone number.'
                : 'Caller claimed to be an existing client but no matching Client record was found by phone number.',
        ]);
    }

    private function createLeadFromCall(?string $callId, ?string $transcript, ?string $summary, array $customData): void
    {
        [$firstName, $lastName] = $this->splitName($customData['caller_name'] ?? null);

        $lead = Lead::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => '',
            'telephone' => $customData['phone_number'] ?? '',
            'source' => ClientSource::Phone,
            'practice_area' => $customData['case_category'] ?? 'Unknown',
            'message' => $customData['case_description'] ?? $summary ?? '',
            'status' => LeadStatus::New,
        ]);

        CallNote::create([
            'lead_id' => $lead->id,
            'call_id' => $callId,
            'transcript' => $transcript,
            'summary' => $summary,
        ]);
    }

    /**
     * Disambiguates multiple open Matters for the same Client by comparing
     * the caller's stated case_category against each Matter's practice_area.
     *
     * @param  Collection<int, Matter>  $matters
     * @return Collection<int, Matter>
     */
    private function matchMattersByCategory(Collection $matters, ?string $caseCategory): Collection
    {
        if (blank($caseCategory)) {
            return collect();
        }

        $normalizedCategory = $this->normalizeCategory($caseCategory);

        return $matters->filter(
            fn (Matter $matter) => $this->normalizeCategory($matter->practice_area) === $normalizedCategory
        );
    }

    private function normalizeCategory(?string $category): ?string
    {
        return blank($category) ? null : strtolower(trim($category));
    }

    private function splitName(?string $name): array
    {
        $name = trim((string) $name);

        if ($name === '') {
            return ['Unknown', 'Caller'];
        }

        $parts = preg_split('/\s+/', $name, 2);

        return [$parts[0], $parts[1] ?? ''];
    }
}
