<?php

namespace App\Models;

use App\Concerns\HasReasonedActivityLog;
use App\Enums\ImpersonationStatus;
use App\Events\ImpersonationRequested;
use App\Events\ImpersonationResponded;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Section 19 — Hub-director-initiated user impersonation, gated on real,
 * on-screen consent from the target user. Deliberately does NOT use
 * BelongsToTenant/TenantScope: a director works this from the Hub, which has
 * no ambient tenant context (HubAccess::TEAM_ID, not a real tenant), so a
 * tenant-filtering global scope would hide every row. tenant_id is still
 * stored (the firm being supported) and stamped onto every activity log
 * entry manually, same as every other tenant-scoped audit row.
 *
 * Every field past the four set at request time is system-controlled via
 * the methods below (forceFill + a distinct activity event each — see
 * CallNote::markReviewed() for the established pattern), never mass-assigned
 * directly.
 */
#[Fillable([
    'tenant_id',
    'target_user_id',
    'requested_by',
    'reason',
])]
class ImpersonationSession extends Model
{
    use HasFactory, HasReasonedActivityLog, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('impersonation_sessions')
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected function casts(): array
    {
        return [
            'status' => ImpersonationStatus::class,
            'requested_at' => 'datetime',
            'request_expires_at' => 'datetime',
            'responded_at' => 'datetime',
            'session_started_at' => 'datetime',
            'session_expires_at' => 'datetime',
            'session_ended_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * $requestTtlMinutes bounds how long the request sits pending before
     * it's treated as stale (see ExpireStaleImpersonationRequests) — not the
     * session duration itself, which isn't known until accept() is called.
     */
    public static function requestFor(User $director, User $target, Tenant $tenant, string $reason, int $requestTtlMinutes = 60): self
    {
        $session = new self;
        $session->forceFill([
            'tenant_id' => $tenant->id,
            'target_user_id' => $target->id,
            'requested_by' => $director->id,
            'reason' => $reason,
            'status' => ImpersonationStatus::Pending,
            'requested_at' => now(),
            'request_expires_at' => now()->addMinutes($requestTtlMinutes),
        ])->save();

        activity('impersonation_sessions')
            ->performedOn($session)
            ->causedBy($director)
            ->tap(fn ($activity) => $activity->tenant_id = $tenant->id)
            ->event('requested')
            ->withProperties(['target_user_id' => $target->id, 'reason' => $reason])
            ->log("Impersonation requested for {$target->email}");

        ImpersonationRequested::dispatch($session);

        return $session;
    }

    public function decline(): void
    {
        $this->forceFill([
            'status' => ImpersonationStatus::Declined,
            'responded_at' => now(),
        ])->saveQuietly();

        activity('impersonation_sessions')
            ->performedOn($this)
            ->causedBy($this->targetUser)
            ->tap(fn ($activity) => $activity->tenant_id = $this->tenant_id)
            ->event('declined')
            ->log('Impersonation request declined');

        ImpersonationResponded::dispatch($this);
    }

    /**
     * Consent granted — but the session clock doesn't start here. If it
     * did, the minutes between the target clicking Accept and the director
     * actually noticing and entering would silently eat into their session
     * time. activate() (called at the moment the director actually enters)
     * is where session_started_at/session_expires_at get set.
     */
    public function accept(): void
    {
        $this->forceFill([
            'status' => ImpersonationStatus::Accepted,
            'responded_at' => now(),
        ])->saveQuietly();

        activity('impersonation_sessions')
            ->performedOn($this)
            ->causedBy($this->targetUser)
            ->tap(fn ($activity) => $activity->tenant_id = $this->tenant_id)
            ->event('accepted')
            ->log('Impersonation request accepted');

        ImpersonationResponded::dispatch($this);
    }

    /**
     * The director has actually entered the session — called immediately
     * around the real auth switch (see EnterImpersonationSession).
     */
    public function activate(int $sessionDurationMinutes = 30): void
    {
        $this->forceFill([
            'status' => ImpersonationStatus::Active,
            'session_started_at' => now(),
            'session_expires_at' => now()->addMinutes($sessionDurationMinutes),
        ])->saveQuietly();

        activity('impersonation_sessions')
            ->performedOn($this)
            ->causedBy($this->requestedBy)
            ->tap(fn ($activity) => $activity->tenant_id = $this->tenant_id)
            ->event('started')
            ->withProperties(['session_expires_at' => $this->session_expires_at])
            ->log('Impersonation session started');
    }

    public function expireRequest(): void
    {
        $this->forceFill(['status' => ImpersonationStatus::Expired])->saveQuietly();

        activity('impersonation_sessions')
            ->performedOn($this)
            ->tap(fn ($activity) => $activity->tenant_id = $this->tenant_id)
            ->event('request_expired')
            ->log('Impersonation request expired unanswered');
    }

    /**
     * $endedBy is null for an auto-expiry (no human caused it at that
     * instant) — 'director_ended' | 'target_ended' | 'expired'.
     */
    public function end(?User $endedBy, string $reason): void
    {
        $this->forceFill([
            'status' => ImpersonationStatus::Ended,
            'session_ended_at' => now(),
            'ended_reason' => $reason,
        ])->saveQuietly();

        $durationMinutes = $this->session_started_at?->diffInMinutes($this->session_ended_at) ?? 0;

        activity('impersonation_sessions')
            ->performedOn($this)
            ->causedBy($endedBy)
            ->tap(fn ($activity) => $activity->tenant_id = $this->tenant_id)
            ->event('ended')
            ->withProperties(['reason' => $reason, 'duration_minutes' => $durationMinutes])
            ->log("Impersonation session ended ({$reason}), duration {$durationMinutes}m");
    }

    public function isRequestExpired(): bool
    {
        return $this->status === ImpersonationStatus::Pending
            && $this->request_expires_at->isPast();
    }

    /**
     * Consent was granted but the director never actually entered — a
     * separate, shorter window than the original request, so an accepted
     * grant doesn't stay redeemable indefinitely.
     */
    public function isAcceptedWindowExpired(int $windowMinutes = 10): bool
    {
        return $this->status === ImpersonationStatus::Accepted
            && $this->responded_at->addMinutes($windowMinutes)->isPast();
    }

    public function isSessionExpired(): bool
    {
        return $this->status === ImpersonationStatus::Active
            && $this->session_expires_at->isPast();
    }
}
