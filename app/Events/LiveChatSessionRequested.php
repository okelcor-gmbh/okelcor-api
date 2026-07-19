<?php

namespace App\Events;

use App\Models\LiveChatSession;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A customer opened the chat widget and a new session is waiting to be
 * claimed. Broadcast to the shared admin queue channel (not any specific
 * admin) — every active admin's app can update its queue view live; who
 * actually gets nudged to look is the push notification sent alongside
 * this (see CustomerChatController), not this broadcast itself.
 */
class LiveChatSessionRequested implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public LiveChatSession $session) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('admin.chat-queue')];
    }

    public function broadcastAs(): string
    {
        return 'session.requested';
    }

    public function broadcastWith(): array
    {
        $customer = $this->session->customer;

        return [
            'session_id'    => $this->session->id,
            'customer_name' => $customer?->company_name ?: trim(($customer?->first_name ?? '') . ' ' . ($customer?->last_name ?? '')),
            'started_at'    => $this->session->created_at?->toIso8601String(),
        ];
    }
}
