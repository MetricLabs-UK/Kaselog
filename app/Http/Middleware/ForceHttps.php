<?php

namespace App\Http\Middleware;

use App\Support\Environment;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AppServiceProvider::boot() already forces *generated* URLs (route(),
 * url()) to https when APP_URL is https — this is the other half: an
 * incoming plain-http *request* gets redirected, not just links rendered as
 * https. Registered as a true global middleware (bootstrap/app.php), not
 * scoped to the 'web' group, since Filament panels build their own
 * middleware stacks directly on their routes rather than going through that
 * named group — this has to run before any of them to cover every panel.
 *
 * Skipped entirely on a local dev APP_URL (App\Support\Environment — same
 * check the session cookie's 'secure' default and the debug-mode safety net
 * use), so plain HTTP keeps working for local testing.
 *
 * $request->secure() (not scheme-string-matching by hand) — correctly
 * respects trusted proxies once configured. If this app ends up behind a
 * TLS-terminating reverse proxy/load balancer and bootstrap/app.php's
 * trustProxies() isn't configured for it, every request looks like plain
 * HTTP to Laravel regardless of what the client actually used, and this
 * redirects forever. See bootstrap/app.php's own comment on that.
 */
class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Environment::isLocalUrl() || $request->secure()) {
            return $next($request);
        }

        return redirect()->to(
            'https://'.$request->getHttpHost().$request->getRequestUri(),
            301,
        );
    }
}
