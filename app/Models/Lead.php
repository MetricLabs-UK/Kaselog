<?php

namespace App\Models;

use App\Concerns\HasReasonedActivityLog;
use App\Enums\ClientSource;
use App\Enums\LeadStatus;
use App\Enums\NurtureSequenceStatus;
use App\Models\Concerns\Archivable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Scopes\ExcludeConvertedLeadsScope;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'prefix',
    'first_name',
    'last_name',
    'email',
    'telephone',
    'mobile',
    'source',
    'campaign_source',
    'gclid',
    'practice_area',
    'message',
    'status',
    'assigned_user_id',
    'nurture_stage',
    'chase_date',
    'last_contacted_at',
    'converted_at',
    'converted_to_client_id',
    'locked',
    'director_only',
])]
class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory, HasReasonedActivityLog, LogsActivity, Archivable, BelongsToTenant;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('leads')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new ExcludeConvertedLeadsScope);
    }

    protected function casts(): array
    {
        return [
            'source' => ClientSource::class,
            'status' => LeadStatus::class,
            'chase_date' => 'date',
            'last_contacted_at' => 'datetime',
            'converted_at' => 'datetime',
            'locked' => 'boolean',
            'director_only' => 'boolean',
        ];
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function matters(): HasMany
    {
        return $this->hasMany(Matter::class);
    }

    public function nurtureSequences(): HasMany
    {
        return $this->hasMany(NurtureSequence::class);
    }

    public function callNotes(): HasMany
    {
        return $this->hasMany(CallNote::class);
    }

    public function convertedClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'converted_to_client_id');
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->prefix} {$this->first_name} {$this->last_name}");
    }

    public function convertToClient(): Client
    {
        $client = new Client([
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->telephone ?: $this->mobile,
            'source' => $this->source,
        ]);

        // Carry the lead's own tenant across explicitly rather than relying
        // on ambient CurrentTenant — this can run from a job/console context
        // where no panel tenant is active.
        $client->tenant_id = $this->tenant_id;
        $client->save();

        $this->forceFill([
            'converted_to_client_id' => $client->id,
            'converted_at' => now(),
            'status' => LeadStatus::Converted,
        ])->save();

        $this->nurtureSequences()
            ->where('status', NurtureSequenceStatus::Pending)
            ->update(['status' => NurtureSequenceStatus::Cancelled]);

        return $client;
    }

    public function cancelPendingNurtureSequences(): void
    {
        $this->nurtureSequences()
            ->where('status', NurtureSequenceStatus::Pending)
            ->update(['status' => NurtureSequenceStatus::Cancelled]);
    }
}
