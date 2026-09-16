<?php

namespace App\Models;

use App\Enums\AccountingProviderKey;
use App\Enums\InvoiceStatus;
use App\Enums\ReconciliationIssueReason;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Section 6 — the one thing both billing paths in this app eventually become
 * on their way to the firm's accounting provider: a fixed-fee PaymentPlan's
 * Instalment (one invoice per instalment, created and sent in a single
 * click) and a bundle of hourly TimeEntry rows (one invoice per bundle,
 * created as a draft for review, sent separately) are genuinely different
 * pricing models — see the Section 6 scoping notes for why — but neither
 * needs its own copy of "talk to Xero", so both attach here instead.
 *
 * Nothing is mass-fillable: every field is set by a factory method below
 * (createDraftForInstalment/createDraftForTimeEntries) or a lifecycle method
 * (markSentToProvider/markPaid/relinkProvider/flagOutOfSync), mirroring
 * AccountingConnection and Instalment's own locked-down style — there is no
 * user-facing create/edit form for an Invoice.
 */
#[Fillable([])]
class Invoice extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'provider' => AccountingProviderKey::class,
            'total_amount' => 'decimal:2',
            'out_of_sync_with_provider' => 'boolean',
            'sent_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function instalments(): HasMany
    {
        return $this->hasMany(Instalment::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function reconciliationIssues(): HasMany
    {
        return $this->hasMany(AccountingReconciliationIssue::class);
    }

    /**
     * A single-instalment draft, created immediately before "Send to Xero"
     * sends it — see Instalment::markSentToProvider()'s docblock for why the
     * two happen back-to-back rather than as separate staff-visible steps.
     */
    public static function createDraftForInstalment(Instalment $instalment): self
    {
        $matter = $instalment->paymentPlan->matter;

        $invoice = (new self)->forceFill([
            'tenant_id' => $instalment->tenant_id,
            'client_id' => $matter->client_id,
            'matter_id' => $matter->id,
            'status' => InvoiceStatus::Draft,
            'total_amount' => $instalment->amount,
        ]);
        $invoice->save();

        $instalment->forceFill(['invoice_id' => $invoice->id])->save();

        return $invoice;
    }

    /**
     * The "Bundle into invoice" bulk action's underlying logic — every
     * caller-side check (same matter, same client, billable, not already
     * invoiced) is the UI's job to enforce with a friendly message; this
     * still guards defensively since attaching a mixed-matter bundle would
     * silently corrupt matter_id/client_id below.
     *
     * A plain query-builder update() (not each entry's own save()) attaches
     * the entries — deliberately: TimeEntry's own updating/updated
     * drift-flagging pair (mirroring Instalment's) must not fire for this
     * initial attachment, only for a later edit to an already-invoiced row,
     * exactly as Instalment's equivalent forceFill()->save() call does via
     * its getOriginal('invoice_id') guard. A bulk builder update never fires
     * model events at all, so that guard isn't even needed here.
     */
    public static function createDraftForTimeEntries(Collection $timeEntries, User $createdBy): self
    {
        if ($timeEntries->isEmpty()) {
            throw new InvalidArgumentException('Cannot bundle an empty set of time entries into an invoice.');
        }

        $matterIds = $timeEntries->pluck('matter_id')->unique();

        if ($matterIds->count() > 1) {
            throw new InvalidArgumentException('All selected time entries must belong to the same matter.');
        }

        $first = $timeEntries->first();

        $invoice = (new self)->forceFill([
            'tenant_id' => $first->tenant_id,
            'client_id' => $first->client_id,
            'matter_id' => $first->matter_id,
            'status' => InvoiceStatus::Draft,
            'total_amount' => $timeEntries->sum('billed_amount'),
            'created_by' => $createdBy->id,
        ]);
        $invoice->save();

        TimeEntry::query()
            ->whereIn('id', $timeEntries->pluck('id'))
            ->update(['invoice_id' => $invoice->id, 'locked' => true]);

        return $invoice;
    }

    /**
     * Normalized line items for AccountingProviderContract::createInvoice()
     * to turn into whatever the provider's own SDK needs — keeps that
     * interface (and its Xero implementation) ignorant of Instalment/
     * TimeEntry entirely, only ever seeing an Invoice.
     *
     * @return list<array{description: string, quantity: float, unitAmount: float}>
     */
    public function lineItemsData(): array
    {
        if ($this->instalments->isNotEmpty()) {
            return $this->instalments->map(fn (Instalment $instalment): array => [
                'description' => "Matter {$this->matter->reference} — instalment due {$instalment->due_date->format('d/m/Y')}",
                'quantity' => 1.0,
                'unitAmount' => (float) $instalment->amount,
            ])->all();
        }

        return $this->timeEntries->map(fn (TimeEntry $timeEntry): array => [
            'description' => sprintf(
                '%s — %s: %s',
                $timeEntry->created_at->format('d/m/Y'),
                $timeEntry->activity_type ?? 'Time',
                Str::limit($timeEntry->description, 100),
            ),
            'quantity' => 1.0,
            'unitAmount' => (float) $timeEntry->billed_amount,
        ])->all();
    }

    /**
     * forceFill()->save() rather than update(): nothing here is mass-fillable
     * (see the class docblock), but this should still fire normal model
     * events — a first-time send is not exempt from any future drift-
     * detection added at the Invoice level itself (there is none today; the
     * drift this app tracks is on the Instalment/TimeEntry rows feeding an
     * already-sent invoice, not the invoice row itself).
     */
    public function markSentToProvider(string $externalInvoiceId, ?string $externalInvoiceNumber = null): void
    {
        $this->forceFill([
            'status' => InvoiceStatus::Sent,
            'provider' => AccountingConnection::forTenant($this->tenant)?->provider ?? AccountingProviderKey::Manual,
            'provider_invoice_id' => $externalInvoiceId,
            'provider_invoice_number' => $externalInvoiceNumber,
            'sent_at' => now(),
        ])->save();
    }

    /**
     * Idempotent and mutually recursive with Instalment::markPaid(): whichever
     * side is called first flips its own state then calls the other, which
     * sees itself already there and returns immediately — see that method's
     * docblock. TimeEntry rows have no per-row "paid" concept of their own,
     * so there is nothing further to cascade to for a time-entry invoice.
     */
    public function markPaid(): void
    {
        if ($this->status === InvoiceStatus::Paid) {
            return;
        }

        $this->forceFill(['status' => InvoiceStatus::Paid, 'paid_at' => now()])->saveQuietly();

        $this->instalments->each(fn (Instalment $instalment) => $instalment->markPaid());
    }

    /**
     * The rare manual-correction path — moved here from Instalment (Section
     * 20) since the invoice, not any one instalment, is what actually has a
     * provider-side id now. Same saveQuietly() + distinct activity event.
     */
    public function relinkProvider(string $newExternalInvoiceId, User $user, string $reason): void
    {
        $oldExternalInvoiceId = $this->provider_invoice_id;

        $this->forceFill(['provider_invoice_id' => $newExternalInvoiceId])->saveQuietly();

        activity('invoices')
            ->performedOn($this)
            ->causedBy($user)
            ->withProperties(['old' => $oldExternalInvoiceId, 'new' => $newExternalInvoiceId, 'reason' => $reason])
            ->tap(function ($activity): void {
                $activity->tenant_id = $this->tenant_id;
            })
            ->event('provider_invoice_relinked')
            ->log("Provider invoice relinked from \"{$oldExternalInvoiceId}\" to \"{$newExternalInvoiceId}\"");
    }

    /**
     * Moved here from Instalment (Section 20) — records that this invoice (or
     * one of the Instalment/TimeEntry rows feeding it) was edited after
     * already being sent to the provider. Idempotent on the flag itself, but
     * always creates a fresh AccountingReconciliationIssue row.
     *
     * $sourceChanges is the actual dirty diff from whichever Instalment/
     * TimeEntry row triggered this — $this->getChanges() would only show the
     * out_of_sync_with_provider flip happening a few lines below, not what
     * was actually edited on the source record.
     */
    public function flagOutOfSync(ReconciliationIssueReason $reason, array $sourceChanges = []): void
    {
        if (! $this->out_of_sync_with_provider) {
            $this->forceFill(['out_of_sync_with_provider' => true])->saveQuietly();
        }

        $connection = AccountingConnection::forTenant($this->tenant);

        AccountingReconciliationIssue::create([
            'tenant_id' => $this->tenant_id,
            'provider' => $connection?->provider ?? AccountingProviderKey::Manual,
            'external_invoice_id' => $this->provider_invoice_id,
            'invoice_id' => $this->id,
            'reason' => $reason,
            'needs_review' => true,
            'payload' => ['dirty' => $sourceChanges],
        ]);

        activity('invoices')
            ->performedOn($this)
            ->tap(function ($activity): void {
                $activity->tenant_id = $this->tenant_id;
            })
            ->event('flagged_out_of_sync')
            ->log('Invoice flagged as possibly out of sync with the accounting provider');
    }
}
