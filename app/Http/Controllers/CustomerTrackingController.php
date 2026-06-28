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
 * Strictly scoped to the authenticated customer's own order. Returns a lean,
 * customer-safe view (no internal device attributes): latest position + the
 * recent route. When no device is assigned (or tracking is unavailable) it
 * returns a clean `available: false` payload rather than an error, so the UI
 * can simply hide the map.
 */
class CustomerTrackingController extends Controller
{
    public function __construct(private TraccarService $traccar) {}

    public function show(Request $request, string $ref): JsonResponse
    {
        $order = Order::where('ref', $ref)
            ->where('customer_email', $request->user()->email)
            ->firstOrFail();

        if (! $order->tracking_device_id) {
            return response()->json([
                'data'    => ['available' => false, 'reason' => 'no_device'],
                'message' => 'No live tracking for this order.',
            ]);
        }

        $deviceId = (int) $order->tracking_device_id;

        $device = $this->traccar->device($deviceId);
        if (isset($device['error'])) {
            return response()->json([
                'data'    => ['available' => false, 'reason' => 'unavailable'],
                'message' => 'Live tracking is temporarily unavailable.',
            ]);
        }

        $route = $this->traccar->currentTripRoute($deviceId);
        $shapedDevice = $device['device'];

        return response()->json([
            'data' => [
                'available'   => true,
                'order_ref'   => $order->ref,
                'name'        => $shapedDevice['name'],
                'status'      => $shapedDevice['status'],
                'last_update' => $shapedDevice['last_update'],
                'position'    => $shapedDevice['position'],
                'route'       => $route['route'] ?? [],
            ],
            'message' => 'success',
        ]);
    }
}
