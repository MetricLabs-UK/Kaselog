<?php

namespace App\Http\Middleware;

use App\Support\Hub\HubAccess;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Hub panel has no ->tenant() call, so nothing else primes spatie's
 * ambient permissions team id the way TenantScope does incidentally for
 * every tenant-scoped query in the admin panel (see CurrentTenant's
 * docblock). Without this, every `->can('hub_...')` / `->hasRole()` check in
 * a Hub request — resource canAccess() included — would resolve against
 * whatever team id (if any) happened to be left over, not Hub roles.
 *
 * Registered TWICE, deliberately: once as regular middleware (first in
 * HubPanelProvider's stack) for the initial full-page load, and once as
 * Livewire *persistent* middleware (AppServiceProvider::boot()) for every
 * subsequent Livewire component update/action — see
 * SyncTenantPermissionsTeam's docblock for why the persistent registration
 * is the one that actually matters for Save/action clicks: Livewire's own
 * update endpoint is a single global route outside every panel's route
 * group, so route-based middleware alone never runs for it.
 *
 * Guarded to the hub panel specifically, since persistent middleware runs
 * for every panel's Livewire requests — without this guard, a Hub
 * component update would stomp the admin panel's own tenant-scoped team id
 * (and vice versa via SyncTenantPermissionsTeam) whenever both happen to be
 * open in different tabs of the same browser session.
 */
class SetHubPermissionsTeam
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Filament::getCurrentPanel()?->getId() === 'hub') {
            app(PermissionRegistrar::class)->setPermissionsTeamId(HubAccess::TEAM_ID);
        }

        return $next($request);
    }
}
