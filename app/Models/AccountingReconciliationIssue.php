<?php

namespace App\Models;

use App\Enums\AccountingProviderKey;
use App\Enums\ReconciliationIssueReason;
use App\Models\Concerns\BelongsToTenant;
use App\Notifications\AccountingReconciliationIssueNotification;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

// reviewed_at/reviewed_by are deliberately not listed — only markReviewed()
// sets them, via forceFill() — mirrors CallNote exactly.
//
// Tenant-scoped like AccountingConnection — a genuinely unattributable issue
// (tenant_id null, e.g. a webhook whose org id matches no connection) simply
// won't surface in any firm's queue, an accepted rare edge case rather than
// building a separate cross-tenant view for it.
#[Fillable([
    'tenant_id',
    'provider',
    'external_invoice_id',
    'invoice_id',
    'reason',
    'payload',
    'needs_review',
])]
class AccountingReconciliationIssue extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'provider' => AccountingProviderKey::class,
            'reason' => ReconciliationIssueReason::class,
            'payload' => 'array',
            'needs_review' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * Mirrors CallNote::booted() exactly: fires the moment a genuinely
     * unresolved issue is created, swallowing its own failure rather than
     * letting a notification problem take down the webhook/edit path this
     * runs inside.
     *
     * User::role('director') is itself tenant-scoped — spatie/laravel-
     * permission's team mode filters role() by the ambient permissions team
     * id, which CurrentTenant::set() keeps in sync (see
     * ProcessPaymentChases/ProcessXeroPayment doing the identical
     * User::role('director')->get() with no explicit tenant filter). Every
     * caller that creates a row here must have already called
     * CurrentTenant::set() to the right tenant first.
     */
    protected static function booted(): void
    {
        static::created(function (AccountingReconciliationIssue $issue): void {
            if (! $issue->needs_review) {
                return;
            }

            try {
                $directors = User::role('director')->get();

                if ($directors->isEmpty()) {
                    Log::warning("AccountingReconciliationIssue#{$issue->id}: no director to notify (tenant_id=".($issue->tenant_id ?? 'null').').');

                    return;
                }

                Notification::send($directors, new AccountingReconciliationIssueNotification($issue));
            } catch (Throwable $exception) {
                Log::error("Failed to send reconciliation-issue notification for AccountingReconciliationIssue#{$issue->id}: {$exception->getMessage()}");
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Mirrors CallNote::markReviewed() exactly — saveQuietly() plus its own
     * distinct 'reviewed' activity event, so "who reviewed this and when"
     * reads directly off the audit trail.
     */
    public function markReviewed(User $user): void
    {
        $this->forceFill([
            'reviewed_at' => now(),
            'reviewed_by' => $user->id,
        ])->saveQuietly();

        activity('accounting')
            ->performedOn($this)
            ->causedBy($user)
            ->tap(function ($activity): void {
                $activity->tenant_id = $this->tenant_id;
            })
            ->event('reconciliation_issue_reviewed')
            ->log('Accounting reconciliation issue reviewed');

        if (in_array($this->reason, [
            ReconciliationIssueReason::InstalmentEditedAfterSend,
            ReconciliationIssueReason::PaymentPlanEditedAfterSend,
            ReconciliationIssueReason::TimeEntryEditedAfterSend,
        ], true) && $this->invoice !== null) {
            $this->invoice->forceFill(['out_of_sync_with_provider' => false])->saveQuietly();
        }
    }
}
