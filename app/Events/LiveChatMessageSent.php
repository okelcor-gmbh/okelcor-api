<?php

namespace App\Events;

use App\Models\LiveChatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LiveChatMessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public LiveChatMessage $message) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('chat-session.' . $this->message->session_id)];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        return [
            'id'          => $this->message->id,
            'session_id'  => $this->message->session_id,
            'sender_type' => $this->message->sender_type,
            'sender_id'   => $this->message->sender_id,
            'body'        => $this->message->body,
            'created_at'  => $this->message->created_at?->toIso8601String(),
        ];
    }
}
