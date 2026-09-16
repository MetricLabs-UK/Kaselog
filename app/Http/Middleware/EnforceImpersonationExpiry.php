<?php

namespace App\Http\Middleware;

use App\Enums\ImpersonationStatus;
use App\Http\Controllers\ImpersonationController;
use App\Models\ImpersonationSession;
use Closure;
use Illuminate\Http\Request;
use Lab404\Impersonate\Services\ImpersonateManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * Section 19's hard time limit — checked on every Admin panel request while
 * impersonating, not just relied on at accept()/activate() time, since
 * nothing else would ever actually cut an active session off once its
 * session_expires_at passes. Registered in AdminPanelProvider's
 * tenantMiddleware, after SyncTenantPermissionsTeam.
 */
class EnforceImpersonationExpiry
{
    public function handle(Request $request, Closure $next): Response
    {
        $manager = app(ImpersonateManager::class);

        if (! $manager->isImpersonating()) {
            return $next($request);
        }

        $sessionId = $request->session()->get(ImpersonationController::SESSION_KEY);
        $session = $sessionId ? ImpersonationSession::find($sessionId) : null;

        // Not expired yet, and nothing already closed it out from under this
        // browser (e.g. ExpireStaleImpersonationSessions, for a tab left
        // open past the limit with no intervening request) — let it through.
        if ($session && $session->status === ImpersonationStatus::Active && ! $session->isSessionExpired()) {
            return $next($request);
        }

        $director = $manager->getImpersonator();

        $manager->leave();
        ImpersonationController::resetPasswordHashSession();
        $request->session()->forget(ImpersonationController::SESSION_KEY);

        if ($session && $session->status === ImpersonationStatus::Active) {
            $session->end($director, 'expired');
        }

        return redirect()
            ->route('filament.hub.resources.impersonation-sessions.index')
            ->with('impersonation_expired', true);
    }
}
