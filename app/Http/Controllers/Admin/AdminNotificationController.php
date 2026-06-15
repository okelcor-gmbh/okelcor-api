<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminNotificationController extends Controller
{
    // ── GET /admin/notifications ───────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $notifications = AdminNotification::where('admin_user_id', $userId)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $unreadCount = AdminNotification::where('admin_user_id', $userId)
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'data'         => $notifications->map(fn ($n) => $this->format($n)),
            'unread_count' => $unreadCount,
        ]);
    }

    // ── POST /admin/notifications/{id}/read ────────────────────────────────

    public function markRead(Request $request, int $id): JsonResponse
    {
        $notification = AdminNotification::where('id', $id)
            ->where('admin_user_id', $request->user()->id)
            ->firstOrFail();

        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json([
            'data'    => $this->format($notification->fresh()),
            'message' => 'Notification marked as read.',
        ]);
    }

    // ── POST /admin/notifications/read-all ─────────────────────────────────

    public function readAll(Request $request): JsonResponse
    {
        AdminNotification::where('admin_user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'message' => 'All notifications marked as read.',
        ]);
    }

    private function format(AdminNotification $notification): array
    {
        return [
            'id'         => $notification->id,
            'type'       => $notification->type,
            'title'      => $notification->title,
            'message'    => $notification->message,
            'link'       => $notification->link,
            'read_at'    => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
