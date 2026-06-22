<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminNotification;
use App\Services\AdminNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRM-3B — Admin notification center.
 *
 * All routes are scoped to the authenticated admin: a user can only ever see
 * and mutate their own notifications.
 *
 *   GET  /admin/notifications                 list (filters: unread, type, severity, page)
 *   GET  /admin/notifications/unread-count     { unread_count }
 *   POST /admin/notifications/{id}/read
 *   POST /admin/notifications/read-all
 *   POST /admin/notifications/{id}/dismiss
 */
class AdminNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'unread'   => ['sometimes'],
            'type'     => ['sometimes', 'string', 'max:64'],
            'severity' => ['sometimes', Rule::in(AdminNotificationService::SEVERITIES)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $userId = $request->user()->id;

        $query = AdminNotification::forUser($userId)
            ->whereNull('dismissed_at')
            ->orderByDesc('created_at');

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }
        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }
        if ($request->filled('severity')) {
            $query->where('severity', $request->string('severity'));
        }

        $paginated = $query->paginate($request->integer('per_page', 20));

        return response()->json([
            'data' => collect($paginated->items())->map(fn ($n) => $this->format($n))->values(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
                'unread_count' => AdminNotificationService::unreadCount($request->user()),
            ],
            'message' => 'success',
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'unread_count' => AdminNotificationService::unreadCount($request->user()),
        ]);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $notification = AdminNotificationService::markRead($id, $request->user());

        if (! $notification) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        return response()->json([
            'data'         => $this->format($notification->fresh()),
            'unread_count' => AdminNotificationService::unreadCount($request->user()),
            'message'      => 'Notification marked as read.',
        ]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $count = AdminNotificationService::markAllRead($request->user());

        return response()->json([
            'updated'      => $count,
            'unread_count' => 0,
            'message'      => 'All notifications marked as read.',
        ]);
    }

    public function dismiss(Request $request, int $id): JsonResponse
    {
        $notification = AdminNotificationService::dismiss($id, $request->user());

        if (! $notification) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        return response()->json([
            'unread_count' => AdminNotificationService::unreadCount($request->user()),
            'message'      => 'Notification dismissed.',
        ]);
    }

    private function format(AdminNotification $n): array
    {
        return [
            'id'           => $n->id,
            'type'         => $n->type,
            'severity'     => $n->severity ?? 'info',
            'title'        => $n->title,
            'body'         => $n->body ?? $n->message,
            'action_url'   => $n->action_url ?? $n->link,
            'related_type' => $n->related_type,
            'related_id'   => $n->related_id,
            'metadata'     => $n->metadata,
            'read'         => $n->read_at !== null,
            'read_at'      => $n->read_at?->toIso8601String(),
            'dismissed_at' => $n->dismissed_at?->toIso8601String(),
            'created_at'   => $n->created_at?->toIso8601String(),
        ];
    }
}
