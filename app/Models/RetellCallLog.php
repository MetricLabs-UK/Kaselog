<?php

namespace App\Models;

use App\Concerns\HasReasonedActivityLog;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\RetellCallLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Arr;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Section 9 — one row per Retell webhook delivery (call_started, call_ended,
 * call_analyzed each create their own row sharing the same call_id), not one
 * row per phone call. RetellCallLogResource's list groups these down to one
 * representative row per call_id (the latest/most complete event) — see its
 * getEloquentQuery(). This is the raw capture; CallNote (matched via
 * call_id, not a real foreign key — no CallNote exists until call_analyzed
 * fires and something resolves) is the business record staff actually
 * triage. All the accessors below read from raw_payload rather than adding
 * real columns, since the shape comes entirely from Retell's own webhook
 * body — see RetellWebhookController for what's guaranteed to be present.
 */
#[Fillable([
    'call_id',
    'agent_id',
    'raw_payload',
    'event_type',
    'processed_at',
])]
class RetellCallLog extends Model
{
    /** @use HasFactory<RetellCallLogFactory> */
    use BelongsToTenant, HasFactory, HasReasonedActivityLog, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('retell_call_logs')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * Matched by call_id, not a real foreign key — a CallNote is only ever
     * created for the call_analyzed event, and only once RetellWebhookController
     * resolves (or fails to resolve) a matter/client/lead for it. Still
     * tenant-scoped via CallNote's own global scope, so this never resolves
     * a match across a tenant boundary even for an orphaned (tenant_id null)
     * log row — see the class docblock on why that can happen.
     */
    public function callNote(): HasOne
    {
        return $this->hasOne(CallNote::class, 'call_id', 'call_id');
    }

    /**
     * Every webhook delivery for the same real call (started/ended/analyzed),
     * including this row itself — shown on the detail page as the event
     * timeline for a call, since the list view only surfaces one
     * representative row per call_id.
     */
    public function siblingLogs(): HasMany
    {
        return $this->hasMany(self::class, 'call_id', 'call_id')->orderBy('id');
    }

    private function call(): array
    {
        return Arr::get($this->raw_payload, 'call', []);
    }

    private function analysis(): array
    {
        return Arr::get($this->call(), 'call_analysis', []);
    }

    /**
     * Phone calls carry from_number directly; web calls (this app's own
     * test/dev traffic, and a real fallback web widget if one's ever added)
     * don't, so the caller's self-reported number from analysis is the next
     * best thing. Null when genuinely nothing is available (e.g. before
     * call_analysis exists, or the caller withheld it).
     */
    public function callerPhoneNumber(): ?string
    {
        return Arr::get($this->call(), 'from_number')
            ?: Arr::get($this->analysis(), 'custom_analysis_data.phone_number')
            ?: null;
    }

    public function callerName(): ?string
    {
        return Arr::get($this->analysis(), 'custom_analysis_data.caller_name') ?: null;
    }

    public function callType(): ?string
    {
        return Arr::get($this->call(), 'call_type');
    }

    public function callStatus(): ?string
    {
        return Arr::get($this->call(), 'call_status');
    }

    /**
     * Only present from call_ended onward — a call_started row alone has no
     * duration yet.
     */
    public function durationSeconds(): ?int
    {
        $ms = Arr::get($this->call(), 'duration_ms');

        return $ms === null ? null : (int) round($ms / 1000);
    }

    public function recordingUrl(): ?string
    {
        return Arr::get($this->call(), 'recording_url');
    }

    public function transcript(): ?string
    {
        return Arr::get($this->call(), 'transcript');
    }

    public function callSummary(): ?string
    {
        return Arr::get($this->analysis(), 'call_summary');
    }

    public function userSentiment(): ?string
    {
        return Arr::get($this->analysis(), 'user_sentiment');
    }

    public function callSuccessful(): ?bool
    {
        return Arr::get($this->analysis(), 'call_successful');
    }

    public function disconnectionReason(): ?string
    {
        return Arr::get($this->call(), 'disconnection_reason');
    }
}
