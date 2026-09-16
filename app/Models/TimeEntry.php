<?php

namespace App\Models;

use App\Concerns\HasReasonedActivityLog;
use App\Enums\ReconciliationIssueReason;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\TimeEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

// invoice_id is deliberately not listed (Section 6) — staff never type an
// invoice reference directly any more; it's set only by
// Invoice::createDraftForTimeEntries() (the "Bundle into invoice" bulk
// action), via a query-builder update() that bypasses mass assignment
// entirely. locked stays fillable — it's still also a manual director
// toggle independent of invoicing, unchanged from before Section 6.
#[Fillable([
    'matter_id',
    'client_id',
    'user_id',
    'start_time',
    'end_time',
    'duration_seconds',
    'description',
    'activity_type',
    'billable',
    'billing_rate',
    'billed_amount',
    'locked',
])]
class TimeEntry extends Model
{
    /** @use HasFactory<TimeEntryFactory> */
    use BelongsToTenant, HasFactory, HasReasonedActivityLog, LogsActivity;

    /**
     * Same non-persisted single-save marker Instalment::booted() uses — see
     * that class's own docblock for why.
     */
    private bool $pendingOutOfSyncFlag = false;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('time_entries')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected function inheritsTenantIdFrom(): array
    {
        return ['matter'];
    }

    protected static function booted(): void
    {
        static::creating(function (TimeEntry $timeEntry): void {
            if (blank($timeEntry->client_id) && $timeEntry->matter_id) {
                $timeEntry->client_id = Matter::find($timeEntry->matter_id)?->client_id;
            }

            if (blank($timeEntry->user_id) && auth()->check()) {
                $timeEntry->user_id = auth()->id();
            }
        });

        static::saving(function (TimeEntry $timeEntry): void {
            if ($timeEntry->billable && filled($timeEntry->billing_rate)) {
                $timeEntry->billed_amount = round(($timeEntry->duration_seconds / 3600) * (float) $timeEntry->billing_rate, 2);
            } else {
                $timeEntry->billed_amount = null;
            }
        });

        /**
         * Mirrors Instalment's identical updating/updated pair: a time entry
         * only ever gets invoice_id set via the bulk bundle action's plain
         * query-builder update() (which fires no model events at all), so by
         * the time an Eloquent save() sees invoice_id already set, it can
         * only mean this row is being edited *after* being bundled — a
         * manage_locked_records override, since canEdit() otherwise blocks
         * it. Whether that bundle has actually been sent to the provider yet
         * doesn't change anything here: editing a row already promised to a
         * specific invoice is exactly the drift this queue exists to catch,
         * even for a still-draft invoice.
         */
        static::updating(function (TimeEntry $timeEntry): void {
            if ($timeEntry->getOriginal('invoice_id') === null) {
                return;
            }

            if ($timeEntry->isDirty(['duration_seconds', 'billing_rate', 'billable', 'description'])) {
                $timeEntry->pendingOutOfSyncFlag = true;
            }
        });

        static::updated(function (TimeEntry $timeEntry): void {
            if ($timeEntry->pendingOutOfSyncFlag) {
                $timeEntry->pendingOutOfSyncFlag = false;
                $timeEntry->invoice?->flagOutOfSync(ReconciliationIssueReason::TimeEntryEditedAfterSend, $timeEntry->getChanges());
            }
        });
    }

    public static function formatDuration(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return "{$hours}h {$minutes}m";
    }

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'billable' => 'boolean',
            'billing_rate' => 'decimal:2',
            'billed_amount' => 'decimal:2',
            'locked' => 'boolean',
        ];
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function getFormattedDurationAttribute(): string
    {
        return static::formatDuration((int) $this->duration_seconds);
    }
}
