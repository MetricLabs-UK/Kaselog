<?php

namespace App\Filament\Portal\Concerns;

use App\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Filament\Facades\Filament;

/**
 * For portal routes reached before login (set-password, login itself) —
 * Filament's own tenant resolution (IdentifyTenant middleware) requires an
 * authenticated HasTenants user, so it can't run for these. The {tenant}
 * route segment arrives here as the raw slug string; this resolves it the
 * same way IdentifyTenant would once a session exists, so the rest of the
 * request (tenant-scoped queries, panel branding) behaves identically
 * either way.
 */
trait ResolvesGuestTenant
{
    protected function resolveGuestTenant(string $tenant): Tenant
    {
        $tenantModel = Tenant::where('slug', $tenant)->firstOrFail();

        CurrentTenant::set($tenantModel);
        Filament::setTenant($tenantModel, isQuiet: true);

        return $tenantModel;
    }
}
