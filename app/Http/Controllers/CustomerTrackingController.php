<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\CarrierTrackingService;
use App\Services\DeliveryEtaService;
use App\Services\GeocodingService;
use App\Services\TraccarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Customer-facing live delivery tracking.
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
 * Two data sources, discriminated by `mode` in the response:
 *   - `gps_live`  — Traccar, for orders carried on Okelcor's own fleet
 *     (a `tracking_device_id` is assigned). Unchanged from the original
 *     GPS-only version of this endpoint.
 *   - `carrier`   — GLS / DHL / ocean freight (incl. Maersk via ShipsGo),
 *     for orders shipped with a third-party carrier. Reads whatever
 *     CarrierTrackingService has already persisted (kept fresh by the
 *     hourly tracking:sync-carriers command) rather than calling the
 *     carrier API on every page view.
 *
 * The payload is intentionally lean and customer-safe (no internal device
 * attributes).
 */
class CustomerTrackingController extends Controller
{
    /** Order states for which a live trail is meaningful. */
    private const IN_TRANSIT = 'shipped';
    private const DELIVERED  = 'delivered';

    public function __construct(
        private TraccarService $traccar,
        private GeocodingService $geocoder,
        private DeliveryEtaService $eta,
        private CarrierTrackingService $carrierTracking,
    ) {}

    public function show(Request $request, string $ref): JsonResponse
    {
        $order = Order::where('ref', $ref)
            ->where('customer_email', $request->user()->email)
            ->firstOrFail();

        $status = $order->status;

        // Cancelled orders never show a live truck or carrier timeline.
        if ($status === 'cancelled') {
            return $this->unavailable($order, 'order_cancelled', 'This order was cancelled.');
        }

        $hasDevice  = (bool) $order->tracking_device_id;
        $hasCarrier = (bool) $order->carrier && ((bool) $order->tracking_number || (bool) $order->container_number);

        // Nothing assigned → nothing to track.
        if (! $hasDevice && ! $hasCarrier) {
            return $this->unavailable($order, 'no_device', 'No live tracking for this order.');
        }

        // Accurate to shipment status: don't show movement while the order is
        // still being prepared (pending / confirmed / processing).
        if (! in_array($status, [self::IN_TRANSIT, self::DELIVERED], true)) {
            return $this->unavailable($order, 'not_shipped', 'Your order is being prepared. Live tracking starts once it ships.');
        }

        if ($hasDevice) {
            return $this->showGpsLive($order, $status);
        }

        return $this->showCarrier($order, $status);
    }

    private function showGpsLive(Order $order, string $status): JsonResponse
    {
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
                'mode'         => 'gps_live',
                'order_ref'    => $order->ref,
                'order_status' => $status,
                'delivered'    => $delivered,
                'name'         => $shapedDevice['name'],
                'status'       => $shapedDevice['status'], // device online/offline
                'last_update'  => $shapedDevice['last_update'],
                'position'     => $shapedDevice['position'],
                'route'        => $route,
                'eta'          => $delivered ? null : $this->buildEta($order, $shapedDevice['position'], $route),
            ],
            'message' => 'success',
        ]);
    }

    private function showCarrier(Order $order, string $status): JsonResponse
    {
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

    /**
     * Straight-line ETA + delivery progress for the in-transit order. Best-effort:
     * returns null (map still shows) if there's no position or the destination
     * can't be geocoded. Never throws.
     *
     * @return array{eta: ?string, minutes_remaining: ?int, distance_remaining_km: float, speed_kmh_used: float, progress_percent: ?int}|null
     */
    private function buildEta(Order $order, ?array $position, array $route): ?array
    {
        try {
            if (! $position || $position['latitude'] === null || $position['longitude'] === null) {
                return null;
            }

            [$destLat, $destLon] = $this->destination($order);
            if ($destLat === null) {
                return null;
            }

            // Effective speed: average of recent MOVING points on the current trip.
            $movingSpeeds = (new Collection($route))
                ->pluck('speed_kmh')
                ->filter(fn ($s) => $s !== null && $s > 5);
            $avgSpeed = $movingSpeeds->isNotEmpty() ? round($movingSpeeds->avg(), 1) : null;

            $estimate = $this->eta->estimate(
                (float) $position['latitude'],
                (float) $position['longitude'],
                $destLat,
                $destLon,
                $avgSpeed,
            );

            // Snapshot the first measured distance as the progress baseline.
            if ($order->route_total_km === null && $estimate['distance_remaining_km'] > 0) {
                $order->forceFill(['route_total_km' => $estimate['distance_remaining_km']])->saveQuietly();
                $order->route_total_km = $estimate['distance_remaining_km'];
            }

            $total    = (float) $order->route_total_km;
            $progress = $total > 0
                ? max(0, min(100, (int) round((1 - $estimate['distance_remaining_km'] / $total) * 100)))
                : null;

            return array_merge($estimate, ['progress_percent' => $progress]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Destination coordinates: use stored values, else geocode the delivery
     * address once (via OSM Nominatim) and persist them on the order.
     *
     * @return array{0: float|null, 1: float|null}
     */
    private function destination(Order $order): array
    {
        if ($order->dest_lat !== null && $order->dest_lon !== null) {
            return [(float) $order->dest_lat, (float) $order->dest_lon];
        }

        $address = trim(implode(', ', array_filter([
            $order->address, $order->city, $order->postal_code, $order->country,
        ])));

        $geo = $this->geocoder->geocode($address);
        if (! $geo) {
            return [null, null];
        }

        $order->forceFill(['dest_lat' => $geo['lat'], 'dest_lon' => $geo['lon']])->saveQuietly();

        return [$geo['lat'], $geo['lon']];
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
