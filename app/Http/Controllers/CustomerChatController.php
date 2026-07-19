<?php

namespace App\Http\Controllers;

use App\Models\LiveChatSession;
use App\Services\LiveChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer-portal / website widget side of live chat (see
 * FRONTEND_NOTE_admin-mobile-app-v2-premium.md, Pillar 1).
 *
 *   POST /api/v1/auth/chat/sessions
 *   GET  /api/v1/auth/chat/sessions/{id}
 *   POST /api/v1/auth/chat/sessions/{id}/messages
 *   POST /api/v1/auth/chat/sessions/{id}/close
 *
 * Real-time delivery happens over Pusher (private channel
 * `chat-session.{id}`, authorized in routes/channels.php) — these
 * endpoints are for starting a session, sending a message, and the
 * initial/fallback fetch, not for polling.
 */
class CustomerChatController extends Controller
{
    public function __construct(private LiveChatService $chat) {}

    public function store(Request $request): JsonResponse
    {
        $session = $this->chat->startSession($request->user());
        $session->load('messages');

        return response()->json(['data' => $this->format($session), 'message' => 'success'], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $session = LiveChatSession::where('customer_id', $request->user()->id)
            ->with('messages')
            ->findOrFail($id);

        return response()->json(['data' => $this->format($session), 'message' => 'success']);
    }

    public function sendMessage(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $session = LiveChatSession::where('customer_id', $request->user()->id)->findOrFail($id);
        if ($session->isClosed()) {
            return response()->json(['message' => 'This chat has ended.'], 409);
        }

        $message = $this->chat->sendCustomerMessage($session, $request->user(), $data['body']);

        return response()->json(['data' => ['id' => $message->id, 'created_at' => $message->created_at->toIso8601String()], 'message' => 'success'], 201);
    }

    public function close(Request $request, int $id): JsonResponse
    {
        $session = LiveChatSession::where('customer_id', $request->user()->id)->findOrFail($id);
        $this->chat->close($session, 'closed_by_customer');

        return response()->json(['message' => 'Chat ended.']);
    }

    private function format(LiveChatSession $s): array
    {
        return [
            'id'         => $s->id,
            'status'     => $s->status,
            'admin_name' => $s->admin?->display_name ?? $s->admin?->name,
            'messages'   => $s->messages->map(fn ($m) => [
                'id'          => $m->id,
                'sender_type' => $m->sender_type,
                'body'        => $m->body,
                'created_at'  => $m->created_at->toIso8601String(),
            ])->values(),
            'created_at' => $s->created_at->toIso8601String(),
        ];
    }
}
