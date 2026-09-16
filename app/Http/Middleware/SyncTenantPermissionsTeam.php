<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CurrentTenant's docblock assumes spatie's permissions team id gets primed
 * "incidentally" by TenantScope the first time any tenant-scoped model is
 * queried during a request — but canAccess() (used for both nav visibility
 * and route-level access gating) runs before any resource query does,
 * against whatever team id was left over from a previous request/console
 * bootstrap. On a fresh request that's null, so every `->can(...)` check —
 * including canAccess() itself — resolves against team_id IS NULL and
 * always fails: real users got a bare 403 on every tenant resource
 * (confirmed via a real browser login, not just tests — RefreshDatabase's
 * SetsUpTenant::actingAsRole() masked this because it seeds roles via
 * CurrentTenant::set() directly, sidestepping the request lifecycle gap
 * entirely).
 *
 * Registered TWICE, deliberately: once as regular tenant middleware (after
 * Filament's own tenant-identification, so Filament::getTenant() is already
 * reliable — see EnsureTenantIsActive) for the initial full-page load, and
 * once as Livewire *persistent* middleware (AppServiceProvider::boot(), the
 * same mechanism Filament's own IdentifyTenant uses) for every subsequent
 * Livewire component update/action. Route-based middleware only runs for
 * the route it's attached to — Livewire's own update endpoint
 * (POST livewire-{hash}/update) is a single global route completely outside
 * any panel's route group (confirmed via `route:list -v`: just ['web',
 * RequireLivewireHeaders]) — so without the persistent registration too,
 * every Save/action click was checking permissions against whatever team id
 * happened to be left over from nothing, working only when some other
 * tenant-scoped query incidentally re-primed it first. Found via a real
 * Edit Role save 403ing in the browser despite passing every automated
 * test (Livewire::test() calls component methods directly, bypassing the
 * real HTTP route entirely, so it never exercises this gap).
 *
 * Guarded to the admin panel specifically (persistent middleware runs for
 * every panel's Livewire requests, not just this one) so it never fights
 * SetHubPermissionsTeam over which team id should currently be active.
 */
class SyncTenantPermissionsTeam
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Filament::getCurrentPanel()?->getId() === 'admin') {
            $tenant = Filament::getTenant();

            CurrentTenant::set($tenant instanceof Tenant ? $tenant : null);
        }

        return $next($request);
    }
}
