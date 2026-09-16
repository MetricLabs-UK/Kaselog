<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks every request into a suspended firm's tenant-scoped routes (admin
 * panel and client portal alike) with a branded page rather than a generic
 * 403 — Filament's own IdentifyTenant middleware has already resolved the
 * tenant by the time this runs (registered via ->tenantMiddleware(), which
 * always runs after it), so Filament::getTenant() is reliable here.
 */
class EnsureTenantIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();

        if ($tenant instanceof Tenant && ! $tenant->is_active) {
            return response()->view('errors.firm-suspended', [
                'tenant' => $tenant,
            ], 403);
        }

        return $next($request);
    }
}
