<?php

namespace App\Support\Tenancy;

use App\Models\Tenant;
use Filament\Facades\Filament;
use Spatie\Permission\PermissionRegistrar;

/**
 * Holds the active tenant for the current request/console process. An
 * explicit set() always wins; otherwise, inside a Filament panel request,
 * falls back to Filament::getTenant() (which is simply null outside a panel
 * context — safe to call from console/jobs). Scheduled jobs and webhooks
 * set()/clear() explicitly around the slice of work for one tenant.
 *
 * This is also the single choke point that keeps spatie/laravel-permission's
 * team context (PermissionRegistrar::setPermissionsTeamId()) in lockstep with
 * tenant resolution — every set()/get()/clear() call re-syncs it, so no
 * middleware or per-job wiring is needed for role/permission checks to be
 * tenant-scoped correctly in console/queue/webhook contexts.
 *
 * Note: a User/Role instance's `roles` relation is cached on first access and
 * stays scoped to whichever team was active at that point — don't reuse a
 * model instance across a set() team switch without re-fetching it.
 */
class CurrentTenant
{
    protected static ?Tenant $tenant = null;

    public static function set(?Tenant $tenant): void
    {
        static::$tenant = $tenant;
        static::syncPermissionsTeamId($tenant?->id);
    }

    public static function get(): ?Tenant
    {
        $tenant = static::$tenant;

        if ($tenant === null) {
            $resolved = Filament::getTenant();
            $tenant = $resolved instanceof Tenant ? $resolved : null;
        }

        static::syncPermissionsTeamId($tenant?->id);

        return $tenant;
    }

    public static function id(): ?int
    {
        return static::get()?->id;
    }

    public static function clear(): void
    {
        static::$tenant = null;
        static::syncPermissionsTeamId(null);
    }

    private static function syncPermissionsTeamId(?int $tenantId): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);
    }
}
