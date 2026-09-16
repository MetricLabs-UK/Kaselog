<?php

namespace App\Livewire;

use App\Enums\ImpersonationStatus;
use App\Http\Controllers\ImpersonationController;
use App\Models\ImpersonationSession;
use Illuminate\Support\Facades\Auth;
use Lab404\Impersonate\Services\ImpersonateManager;
use Livewire\Component;

/**
 * Present on every Admin panel page (see AdminPanelProvider's BODY_END
 * render hook, same mechanism as the Quill launcher). Covers both halves of
 * "request-and-wait" consent for Section 19's impersonation feature:
 *
 * - mount() checks the database directly for a pending request addressed to
 *   the current user — this is what delivers the prompt to someone who
 *   wasn't connected when the request was made; the moment they load any
 *   Admin panel page, they see it, before anything else.
 * - getListeners() additionally subscribes to the same user's private Echo
 *   channel, so someone already active sees the prompt appear live, with no
 *   need to navigate or refresh.
 *
 * Both paths converge on the same $pendingSession property and the same
 * blocking modal in the Blade view — there is no dismiss-without-deciding
 * path in that view (see impersonation-consent.blade.php).
 */
class ImpersonationConsent extends Component
{
    public ?int $pendingSessionId = null;

    public function mount(): void
    {
        $this->refreshPendingSession();
    }

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        $userId = Auth::id();

        if (! $userId) {
            return [];
        }

        return [
            "echo-private:App.Models.User.{$userId},.impersonation.requested" => 'refreshPendingSession',
        ];
    }

    public function refreshPendingSession(): void
    {
        $session = ImpersonationSession::query()
            ->where('target_user_id', Auth::id())
            ->where('status', ImpersonationStatus::Pending)
            ->latest('requested_at')
            ->first();

        if ($session?->isRequestExpired()) {
            $session->expireRequest();
            $session = null;
        }

        $this->pendingSessionId = $session?->id;
    }

    public function accept(): void
    {
        $session = $this->getPendingSession();

        if (! $session) {
            return;
        }

        $session->accept();
        $this->pendingSessionId = null;
    }

    public function decline(): void
    {
        $session = $this->getPendingSession();

        if (! $session) {
            return;
        }

        $session->decline();
        $this->pendingSessionId = null;
    }

    private function getPendingSession(): ?ImpersonationSession
    {
        if (! $this->pendingSessionId) {
            return null;
        }

        $session = ImpersonationSession::find($this->pendingSessionId);

        return $session?->status === ImpersonationStatus::Pending && $session->target_user_id === Auth::id()
            ? $session
            : null;
    }

    public function render()
    {
        $isImpersonated = Auth::user()?->isImpersonated() ?? false;

        return view('livewire.impersonation-consent', [
            'pendingSession' => $this->getPendingSession(),
            'isImpersonated' => $isImpersonated,
            'impersonator' => $isImpersonated ? app(ImpersonateManager::class)->getImpersonator() : null,
            'activeSession' => $isImpersonated ? ImpersonationSession::find(session(ImpersonationController::SESSION_KEY)) : null,
        ]);
    }
}
