<?php

namespace App\Jobs;

use App\Enums\ChaseLogChannel;
use App\Enums\ChaseLogStatus;
use App\Enums\InstalmentStatus;
use App\Enums\MatterStatus;
use App\Mail\ChaseEmailOne;
use App\Mail\ChaseEmailTwo;
use App\Models\ChaseLog;
use App\Models\Instalment;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\MatterSuspendedNotification;
use App\Services\SmsService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

class ProcessPaymentChases implements ShouldQueue
{
    use Queueable;

    /**
     * Chase tiers, in ascending order of days overdue.
     *
     * @var array<int, array{days: int, channel: ChaseLogChannel, template: string}>
     */
    private const TIERS = [
        ['days' => 3, 'channel' => ChaseLogChannel::Email, 'template' => 'chase_email_1'],
        ['days' => 7, 'channel' => ChaseLogChannel::Email, 'template' => 'chase_email_2'],
        ['days' => 14, 'channel' => ChaseLogChannel::Sms, 'template' => 'chase_sms_1'],
        ['days' => 21, 'channel' => ChaseLogChannel::Sms, 'template' => 'chase_sms_2'],
    ];

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
        $instalments = Instalment::query()
            ->where('due_date', '<', today())
            ->whereNull('paid_at')
            ->where('status', '!=', InstalmentStatus::Waived)
            ->with('paymentPlan.matter.client')
            ->get();

        foreach ($instalments as $instalment) {
            $this->processInstalment($instalment);
        }
    }

    private function processInstalment(Instalment $instalment): void
    {
        $daysOverdue = $instalment->daysOverdue();

        if ($instalment->status !== InstalmentStatus::Overdue) {
            $instalment->update(['status' => InstalmentStatus::Overdue]);
        }

        foreach (self::TIERS as $tier) {
            if ($daysOverdue < $tier['days']) {
                continue;
            }

            $alreadyLogged = ChaseLog::query()
                ->where('instalment_id', $instalment->id)
                ->where('channel', $tier['channel'])
                ->where('template', $tier['template'])
                ->exists();

            if ($alreadyLogged) {
                continue;
            }

            $this->sendTier($instalment, $tier, $daysOverdue);
        }
    }

    /**
     * @param  array{days: int, channel: ChaseLogChannel, template: string}  $tier
     */
    private function sendTier(Instalment $instalment, array $tier, int $daysOverdue): void
    {
        $status = ChaseLogStatus::Sent;

        try {
            $this->dispatchTier($instalment, $tier['template'], $daysOverdue);
        } catch (\Throwable $exception) {
            Log::error("Chase tier {$tier['template']} failed for instalment {$instalment->id}: {$exception->getMessage()}");
            $status = ChaseLogStatus::Failed;
        }

        ChaseLog::create([
            'instalment_id' => $instalment->id,
            'channel' => $tier['channel'],
            'template' => $tier['template'],
            'sent_at' => now(),
            'status' => $status,
        ]);

        if ($tier['template'] === 'chase_sms_2' && $status === ChaseLogStatus::Sent) {
            $this->escalateToSuspended($instalment);
        }
    }

    private function dispatchTier(Instalment $instalment, string $template, int $daysOverdue): void
    {
        $client = $instalment->paymentPlan->matter->client;

        match ($template) {
            'chase_email_1' => Mail::to($client->email)->send(new ChaseEmailOne($instalment, $daysOverdue)),
            'chase_email_2' => Mail::to($client->email)->send(new ChaseEmailTwo($instalment, $daysOverdue)),
            'chase_sms_1' => app(SmsService::class)->chaseStep1($instalment),
            'chase_sms_2' => app(SmsService::class)->chaseStep2($instalment),
        };
    }

    private function escalateToSuspended(Instalment $instalment): void
    {
        $matter = $instalment->paymentPlan->matter;

        if ($matter->status === MatterStatus::Suspended) {
            return;
        }

        $matter->update(['status' => MatterStatus::Suspended]);

        Notification::send(
            User::role('director')->get(),
            new MatterSuspendedNotification($matter, $instalment),
        );
    }
}
