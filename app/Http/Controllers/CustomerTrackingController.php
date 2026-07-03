<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\CarrierTrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer-facing shipment tracking.
 *
 *   GET /api/v1/auth/orders/{ref}/tracking
 *
 * Strictly scoped to the authenticated customer's own order, and kept in step
 * with the order's shipment status so it's always truthful:
 *   - only goes live once the order is actually SHIPPED (in transit);
 *   - shows a "delivered" state once delivered (no implied live movement);
 *   - otherwise returns a clean `available: false` + a reason the UI can show
 *     ("being prepared", "no tracking", "cancelled") instead of an error.
 *
 * Backed by CarrierTrackingService (GLS / DHL / ocean freight incl. Maersk),
 * reading whatever's already persisted (kept fresh by the hourly
 * tracking:sync-carriers command) rather than calling the carrier API on
 * every page view. `mode: "carrier"` is kept in the response even though
 * it's the only mode now — was previously discriminated against `gps_live`
 * (Traccar, Okelcor's own fleet GPS), removed as a feature; keeping the
 * field avoids a frontend contract change for zero benefit.
 */
class CustomerTrackingController extends Controller
{
    /** Order states for which live tracking is meaningful. */
    private const IN_TRANSIT = 'shipped';
    private const DELIVERED  = 'delivered';

    public function __construct(
        private CarrierTrackingService $carrierTracking,
    ) {}

    public function show(Request $request, string $ref): JsonResponse
    {
        $order = Order::where('ref', $ref)
            ->where('customer_email', $request->user()->email)
            ->firstOrFail();

        $status = $order->status;

        // Cancelled orders never show a tracking timeline.
        if ($status === 'cancelled') {
            return $this->unavailable($order, 'order_cancelled', 'This order was cancelled.');
        }

        $hasCarrier = (bool) $order->carrier && ((bool) $order->tracking_number || (bool) $order->container_number);

        // Nothing assigned → nothing to track.
        if (! $hasCarrier) {
            return $this->unavailable($order, 'no_device', 'No live tracking for this order.');
        }

        // Accurate to shipment status: don't show movement while the order is
        // still being prepared (pending / confirmed / processing).
        if (! in_array($status, [self::IN_TRANSIT, self::DELIVERED], true)) {
            return $this->unavailable($order, 'not_shipped', 'Your order is being prepared. Live tracking starts once it ships.');
        }

        $carrierData = $this->carrierTracking->fromPersistedEvents($order);

        return response()->json([
            'data' => array_merge($carrierData, [
                'available'    => true,
                'mode'         => 'carrier',
                'order_ref'    => $order->ref,
                'order_status' => $status,
                'delivered'    => $status === self::DELIVERED,
            ]),
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
