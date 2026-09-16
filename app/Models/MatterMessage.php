<?php

namespace App\Models;

use App\Concerns\HasReasonedActivityLog;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\MatterMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'matter_id',
    'from_type',
    'from_id',
    'body',
    'read_at',
    'visible_to_client',
])]
class MatterMessage extends Model
{
    /** @use HasFactory<MatterMessageFactory> */
    use HasFactory, HasReasonedActivityLog, LogsActivity, BelongsToTenant;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('matter_messages')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'visible_to_client' => 'boolean',
        ];
    }

    protected function inheritsTenantIdFrom(): array
    {
        return ['matter'];
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function getFromLabelAttribute(): string
    {
        return match ($this->from_type) {
            'user' => User::find($this->from_id)?->name ?? 'Unknown user',
            'client' => Client::find($this->from_id)?->full_name ?? 'Unknown client',
            default => 'Unknown',
        };
    }
}
