<?php

namespace App\Models;

use App\Concerns\HasReasonedActivityLog;
use App\Enums\DocumentAiSummaryStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Notifications\DocumentFieldReviewNotification;
use Database\Factories\DocumentAiSummaryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Throwable;

// reviewed_at/reviewed_by are deliberately not listed — only markReviewed()
// sets them, via forceFill() — mirrors CallNote/AccountingReconciliationIssue
// exactly.
#[Fillable([
    'matter_document_id',
    'status',
    'summary',
    'key_facts',
    'extracted_fields',
    'field_comparisons',
    'needs_review',
    'review_reason',
    'error_message',
    'processed_at',
])]
class DocumentAiSummary extends Model
{
    /** @use HasFactory<DocumentAiSummaryFactory> */
    use BelongsToTenant, HasFactory, HasReasonedActivityLog, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('document_ai_summaries')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Fires when a save transitions needs_review from false to true — that
     * only ever happens once, from SummarizeMatterDocument's own completion
     * update (a fresh Pending row is always needs_review=false), so this
     * can't double-notify on a later, unrelated update. Mirrors CallNote's
     * identical created-hook shape, just on an update instead of a create,
     * since this row already exists (Pending) by the time the comparison
     * outcome is known.
     */
    protected static function booted(): void
    {
        static::updated(function (DocumentAiSummary $summary): void {
            if (! $summary->wasChanged('needs_review') || ! $summary->needs_review) {
                return;
            }

            try {
                $matter = $summary->matterDocument->matter;

                Notification::send(
                    $matter->notifiableStaffUsers(),
                    new DocumentFieldReviewNotification($summary),
                );
            } catch (Throwable $exception) {
                Log::error("Failed to send document-field-review notification for DocumentAiSummary#{$summary->id}: {$exception->getMessage()}");
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => DocumentAiSummaryStatus::class,
            'key_facts' => 'array',
            'extracted_fields' => 'array',
            'field_comparisons' => 'array',
            'needs_review' => 'boolean',
            'processed_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    protected function inheritsTenantIdFrom(): array
    {
        return ['matterDocument'];
    }

    public function matterDocument(): BelongsTo
    {
        return $this->belongsTo(MatterDocument::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Mirrors CallNote::markReviewed()/AccountingReconciliationIssue::
     * markReviewed() exactly — this dismisses the flag once a human has
     * looked at the comparison, it never writes the extracted value into
     * the CRM record itself. Applying an extracted value to the actual
     * Client/Matter field is a deliberate, separate action a human takes
     * elsewhere (in the Client/Matter edit form) if they agree with it.
     */
    public function markReviewed(User $user): void
    {
        $this->forceFill([
            'reviewed_at' => now(),
            'reviewed_by' => $user->id,
        ])->saveQuietly();

        activity('document_ai_summaries')
            ->performedOn($this)
            ->causedBy($user)
            ->tap(function ($activity): void {
                $activity->tenant_id = $this->tenant_id;
            })
            ->event('reviewed')
            ->log('Document field comparison reviewed');
    }
}
