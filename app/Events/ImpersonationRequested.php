<?php

namespace App\Events;

use App\Models\ImpersonationSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pushed to the target user's own private channel the instant a director
 * requests impersonation — this is the "on-screen" half of consent: if
 * they're currently active in the Admin panel, ImpersonationConsent (the
 * persistent Livewire component in the panel layout) reacts to this and
 * shows the blocking accept/decline modal immediately. If they're not
 * connected, this broadcast simply has no listener — the same request is
 * still picked up from the database the next time they load any Admin panel
 * page (see ImpersonationConsent::mount()), so the request isn't lost, only
 * delivered later. ShouldBroadcastNow (not ShouldBroadcast): this is
 * latency-sensitive enough that it shouldn't wait on a queue worker's poll
 * cycle.
 */
class ImpersonationRequested implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly ImpersonationSession $session) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('App.Models.User.'.$this->session->target_user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'impersonation.requested';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->session->id,
            'director_name' => $this->session->requestedBy->name,
            'reason' => $this->session->reason,
            'request_expires_at' => $this->session->request_expires_at->toIso8601String(),
        ];
    }
}
