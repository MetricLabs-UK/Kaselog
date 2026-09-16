<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Spatie\Activitylog\Contracts\Activity;
use Symfony\Component\HttpFoundation\Response;

/**
 * Section 18 item 3 — every authorization denial in this app is a plain
 * abort(403) or Filament's own equivalent (no Laravel Policy classes exist,
 * per BRIEF.txt's explicit instruction — see AuditLogResource's docblock and
 * the audit's section 14), so unlike LogAuthenticationActivity/
 * LogPermissionActivity there's no framework event to hook.
 *
 * Checks the RESPONSE status code, not a caught exception — confirmed via
 * a real failing test that abort(403) thrown deep inside a Filament/
 * Livewire page's mount lifecycle never reaches this middleware as a
 * propagating exception at all: Laravel's router converts it to a Response
 * from within its own nested dispatch pipeline before control returns here,
 * so a try/catch around $next() silently never fires. The response itself
 * already carries the right status code by the time it gets back to us,
 * which is all that's actually needed.
 *
 * Does NOT catch 404s — Filament's cross-tenant guard (IdentifyTenant)
 * throws a bare abort(404) indistinguishable from a genuine not-found page,
 * so treating it as a security signal here would be guessing at intent.
 * See LogCrossTenantAccessAttempts for that case specifically — it re-runs
 * the same access check independently, before Filament's own, so it knows
 * exactly what happened rather than inferring it from a status code.
 */
class LogAccessDenials
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() === 403) {
            $this->log($request);
        }

        return $response;
    }

    private function log(Request $request): void
    {
        $panel = Filament::getCurrentPanel();

        activity('access_denied')
            ->causedBy(auth()->user())
            ->withProperties(array_filter([
                'path' => $request->path(),
                'method' => $request->method(),
                'panel' => $panel?->getId(),
                'ip' => $request->ip(),
            ], fn (mixed $value): bool => $value !== null))
            ->tap(function (Activity $activity): void {
                $tenant = Filament::getTenant();

                if ($tenant instanceof Tenant) {
                    $activity->tenant_id = $tenant->id;
                }
            })
            ->event('access_denied')
            ->log('Access denied');
    }
}
