<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\GeocodingService;
use App\Services\TraccarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin fleet tracking (Traccar) — live devices, positions, trips, routes,
 * geofences. Read endpoints are gated by `tracking.view`; assigning a device to
 * an order is a write gated by `orders.update`.
 *
 * All responses follow the project's { data, meta, message } convention and
 * degrade gracefully (503) when the Traccar server is unconfigured/unreachable.
 */
class AdminTrackingController extends Controller
{
    public function __construct(
        private TraccarService $traccar,
        private GeocodingService $geocoder,
    ) {}

    /** GET /admin/tracking/status — connection/readiness probe. */
    public function status(): JsonResponse
    {
        return response()->json(['data' => $this->traccar->status(), 'message' => 'success']);
    }

    /** GET /admin/tracking/devices — all devices + latest positions. */
    public function devices(): JsonResponse
    {
        $result = $this->traccar->devicesWithPositions();

        return $this->respond($result, fn () => [
            'data'    => $result['devices'],
            'meta'    => ['total' => count($result['devices'])],
            'message' => 'success',
        ]);
    }

    /** GET /admin/tracking/devices/{id} — one device + latest position. */
    public function device(int $id): JsonResponse
    {
        $result = $this->traccar->device($id);

        return $this->respond($result, fn () => [
            'data'    => $result['device'],
            'message' => 'success',
        ]);
    }

    /** GET /admin/tracking/devices/{id}/route?from=&to= — route points. */
    public function route(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'from' => ['sometimes', 'date'],
            'to'   => ['sometimes', 'date'],
        ]);

        $result = $this->traccar->route($id, $request->query('from'), $request->query('to'));

        return $this->respond($result, fn () => [
            'data'    => $result['route'],
            'meta'    => ['from' => $result['from'], 'to' => $result['to'], 'total' => count($result['route'])],
            'message' => 'success',
        ]);
    }

    /** GET /admin/tracking/devices/{id}/trips?from=&to= — trip summaries. */
    public function trips(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'from' => ['sometimes', 'date'],
            'to'   => ['sometimes', 'date'],
        ]);

        $result = $this->traccar->trips($id, $request->query('from'), $request->query('to'));

        return $this->respond($result, fn () => [
            'data'    => $result['trips'],
            'meta'    => ['from' => $result['from'], 'to' => $result['to'], 'total' => count($result['trips'])],
            'message' => 'success',
        ]);
    }

    /** GET /admin/tracking/geofences — geofence zones. */
    public function geofences(): JsonResponse
    {
        $result = $this->traccar->geofences();

        return $this->respond($result, fn () => [
            'data'    => $result['geofences'],
            'message' => 'success',
        ]);
    }

    /**
     * PUT /admin/tracking/orders/{id}/device — link/unlink a Traccar device to
     * an order so the customer can track their delivery. (orders.update)
     */
    public function assignDevice(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'tracking_device_id' => ['present', 'nullable', 'string', 'max:64'],
        ]);

        $order = Order::findOrFail($id);
        $order->update(['tracking_device_id' => $data['tracking_device_id'] ?: null]);

        return response()->json([
            'data'    => ['ref' => $order->ref, 'tracking_device_id' => $order->tracking_device_id],
            'message' => $order->tracking_device_id ? 'Tracking device assigned.' : 'Tracking device cleared.',
        ]);
    }

    /**
     * PUT /admin/tracking/orders/{id}/destination — set the delivery destination
     * for ETA/progress when the order address can't be geocoded automatically.
     * (orders.update)
     *
     * Accepts either an explicit pin (`lat` + `lon`) or an `address` to geocode.
     * Empty body clears the destination. Either way the progress baseline
     * (route_total_km) is reset so it recomputes against the new destination.
     */
    public function setDestination(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'lat'     => ['nullable', 'numeric', 'between:-90,90', 'required_with:lon'],
            'lon'     => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        $order = Order::findOrFail($id);

        // Explicit pin wins.
        if (isset($data['lat'], $data['lon'])) {
            $lat = (float) $data['lat'];
            $lon = (float) $data['lon'];
        } elseif (! empty($data['address'])) {
            $geo = $this->geocoder->geocode($data['address']);
            if (! $geo) {
                return response()->json([
                    'message' => 'Could not find coordinates for that address. Enter a lat/lng pin instead.',
                    'code'    => 'geocode_failed',
                ], 422);
            }
            $lat = $geo['lat'];
            $lon = $geo['lon'];
        } else {
            // Clear.
            $order->update(['dest_lat' => null, 'dest_lon' => null, 'route_total_km' => null]);
            return response()->json([
                'data'    => ['ref' => $order->ref, 'dest_lat' => null, 'dest_lon' => null],
                'message' => 'Destination cleared.',
            ]);
        }

        $order->update([
            'dest_lat'       => $lat,
            'dest_lon'       => $lon,
            'route_total_km' => null, // reset progress baseline for the new destination
        ]);

        return response()->json([
            'data'    => ['ref' => $order->ref, 'dest_lat' => (float) $order->dest_lat, 'dest_lon' => (float) $order->dest_lon],
            'message' => 'Destination set.',
        ]);
    }

    /**
     * Return the success payload, or a 503 carrying the Traccar error message.
     */
    private function respond(array $result, callable $onSuccess): JsonResponse
    {
        if (isset($result['error'])) {
            return response()->json(['data' => null, 'message' => $result['error']], 503);
        }

        return response()->json($onSuccess());
    }
}
