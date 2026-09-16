<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Instalment;
use App\Models\Lead;
use App\Models\Matter;
use App\Services\Sms\TwilioSmsClient;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SmsService
{
    public function __construct(protected TwilioSmsClient $client) {}

    /**
     * Send an SMS via Twilio. Deliberately does NOT catch-and-swallow its
     * own failures: ProcessPaymentChases and ProcessLeadNurture already wrap
     * their dispatch calls in a try/catch that marks the ChaseLog/
     * NurtureSequence row Failed on any thrown exception — silently
     * absorbing the error here would make every failed send look like a
     * success to that existing tracking. Logged both to the app log
     * (immediate visibility) and the audit trail (log_name 'sms' — who was
     * texted, what was sent, whether it worked, tied to the record it was
     * about when one's given) before rethrowing.
     */
    public function send(string $to, string $message, ?Model $subject = null): void
    {
        try {
            $sid = $this->client->send($to, (string) config('services.twilio.from_number'), $message);
        } catch (Throwable $exception) {
            Log::error('SMS send failed', [
                'to' => $to,
                'message_preview' => Str::limit($message, 50),
                'error' => $exception->getMessage(),
            ]);

            $this->logActivity('failed', $to, $message, $subject, error: $exception->getMessage());

            throw $exception;
        }

        $this->logActivity('sent', $to, $message, $subject, sid: $sid);
    }

    private function logActivity(string $event, string $to, string $message, ?Model $subject, ?string $sid = null, ?string $error = null): void
    {
        $log = activity('sms')
            ->withProperties(array_filter([
                'to' => $to,
                'message' => $message,
                'sid' => $sid,
                'error' => $error,
            ], fn (mixed $value): bool => $value !== null));

        if ($subject !== null) {
            $log->performedOn($subject);
        }

        $log->tap(function ($activity): void {
            $activity->tenant_id = CurrentTenant::id();
        })
            ->event($event)
            ->log($event === 'sent' ? 'SMS sent' : 'SMS send failed');
    }

    public function chaseStep1(Instalment $instalment): void
    {
        $matter = $instalment->paymentPlan->matter;
        $client = $matter->client;

        $this->send(
            $client->phone,
            "Hi {$client->first_name}, a reminder that £{$instalment->amount} for matter {$matter->reference} was due on {$instalment->due_date->format('d/m/Y')}. Please contact us to arrange payment.",
            $instalment,
        );
    }

    public function chaseStep2(Instalment $instalment): void
    {
        $matter = $instalment->paymentPlan->matter;
        $client = $matter->client;

        $this->send(
            $client->phone,
            "Hi {$client->first_name}, £{$instalment->amount} for matter {$matter->reference} is now significantly overdue. Please contact us urgently to avoid further action.",
            $instalment,
        );
    }

    public function nurtureStep1(Lead $lead): void
    {
        $this->send(
            $lead->mobile ?: $lead->telephone,
            "Hi {$lead->first_name}, following up on your {$lead->practice_area} enquiry with Kaselog. Reply to this message or call us to book a consultation.",
            $lead,
        );
    }

    public function portalInvite(Client $client, Matter $matter, string $signedUrl): void
    {
        $this->send(
            $client->phone,
            "Hi {$client->first_name}, set up your {$matter->tenant->name} client portal account for matter {$matter->reference}: {$signedUrl}",
            $matter,
        );
    }
}
