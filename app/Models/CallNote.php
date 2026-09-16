<?php

namespace App\Models;

use App\Concerns\HasReasonedActivityLog;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Scopes\ExcludeConvertedLeadsScope;
use App\Notifications\CallNeedsReviewNotification;
use Database\Factories\CallNoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Throwable;

// reviewed_at/reviewed_by (Section 8, needs_review queue) are deliberately
// not listed — only markReviewed() sets them, via forceFill().
#[Fillable([
    'matter_id',
    'client_id',
    'lead_id',
    'call_id',
    'transcript',
    'summary',
    'needs_review',
    'review_reason',
])]
class CallNote extends Model
{
    /** @use HasFactory<CallNoteFactory> */
    use HasFactory, HasReasonedActivityLog, LogsActivity, BelongsToTenant;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('call_notes')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected function casts(): array
    {
        return [
            'needs_review' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * RetellWebhookController::processAnalyzedCall() only ever sets
     * needs_review at creation time (never flips an existing note from
     * false to true later), so a created hook is the one place this needs
     * to fire — not an updated one too.
     *
     * Deliberately swallows its own failures (e.g. no director role seeded
     * yet for this tenant) rather than letting them propagate — this fires
     * synchronously inside the same save the webhook controller depends on
     * completing; a notification problem must never be the reason a call
     * note fails to record at all.
     */
    protected static function booted(): void
    {
        static::created(function (CallNote $callNote): void {
            if (! $callNote->needs_review) {
                return;
            }

            try {
                Notification::send(User::role('director')->get(), new CallNeedsReviewNotification($callNote));
            } catch (Throwable $exception) {
                Log::error("Failed to send needs-review notification for CallNote#{$callNote->id}: {$exception->getMessage()}");
            }
        });
    }

    /**
     * Tries whichever of matter/client/lead is actually linked; falls back
     * to CurrentTenant (set by the webhook controller) when none are —
     * e.g. an unmatched inbound call with no resolvable record at all.
     */
    protected function inheritsTenantIdFrom(): array
    {
        return ['matter', 'client', 'lead'];
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class)->withoutGlobalScope(ExcludeConvertedLeadsScope::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Mirrors App\Models\Concerns\Archivable's archive()/restore() pattern —
     * saveQuietly() (so this doesn't also log as a generic 'updated' diff)
     * plus its own distinct 'reviewed' activity event, so "who reviewed this
     * and when" reads directly off the audit trail rather than needing to be
     * inferred from a needs_review true→false diff.
     */
    public function markReviewed(User $user): void
    {
        $this->forceFill([
            'reviewed_at' => now(),
            'reviewed_by' => $user->id,
        ])->saveQuietly();

        activity('call_notes')
            ->performedOn($this)
            ->causedBy($user)
            ->tap(function ($activity): void {
                $activity->tenant_id = $this->tenant_id;
            })
            ->event('reviewed')
            ->log('Call note reviewed');
    }
}
