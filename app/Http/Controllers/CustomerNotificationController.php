<?php

namespace App\Http\Controllers;

use App\Models\CustomerNotification;
use App\Services\CustomerNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Customer Portal Notifications — "Email = Inbox".
 *
 * Every route is scoped to the authenticated customer (auth.customer): a
 * customer can only ever see / mutate their OWN notifications.
 *
 *   GET  /auth/customer/notifications                 list (unread/type/severity/page/per_page)
 *   GET  /auth/customer/notifications/unread-count     { unread_count }
 *   POST /auth/customer/notifications/{id}/read
 *   POST /auth/customer/notifications/read-all
 *   POST /auth/customer/notifications/{id}/dismiss
 */
class CustomerNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'unread'   => ['sometimes'],
            'type'     => ['sometimes', 'string', 'max:48'],
            'severity' => ['sometimes', Rule::in(CustomerNotifier::SEVERITIES)],
            'page'     => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $customer = $request->user();

        $query = CustomerNotification::forCustomer($customer->id)
            ->visible()
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }
        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }
        if ($request->filled('severity')) {
            $query->where('severity', $request->string('severity'));
        }

        $paginated = $query->paginate($request->integer('per_page', 15));

        return response()->json([
            'data' => collect($paginated->items())->map(fn ($n) => $this->format($n))->values(),
            'unread_count' => CustomerNotifier::unreadCount($customer),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ],
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'unread_count' => CustomerNotifier::unreadCount($request->user()),
        ]);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $n = CustomerNotifier::markRead($id, $request->user());

        if (! $n) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        return response()->json(['ok' => true]);
    }

    public function readAll(Request $request): JsonResponse
    {
        CustomerNotifier::markAllRead($request->user());

        return response()->json(['ok' => true]);
    }

    public function dismiss(Request $request, int $id): JsonResponse
    {
        $n = CustomerNotifier::dismiss($id, $request->user());

        if (! $n) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        return response()->json(['ok' => true]);
    }

    private function format(CustomerNotification $n): array
    {
        return [
            'id'            => $n->id,
            'type'          => $n->type,
            'title'         => $n->title,
            'body'          => $n->body,
            'severity'      => $n->severity ?? 'info',
            'action_url'    => $n->action_url,
            'related_type'  => $n->related_type,
            'related_id'    => $n->related_id,
            'read_at'       => $n->read_at?->toIso8601String(),
            'dismissed_at'  => $n->dismissed_at?->toIso8601String(),
            'email_sent_at' => $n->email_sent_at?->toIso8601String(),
            'metadata'      => $n->metadata,
            'created_at'    => $n->created_at?->toIso8601String(),
        ];
    }
}
