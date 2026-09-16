<?php

namespace App\Models;

use App\Enums\BackupDestinationProvider;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 3+ of the firm-facing self-service backup — a firm's own SharePoint/
 * Google Drive connection, authorized via delegated per-tenant OAuth (each
 * firm connects their own Microsoft/Google account), not the app-only
 * client-credentials auth Section 12's Kase-internal 'sharepoint' disk uses.
 *
 * Unlike AccountingConnection (one active provider per tenant), a firm can
 * hold a SharePoint AND a Google Drive connection at once — see the unique
 * [tenant_id, provider] index on the migration.
 *
 * No LogsActivity — access_token/refresh_token live here, same reasoning as
 * AccountingConnection.
 */
#[Fillable([
    'tenant_id',
    'provider',
    'site_id',
    'site_name',
    'drive_id',
    'access_token',
    'refresh_token',
    'token_expires_at',
    'connected_by',
    'connected_at',
    'disconnected_at',
])]
class BackupDestinationConnection extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'provider' => BackupDestinationProvider::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
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

    public function isConnected(): bool
    {
        return $this->access_token !== null && $this->disconnected_at === null;
    }

    /**
     * A connection can be OAuth-connected but still awaiting site selection
     * — see SharePointBackupProvider::selectSite(), the only place drive_id
     * gets set. uploadFile() refuses to run without it.
     */
    public function hasSiteSelected(): bool
    {
        return $this->isConnected() && $this->drive_id !== null;
    }

    /**
     * Within ~5 minutes of expiry (or already past it) — same threshold as
     * AccountingConnection::tokenNeedsRefresh().
     */
    public function tokenNeedsRefresh(): bool
    {
        return $this->token_expires_at !== null
            && now()->addMinutes(5)->greaterThanOrEqualTo($this->token_expires_at);
    }

    /**
     * Explicit-tenant lookups (OAuth callback, the daily refresh sweep, the
     * daily push job) must never depend on CurrentTenant already being set
     * to the right tenant — same reasoning as AccountingConnection::
     * forTenant().
     */
    public static function forTenantAndProvider(Tenant $tenant, BackupDestinationProvider $provider): ?self
    {
        return static::allTenants()
            ->where('tenant_id', $tenant->id)
            ->where('provider', $provider)
            ->first();
    }

    public function disconnect(): void
    {
        $this->forceFill([
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
            'site_id' => null,
            'site_name' => null,
            'drive_id' => null,
            'disconnected_at' => now(),
        ])->save();
    }
}
