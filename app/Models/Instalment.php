<?php

namespace App\Models;

use App\Concerns\HasReasonedActivityLog;
use App\Enums\ChaseLogStatus;
use App\Enums\InstalmentStatus;
use App\Enums\MatterStatus;
use App\Enums\ReconciliationIssueReason;
use App\Models\Concerns\BelongsToTenant;
use App\Notifications\MatterReactivatedNotification;
use Database\Factories\InstalmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

// invoice_id is deliberately not listed — staff never attach an instalment to
// an invoice directly (Section 6/20): it's set by markSentToProvider() via
// Invoice::createDraftForInstalment(), or (rare correction) directly on the
// Invoice via relinkProvider().
#[Fillable([
    'payment_plan_id',
    'amount',
    'due_date',
    'paid_at',
    'status',
])]
class Instalment extends Model
{
    /** @use HasFactory<InstalmentFactory> */
    use BelongsToTenant, HasFactory, HasReasonedActivityLog, LogsActivity;

    /**
     * Non-persisted marker set by the updating hook below and read by the
     * updated hook that follows it — Eloquent doesn't have both original and
     * new values available in one event, so this is how a single save
     * decides, then acts on, "does this need flagging".
     */
    private bool $pendingOutOfSyncFlag = false;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('instalments')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * The updating/updated pair below is the *only* place an already-sent
     * instalment's edit gets flagged — deliberately does not fire for
     * markPaid() (which uses saveQuietly(), suppressing these events
     * entirely) since that's a legitimate, deliberate transition, not drift.
     * See Invoice::flagOutOfSync()'s own docblock for the full reasoning.
     */
    protected static function booted(): void
    {
        static::updating(function (Instalment $instalment): void {
            if ($instalment->getOriginal('invoice_id') === null) {
                // Either never attached to an invoice, or this save IS that
                // attachment itself — neither is an edit *after* sending.
                return;
            }

            if ($instalment->isDirty(['amount', 'due_date', 'status', 'paid_at'])) {
                $instalment->pendingOutOfSyncFlag = true;
            }
        });

        static::updated(function (Instalment $instalment): void {
            if ($instalment->pendingOutOfSyncFlag) {
                $instalment->pendingOutOfSyncFlag = false;
                $instalment->invoice?->flagOutOfSync(ReconciliationIssueReason::InstalmentEditedAfterSend, $instalment->getChanges());
            }
        });
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'due_date' => 'date',
            'paid_at' => 'datetime',
            'status' => InstalmentStatus::class,
        ];
    }

    protected function inheritsTenantIdFrom(): array
    {
        return ['paymentPlan'];
    }

    public function paymentPlan(): BelongsTo
    {
        return $this->belongsTo(PaymentPlan::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function chaseLogs(): HasMany
    {
        return $this->hasMany(ChaseLog::class);
    }

    /**
     * Days past due for an unpaid instalment — the single source of truth
     * for "how overdue", shared by the dashboard widget, the chase job's
     * tier thresholds, the matter finance tab, and display_status. Returns
     * 0 when paid, waived, or not yet due. An instalment due *today* is not
     * overdue (due_date is a date cast, so the whole due day is still
     * within terms) — matching the `due_date < today()` filters the widget
     * and chase job already query with.
     */
    public function daysOverdue(): int
    {
        if ($this->paid_at !== null || $this->status === InstalmentStatus::Waived) {
            return 0;
        }

        if ($this->due_date === null || $this->due_date->gte(today())) {
            return 0;
        }

        return (int) $this->due_date->diffInDays(today(), absolute: true);
    }

    public function getDisplayStatusAttribute(): InstalmentStatus
    {
        if ($this->status === InstalmentStatus::Pending && $this->daysOverdue() > 0) {
            return InstalmentStatus::Overdue;
        }

        return $this->status;
    }

    /**
     * Thin compatibility wrapper kept so "send to Xero" stays the one click
     * it always was (per Section 6's sign-off) even though there's now a
     * real Invoice underneath: creates a draft Invoice for this instalment if
     * one doesn't already exist, then marks *it* sent. forceFill()->save()
     * inside Invoice::createDraftForInstalment() still fires this model's own
     * updating/updated pair above — harmless, since getOriginal('invoice_id')
     * is null the first time (see that hook's own guard).
     */
    public function markSentToProvider(string $externalInvoiceId, ?string $externalInvoiceNumber = null): void
    {
        $invoice = $this->invoice ?? Invoice::createDraftForInstalment($this);

        $invoice->markSentToProvider($externalInvoiceId, $externalInvoiceNumber);

        $this->setRelation('invoice', $invoice->fresh());
    }

    /**
     * The manual-parity fix (Section 20): everything that used to happen
     * only inside ProcessXeroPayment's webhook handling — mark paid, cancel
     * any pending chase logs, reactivate a suspended matter — now lives here
     * so a firm with no accounting integration (or a director correcting a
     * payment by hand even with one) gets identical behaviour, not a lesser
     * version reachable only via a Xero webhook. saveQuietly() plus its own
     * distinct 'paid' event, same shape as CallNote::markReviewed().
     *
     * Cascades to invoice()->markPaid() (Section 6) — mutually idempotent
     * with that method, so whichever side is reached first (a manual click
     * here, or the accounting webhook finding the Invoice) produces the same
     * end state without looping.
     */
    public function markPaid(): void
    {
        if ($this->status === InstalmentStatus::Paid) {
            return;
        }

        $this->forceFill([
            'paid_at' => now(),
            'status' => InstalmentStatus::Paid,
        ])->saveQuietly();

        activity('instalments')
            ->performedOn($this)
            ->tap(function ($activity): void {
                $activity->tenant_id = $this->tenant_id;
            })
            ->event('paid')
            ->log('Instalment marked paid');

        // Chase logs are a historical audit trail (sent/failed) rather than
        // a schedule of future actions, so there is normally nothing
        // "pending" to cancel — this guards against any future design that
        // adds one.
        $this->chaseLogs()->where('status', 'pending')->update(['status' => ChaseLogStatus::Cancelled]);

        $this->reactivateMatterIfSuspended();

        $this->invoice?->markPaid();
    }

    /**
     * Thin compatibility wrapper delegating to the attached Invoice — see
     * markSentToProvider()'s docblock; kept so the "Relink invoice" UI action
     * doesn't need to know whether it's looking at an Instalment or Invoice.
     */
    public function relinkProvider(string $newExternalInvoiceId, User $user, string $reason): void
    {
        $this->invoice?->relinkProvider($newExternalInvoiceId, $user, $reason);
    }

    private function reactivateMatterIfSuspended(): void
    {
        $matter = $this->paymentPlan->matter;

        if ($matter->status !== MatterStatus::Suspended) {
            return;
        }

        $matter->update(['status' => MatterStatus::Active]);

        Notification::send(
            User::role('director')->get(),
            new MatterReactivatedNotification($matter, $this),
        );
    }
}
