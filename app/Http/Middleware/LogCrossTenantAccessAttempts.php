<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Contracts\Activity;
use Symfony\Component\HttpFoundation\Response;

/**
 * Section 18 item 3 — a firm's own staff member trying another firm's admin
 * URL (e.g. hand-editing /admin/lostock-legal to /admin/some-other-firm).
 * Filament's own IdentifyTenant middleware already blocks this (a bare
 * abort(404), so the user sees nothing different from a genuine typo), but
 * that 404 is indistinguishable from a real not-found page — logging every
 * 404 as a security event would be pure noise. This runs its own copy of
 * the same check independently, so it knows precisely what happened rather
 * than inferring intent from a status code, and logs before IdentifyTenant
 * redundantly (harmlessly) 404s the same request moments later.
 *
 * Registered in the base ->middleware() array, before Filament's own tenant
 * identification. The {tenant} route parameter is still the raw slug string
 * at this point, not a resolved model — confirmed by reading IdentifyTenant
 * itself: Filament resolves it manually via $panel->getTenant($slug) inside
 * that middleware, it isn't Laravel's own SubstituteBindings doing implicit
 * route-model-binding, so this does the same lookup itself.
 */
class LogCrossTenantAccessAttempts
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolveTenant($request->route('tenant'));
        $user = Auth::user();

        if ($tenant && $user && ! $user->canAccessTenant($tenant)) {
            activity('access_denied')
                ->causedBy($user)
                ->performedOn($tenant)
                ->withProperties([
                    'path' => $request->path(),
                    'attempted_tenant_slug' => $tenant->slug,
                    'ip' => $request->ip(),
                ])
                ->tap(function (Activity $activity) use ($tenant): void {
                    $activity->tenant_id = $tenant->id;
                })
                ->event('cross_tenant_attempt')
                ->log('Cross-tenant access attempt');
        }

        return $next($request);
    }

    private function resolveTenant(mixed $routeParameter): ?Tenant
    {
        if ($routeParameter instanceof Tenant) {
            return $routeParameter;
        }

        if (is_string($routeParameter)) {
            return Tenant::where('slug', $routeParameter)->first();
        }

        return null;
    }
}
