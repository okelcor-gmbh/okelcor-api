<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderSignoff;
use App\Services\OrderSignoffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The two signatures on an order confirmation.
 *
 * Routed under `orders.view` rather than a sign-off permission, because the
 * entitlement to SIGN is checked per slot inside the service — a sales manager
 * who can see the order should be able to see whether it has been approved, and
 * refusing them the read would only mean asking someone in a chat instead.
 */
class AdminOrderSignoffController extends Controller
{
    public function __construct(private OrderSignoffService $signoffs)
    {
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/orders/{id}/signoffs — orders.view
    // -------------------------------------------------------------------------
    public function index(Request $request, int $id): JsonResponse
    {
        $order = Order::findOrFail($id);
        $admin = $request->user();

        // `you_may_sign` / `you_may_revoke` come from state() itself now, so
        // this endpoint and the block embedded in the order detail return the
        // identical shape rather than one being a superset of the other.
        return response()->json([
            'data'    => $this->signoffs->state($order, $admin),
            'message' => 'success',
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/admin/orders/{id}/signoffs — orders.view
    //
    // Entitlement is per slot and enforced in the service, not by the route:
    // the ops and finance halves are held by different roles, so one route
    // middleware cannot express it.
    // -------------------------------------------------------------------------
    public function store(Request $request, int $id): JsonResponse
    {
        $order = Order::findOrFail($id);

        $data = $request->validate([
            'slot' => ['required', Rule::in(OrderSignoff::SLOTS)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $this->signoffs->sign(
            $order,
            $request->user(),
            $data['slot'],
            $data['note'] ?? null,
            $request->ip()
        );

        if (! $result['ok']) {
            return response()->json([
                'message' => $result['message'],
                'code'    => $result['code'],
                'data'    => $this->signoffs->state($order->fresh()),
            ], $result['code'] === 'not_entitled' ? 403 : 409);
        }

        $state = $this->signoffs->state($order->fresh());

        return response()->json([
            'data'    => $state,
            'message' => $state['complete']
                ? 'Signed. Both signatures are in — this confirmation can now be sent.'
                : 'Signed. Still waiting on the other signature.',
        ], 201);
    }

    // -------------------------------------------------------------------------
    // DELETE /api/v1/admin/orders/{id}/signoffs/{slot} — orders.view
    //
    // A reason is required. A signature that can be removed without saying why
    // is one nobody can rely on afterwards.
    // -------------------------------------------------------------------------
    public function destroy(Request $request, int $id, string $slot): JsonResponse
    {
        $order = Order::findOrFail($id);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $result = $this->signoffs->revoke($order, $request->user(), $slot, $data['reason'], $request->ip());

        if (! $result['ok']) {
            return response()->json([
                'message' => $result['message'],
                'code'    => $result['code'],
            ], $result['code'] === 'not_entitled' ? 403 : 409);
        }

        return response()->json([
            'data'    => $this->signoffs->state($order->fresh()),
            'message' => 'Signature withdrawn.',
        ]);
    }
}
