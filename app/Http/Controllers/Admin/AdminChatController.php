<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LiveChatSession;
use App\Services\LiveChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin/mobile-app side of live chat (see
 * FRONTEND_NOTE_admin-mobile-app-v2-premium.md, Pillar 1).
 *
 *   GET  /api/v1/admin/chat-sessions
 *   POST /api/v1/admin/chat-sessions/{id}/accept
 *   POST /api/v1/admin/chat-sessions/{id}/messages
 *   POST /api/v1/admin/chat-sessions/{id}/close
 *
 * No decline endpoint by design — declining a push notification just
 * dismisses it client-side; the session stays "pending" and visible to
 * every other available admin, no server state to change.
 */
class AdminChatController extends Controller
{
    public function __construct(private LiveChatService $chat) {}

    public function index(Request $request): JsonResponse
    {
        $query = LiveChatSession::with(['customer:id,first_name,last_name,company_name'])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        } else {
            $query->whereIn('status', ['pending', 'active']);
        }

        $sessions = $query->limit(50)->get();

        return response()->json([
            'data' => $sessions->map(fn ($s) => $this->formatSummary($s))->values(),
            'message' => 'success',
        ]);
    }

    public function accept(Request $request, int $id): JsonResponse
    {
        $session = LiveChatSession::findOrFail($id);

        if (! $this->chat->accept($session, $request->user())) {
            return response()->json([
                'message' => 'This chat was already claimed by another admin.',
                'code'    => 'already_claimed',
            ], 409);
        }

        return response()->json(['data' => $this->format($session->fresh(['messages'])), 'message' => 'Chat accepted.']);
    }

    public function sendMessage(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $session = LiveChatSession::where('admin_id', $request->user()->id)->findOrFail($id);
        if ($session->isClosed()) {
            return response()->json(['message' => 'This chat has ended.'], 409);
        }

        $message = $this->chat->sendAdminMessage($session, $request->user(), $data['body']);

        return response()->json(['data' => ['id' => $message->id, 'created_at' => $message->created_at->toIso8601String()], 'message' => 'success'], 201);
    }

    public function close(Request $request, int $id): JsonResponse
    {
        $session = LiveChatSession::where('admin_id', $request->user()->id)->findOrFail($id);
        $this->chat->close($session, 'closed_by_admin');

        return response()->json(['message' => 'Chat closed.']);
    }

    private function formatSummary(LiveChatSession $s): array
    {
        $customer = $s->customer;

        return [
            'id'            => $s->id,
            'status'        => $s->status,
            'customer_id'   => $s->customer_id,
            'customer_name' => $customer?->company_name ?: trim(($customer?->first_name ?? '') . ' ' . ($customer?->last_name ?? '')),
            'admin_id'      => $s->admin_id,
            'created_at'    => $s->created_at->toIso8601String(),
            'last_message_at' => $s->last_message_at?->toIso8601String(),
        ];
    }

    private function format(LiveChatSession $s): array
    {
        return array_merge($this->formatSummary($s), [
            'messages' => $s->messages->map(fn ($m) => [
                'id'          => $m->id,
                'sender_type' => $m->sender_type,
                'sender_id'   => $m->sender_id,
                'body'        => $m->body,
                'created_at'  => $m->created_at->toIso8601String(),
            ])->values(),
        ]);
    }
}
