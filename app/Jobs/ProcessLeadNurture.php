<?php

namespace App\Jobs;

use App\Enums\ChaseLogChannel;
use App\Enums\LeadStatus;
use App\Enums\NurtureSequenceStatus;
use App\Mail\NurtureEmailOne;
use App\Mail\NurtureEmailTwo;
use App\Mail\NurtureEmailZero;
use App\Models\Lead;
use App\Models\NurtureSequence;
use App\Models\Scopes\ExcludeConvertedLeadsScope;
use App\Models\Tenant;
use App\Services\SmsService;
use App\Support\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class ProcessLeadNurture implements ShouldQueue
{
    use Queueable;

    /**
     * A scheduled batch job spans every tenant — it processes each brand's
     * slice of work under that brand's own tenant context, never all at once.
     */
    public function handle(): void
    {
        $previousTenant = CurrentTenant::get();

        try {
            foreach (Tenant::all() as $tenant) {
                CurrentTenant::set($tenant);
                $this->processTenant();
            }
        } finally {
            CurrentTenant::set($previousTenant);
        }
    }

    private function processTenant(): void
    {
        $leads = Lead::withoutGlobalScope(ExcludeConvertedLeadsScope::class)
            ->whereIn('status', [LeadStatus::New, LeadStatus::Contacted])
            ->where('nurture_stage', '<', 4)
            ->get();

        foreach ($leads as $lead) {
            $this->processLead($lead);
        }
    }

    private function processLead(Lead $lead): void
    {
        match ($lead->nurture_stage) {
            0 => $this->sendStage0($lead),
            1 => $this->sendStage1($lead),
            2 => $this->sendStage2($lead),
            3 => $this->sendStage3($lead),
            default => null,
        };
    }

    private function sendStage0(Lead $lead): void
    {
        if ($this->alreadySent($lead, 0)) {
            return;
        }

        $sent = $this->dispatchAndLog($lead, 0, ChaseLogChannel::Email, fn () => Mail::to($lead->email)->send(new NurtureEmailZero($lead)));

        if ($sent) {
            $lead->update(['nurture_stage' => 1, 'last_contacted_at' => now()]);
        }
    }

    private function sendStage1(Lead $lead): void
    {
        $reference = $lead->last_contacted_at ?? $lead->created_at;

        if ($reference->diffInHours(now(), absolute: true) < 48) {
            return;
        }

        if ($this->alreadySent($lead, 1)) {
            return;
        }

        $sent = $this->dispatchAndLog($lead, 1, ChaseLogChannel::Email, fn () => Mail::to($lead->email)->send(new NurtureEmailOne($lead)));

        if ($sent) {
            $lead->update(['nurture_stage' => 2, 'last_contacted_at' => now()]);
        }
    }

    private function sendStage2(Lead $lead): void
    {
        if ($lead->created_at->diffInDays(now(), absolute: true) < 5) {
            return;
        }

        if ($this->alreadySent($lead, 2)) {
            return;
        }

        $sent = $this->dispatchAndLog($lead, 2, ChaseLogChannel::Sms, fn () => app(SmsService::class)->nurtureStep1($lead));

        if ($sent) {
            $lead->update(['nurture_stage' => 3, 'last_contacted_at' => now()]);
        }
    }

    private function sendStage3(Lead $lead): void
    {
        if ($lead->created_at->diffInDays(now(), absolute: true) < 10) {
            return;
        }

        if ($this->alreadySent($lead, 3)) {
            return;
        }

        $sent = $this->dispatchAndLog($lead, 3, ChaseLogChannel::Email, fn () => Mail::to($lead->email)->send(new NurtureEmailTwo($lead)));

        if ($sent) {
            $lead->update([
                'status' => LeadStatus::Lost,
                'nurture_stage' => 4,
                'last_contacted_at' => now(),
            ]);
        }
    }

    private function alreadySent(Lead $lead, int $step): bool
    {
        return NurtureSequence::query()
            ->where('lead_id', $lead->id)
            ->where('step', $step)
            ->exists();
    }

    private function dispatchAndLog(Lead $lead, int $step, ChaseLogChannel $channel, Closure $action): bool
    {
        $status = NurtureSequenceStatus::Sent;

        try {
            $action();
        } catch (Throwable $exception) {
            Log::error("Nurture step {$step} failed for lead {$lead->id}: {$exception->getMessage()}");
            $status = NurtureSequenceStatus::Failed;
        }

        NurtureSequence::create([
            'lead_id' => $lead->id,
            'step' => $step,
            'channel' => $channel,
            'scheduled_at' => now(),
            'sent_at' => now(),
            'status' => $status,
        ]);

        return $status === NurtureSequenceStatus::Sent;
    }
}
