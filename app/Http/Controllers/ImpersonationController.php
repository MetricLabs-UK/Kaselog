<?php

namespace App\Http\Controllers;

use App\Enums\ImpersonationStatus;
use App\Models\ImpersonationSession;
use App\Support\Hub\HubAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Lab404\Impersonate\Services\ImpersonateManager;

/**
 * Plain (non-Filament-panel) routes for the two moments impersonation
 * actually crosses panels: entering redirects Hub -> Admin, leaving redirects
 * Admin -> Hub. Both need a real HTTP redirect across two entirely separate
 * Filament panels, which a Livewire action inside either panel can't do
 * cleanly — see routes/web.php.
 */
class ImpersonationController extends Controller
{
    /**
     * Session key tracking which ImpersonationSession is currently live for
     * this browser session — set here, read back by leave() and by
     * EnforceImpersonationExpiry, so neither has to reconstruct "which
     * session is this" from director/target ids (which stops being unique
     * the moment a second, unrelated request exists in history).
     */
    public const SESSION_KEY = 'active_impersonation_session_id';

    /**
     * lab404's quietLogin()/quietLogout() swap Auth::user() without going
     * through the normal login() flow (deliberately — that flow fires the
     * Login event, which the package's own service provider listens for to
     * immediately clear impersonation state). But Illuminate\Session\
     * Middleware\AuthenticateSession (present in AdminPanelProvider's
     * middleware stack) independently stores the authenticated user's
     * password hash in the session and compares it on every request;
     * quietLogin() never updates that stored value, so the very next
     * request after entering (or leaving) sees a hash belonging to the
     * PREVIOUS user, treats it as a hijacked session, and force-logs-out —
     * found via a real two-account browser walkthrough, not caught by any
     * automated test since Livewire::test()/actingAs() never run real
     * request-to-request middleware twice in a row against a live session
     * store the way an actual browser does. Forgetting the key (rather than
     * trying to recompute Laravel's exact hash format ourselves) is enough:
     * AuthenticateSession repopulates it itself on the next request once
     * it's missing.
     */
    public static function resetPasswordHashSession(): void
    {
        session()->forget('password_hash_'.Auth::getDefaultDriver());
    }

    public function enter(ImpersonationSession $session): RedirectResponse
    {
        $user = Auth::user();

        abort_unless(
            $session->requested_by === $user->id && $user->hasHubPermission(HubAccess::PERMISSION_IMPERSONATE),
            403,
        );

        abort_if($session->status !== ImpersonationStatus::Accepted || $session->isAcceptedWindowExpired(), 410, 'This impersonation grant is no longer valid.');

        $target = $session->targetUser;

        abort_unless($user->canImpersonate() && $target->canBeImpersonated(), 403);

        $session->activate();

        $user->impersonate($target);
        self::resetPasswordHashSession();

        session([self::SESSION_KEY => $session->id]);

        return redirect()->route('filament.admin.pages.dashboard', ['tenant' => $session->tenant->slug]);
    }

    public function leave(): RedirectResponse
    {
        $user = Auth::user();

        abort_unless($user->isImpersonated(), 403);

        $sessionId = session(self::SESSION_KEY);
        $session = $sessionId ? ImpersonationSession::find($sessionId) : null;

        $director = app(ImpersonateManager::class)->getImpersonator();

        $user->leaveImpersonation();
        self::resetPasswordHashSession();

        session()->forget(self::SESSION_KEY);

        if ($session && $session->status === ImpersonationStatus::Active) {
            $session->end($director, 'director_ended');
        }

        return redirect()->route('filament.hub.resources.impersonation-sessions.index');
    }
}
