<?php

namespace App\Console\Commands;

use App\Enums\ImpersonationStatus;
use App\Models\ImpersonationSession;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Section 19's belt-and-braces sweep. EnforceImpersonationExpiry middleware
 * catches an active session's hard limit reactively, on the director's next
 * Admin panel request — but nothing forces that next request to ever
 * happen, so a session left open in an abandoned tab would sit "active" in
 * the database indefinitely. Likewise a pending request the target never
 * answers, or one they accepted but the director never entered, would
 * otherwise sit open forever too. This is what actually closes all three
 * out, on a schedule, independent of anyone's next request.
 */
#[Signature('app:expire-stale-impersonation-sessions')]
#[Description('Expire pending/accepted impersonation requests and end overdue active sessions.')]
class ExpireStaleImpersonationSessions extends Command
{
    public function handle(): void
    {
        $expiredRequests = 0;
        $expiredGrants = 0;
        $endedSessions = 0;

        ImpersonationSession::query()
            ->where('status', ImpersonationStatus::Pending)
            ->each(function (ImpersonationSession $session) use (&$expiredRequests): void {
                if ($session->isRequestExpired()) {
                    $session->expireRequest();
                    $expiredRequests++;
                }
            });

        ImpersonationSession::query()
            ->where('status', ImpersonationStatus::Accepted)
            ->each(function (ImpersonationSession $session) use (&$expiredGrants): void {
                if ($session->isAcceptedWindowExpired()) {
                    $session->end(null, 'accepted_not_entered');
                    $expiredGrants++;
                }
            });

        ImpersonationSession::query()
            ->where('status', ImpersonationStatus::Active)
            ->each(function (ImpersonationSession $session) use (&$endedSessions): void {
                if ($session->isSessionExpired()) {
                    $session->end(null, 'expired');
                    $endedSessions++;
                }
            });

        $this->info("Expired {$expiredRequests} stale request(s), {$expiredGrants} unentered grant(s), ended {$endedSessions} overdue session(s).");
    }
}
