<?php

namespace App\Models;

use App\Concerns\HasReasonedActivityLog;
use App\Enums\ChaseLogChannel;
use App\Enums\ChaseLogStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ChaseLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'instalment_id',
    'channel',
    'template',
    'sent_at',
    'status',
])]
class ChaseLog extends Model
{
    /** @use HasFactory<ChaseLogFactory> */
    use HasFactory, HasReasonedActivityLog, LogsActivity, BelongsToTenant;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('chase_logs')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected function casts(): array
    {
        return [
            'channel' => ChaseLogChannel::class,
            'sent_at' => 'datetime',
            'status' => ChaseLogStatus::class,
        ];
    }

    protected function inheritsTenantIdFrom(): array
    {
        return ['instalment'];
    }

    public function instalment(): BelongsTo
    {
        return $this->belongsTo(Instalment::class);
    }
}
