<?php

namespace App\Events;

use App\Models\LiveChatSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Covers both "accepted" and "closed" — broadcast on the shared queue
 * channel too (not just the session's own channel) so every other admin's
 * pending list drops this session the moment someone else claims it,
 * without waiting for a full re-fetch.
 */
class LiveChatSessionStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public LiveChatSession $session) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.chat-queue'),
            new PrivateChannel('chat-session.' . $this->session->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'session.status_changed';
    }

    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->session->id,
            'status'     => $this->session->status,
            'admin_id'   => $this->session->admin_id,
            'admin_name' => $this->session->admin?->display_name ?? $this->session->admin?->name,
        ];
    }
}
