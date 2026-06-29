<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\TraccarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer-facing live delivery tracking (Traccar).
 *
 *   GET /api/v1/auth/orders/{ref}/tracking
 *
 * Strictly scoped to the authenticated customer's own order, and kept in step
 * with the order's shipment status so the map is always truthful:
 *   - only goes live once the order is actually SHIPPED (in transit);
 *   - shows a "delivered" state once delivered (no implied live movement);
 *   - otherwise returns a clean `available: false` + a reason the UI can show
 *     ("being prepared", "no tracking", "cancelled") instead of an error.
 *
 * The payload is intentionally lean and customer-safe (no internal device
 * attributes).
 */
class CustomerTrackingController extends Controller
{
    /** Order states for which a live trail is meaningful. */
    private const IN_TRANSIT = 'shipped';
    private const DELIVERED  = 'delivered';

    public function __construct(private TraccarService $traccar) {}

    public function show(Request $request, string $ref): JsonResponse
    {
        $order = Order::where('ref', $ref)
            ->where('customer_email', $request->user()->email)
            ->firstOrFail();

        $status = $order->status;

        // Nothing assigned → nothing to track.
        if (! $order->tracking_device_id) {
            return $this->unavailable($order, 'no_device', 'No live tracking for this order.');
        }

        // Cancelled orders never show a live truck.
        if ($status === 'cancelled') {
            return $this->unavailable($order, 'order_cancelled', 'This order was cancelled.');
        }

        // Accurate to shipment status: don't show a moving vehicle while the
        // order is still being prepared (pending / confirmed / processing).
        if (! in_array($status, [self::IN_TRANSIT, self::DELIVERED], true)) {
            return $this->unavailable($order, 'not_shipped', 'Your order is being prepared. Live tracking starts once it ships.');
        }

        $deviceId = (int) $order->tracking_device_id;
        $device   = $this->traccar->device($deviceId);

        if (isset($device['error'])) {
            return $this->unavailable($order, 'unavailable', 'Live tracking is temporarily unavailable.');
        }

        $delivered    = $status === self::DELIVERED;
        $shapedDevice = $device['device'];

        // Only pull the moving trail while in transit; a delivered order shows
        // its final position, not a "live" route.
        $route = $delivered ? [] : ($this->traccar->currentTripRoute($deviceId)['route'] ?? []);

        return response()->json([
            'data' => [
                'available'    => true,
                'order_ref'    => $order->ref,
                'order_status' => $status,
                'delivered'    => $delivered,
                'name'         => $shapedDevice['name'],
                'status'       => $shapedDevice['status'], // device online/offline
                'last_update'  => $shapedDevice['last_update'],
                'position'     => $shapedDevice['position'],
                'route'        => $route,
            ],
            'message' => 'success',
        ]);
    }

    private function unavailable(Order $order, string $reason, string $message): JsonResponse
    {
        return response()->json([
            'data' => [
                'available'    => false,
                'reason'       => $reason,
                'order_ref'    => $order->ref,
                'order_status' => $order->status,
            ],
            'message' => $message,
        ]);
    }
}
