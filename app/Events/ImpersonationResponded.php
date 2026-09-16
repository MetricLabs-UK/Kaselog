<?php

namespace App\Events;

use App\Models\ImpersonationSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pushed to the requesting director's own private channel once the target
 * accepts or declines — lets the Hub-side "waiting for approval" view react
 * live instead of needing to poll, mirroring ImpersonationRequested's role
 * for the other direction of this two-party flow.
 */
class ImpersonationResponded implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly ImpersonationSession $session) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('App.Models.User.'.$this->session->requested_by),
        ];
    }

    public function broadcastAs(): string
    {
        return 'impersonation.responded';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->session->id,
            'status' => $this->session->status->value,
            'target_name' => $this->session->targetUser->name,
        ];
    }
}
