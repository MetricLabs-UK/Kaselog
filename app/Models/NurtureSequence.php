<?php

namespace App\Models;

use App\Concerns\HasReasonedActivityLog;
use App\Enums\ChaseLogChannel;
use App\Enums\NurtureSequenceStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\NurtureSequenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'lead_id',
    'step',
    'channel',
    'scheduled_at',
    'sent_at',
    'status',
])]
class NurtureSequence extends Model
{
    /** @use HasFactory<NurtureSequenceFactory> */
    use HasFactory, HasReasonedActivityLog, LogsActivity, BelongsToTenant;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('nurture_sequences')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected function casts(): array
    {
        return [
            'channel' => ChaseLogChannel::class,
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'status' => NurtureSequenceStatus::class,
        ];
    }

    protected function inheritsTenantIdFrom(): array
    {
        return ['lead'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
