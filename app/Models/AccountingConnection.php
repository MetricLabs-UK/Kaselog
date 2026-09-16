<?php

namespace App\Models;

use App\Enums\AccountingProviderKey;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A firm's accounting integration choice — see the creating migration's
 * docblock for why "manual" is a real row rather than the absence of one.
 *
 * Tenant-scoped like almost everything else in the Admin panel (unlike
 * Section 18's Hub-side billing tables): this is only ever read/written from
 * within a firm's own Admin panel, never cross-tenant from Hub. The one
 * place that matters: the OAuth callback and the daily token-refresh sweep
 * both run outside any ambient Filament tenant context, so both must call
 * CurrentTenant::set($tenant) themselves before touching this model — same
 * pattern ProcessPaymentChases already uses for its own cross-tenant sweep.
 *
 * No LogsActivity/HasReasonedActivityLog: access_token/refresh_token live
 * here, and Spatie's dirty-attribute diffing would put live credentials
 * straight into the audit trail's properties column. Lifecycle events
 * (connected/disconnected) are logged manually instead — see connect()/
 * disconnect() — the same explicit-event-only approach ImpersonationSession
 * uses for its own lifecycle.
 */
#[Fillable([
    'tenant_id',
    'provider',
    'access_token',
    'refresh_token',
    'token_expires_at',
    'external_org_id',
    'account_code',
    'settings',
    'connected_by',
    'connected_at',
    'disconnected_at',
])]
class AccountingConnection extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'provider' => AccountingProviderKey::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'settings' => 'array',
            'connected_at' => 'datetime',
            'disconnected_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    public function isRealProvider(): bool
    {
        return $this->provider->isRealProvider();
    }

    /**
     * Within ~5 minutes of expiry (or already past it) — the threshold
     * every real API call checks via
     * AccountingProviderContract::ensureFreshToken() before doing anything
     * else, plus what the daily scheduled refresh sweeps for.
     */
    public function tokenNeedsRefresh(): bool
    {
        return $this->token_expires_at !== null
            && now()->addMinutes(5)->greaterThanOrEqualTo($this->token_expires_at);
    }

    /**
     * Explicit-tenant lookups (OAuth callback, the daily refresh sweep, the
     * webhook job resolving a tenant from an event's org id) must never
     * depend on CurrentTenant already being set to the right tenant —
     * allTenants() bypasses the scope, and the tenant_id in the query/data
     * below is what actually keeps them correct.
     */
    public static function forTenant(Tenant $tenant): ?self
    {
        return static::allTenants()->where('tenant_id', $tenant->id)->first();
    }

    public static function forExternalOrgId(AccountingProviderKey $provider, string $externalOrgId): ?self
    {
        return static::allTenants()
            ->where('provider', $provider)
            ->where('external_org_id', $externalOrgId)
            ->first();
    }

    public static function connectManual(Tenant $tenant, User $connectedBy): self
    {
        return static::allTenants()->updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'provider' => AccountingProviderKey::Manual,
                'access_token' => null,
                'refresh_token' => null,
                'token_expires_at' => null,
                'external_org_id' => null,
                'account_code' => null,
                'connected_by' => $connectedBy->id,
                'connected_at' => now(),
                'disconnected_at' => null,
            ],
        );
    }
}
