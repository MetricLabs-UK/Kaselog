<?php

namespace App\Http\Middleware;

use App\Filament\Hub\Pages\SetHubPassword;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies on every Hub request (registered as ->authMiddleware(), which
 * wraps the whole authenticated route group — not just login), so an
 * existing session started before the flag was set still gets caught. Only
 * the set-password page itself and logout are exempt, by route name, to
 * avoid a redirect loop and to let a stuck user still sign out.
 */
class EnsureHubPasswordIsChanged
{
    private const EXEMPT_ROUTE_NAMES = [
        'filament.hub.auth.logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->must_change_password) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();

        if ($routeName === SetHubPassword::ROUTE_NAME || in_array($routeName, self::EXEMPT_ROUTE_NAMES, true)) {
            return $next($request);
        }

        return redirect()->route(SetHubPassword::ROUTE_NAME);
    }
}
