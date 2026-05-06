<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderLog;
use App\Models\OrderShipmentEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminOrderShipmentEventController extends Controller
{
    /**
     * POST /api/v1/admin/orders/{id}/shipment-events
     */
    public function store(Request $request, int $id): JsonResponse
    {
        $order = Order::findOrFail($id);

        $data = $request->validate([
            'event_date'   => ['required', 'date'],
            'status_label' => ['required', 'string', 'max:100'],
            'location'     => ['sometimes', 'nullable', 'string', 'max:200'],
            'description'  => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $event = OrderShipmentEvent::create([
            'order_id'      => $order->id,
            'order_ref'     => $order->ref,
            'event_date'    => $data['event_date'],
            'status_label'  => $data['status_label'],
            'location'      => $data['location'] ?? null,
            'description'   => $data['description'] ?? null,
            'admin_user_id' => $request->user()?->id,
        ]);

        $this->syncTrackingStatus($order);

        $this->writeLog($request, $order, 'tracking_updated', [
            'notes' => 'Shipment event added: ' . $event->status_label
                . ($event->location ? ' at ' . $event->location : ''),
        ]);

        return response()->json([
            'data'    => $this->formatEvent($event),
            'message' => 'Shipment event added.',
        ], 201);
    }

    /**
     * PUT /api/v1/admin/orders/{id}/shipment-events/{event}
     */
    public function update(Request $request, int $id, int $event): JsonResponse
    {
        $order         = Order::findOrFail($id);
        $shipmentEvent = OrderShipmentEvent::where('order_id', $order->id)->findOrFail($event);

        $data = $request->validate([
            'event_date'   => ['required', 'date'],
            'status_label' => ['required', 'string', 'max:100'],
            'location'     => ['sometimes', 'nullable', 'string', 'max:200'],
            'description'  => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $shipmentEvent->update($data);
        $this->syncTrackingStatus($order);

        $this->writeLog($request, $order, 'tracking_updated', [
            'notes' => 'Shipment event updated: ' . $shipmentEvent->status_label,
        ]);

        return response()->json([
            'data'    => $this->formatEvent($shipmentEvent->fresh()),
            'message' => 'Shipment event updated.',
        ]);
    }

    /**
     * DELETE /api/v1/admin/orders/{id}/shipment-events/{event}
     */
    public function destroy(Request $request, int $id, int $event): JsonResponse
    {
        $order         = Order::findOrFail($id);
        $shipmentEvent = OrderShipmentEvent::where('order_id', $order->id)->findOrFail($event);

        $shipmentEvent->delete();
        $this->syncTrackingStatus($order);

        $this->writeLog($request, $order, 'tracking_updated', [
            'notes' => 'Shipment event deleted.',
        ]);

        return response()->json(['message' => 'Shipment event deleted.']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function syncTrackingStatus(Order $order): void
    {
        $latest = OrderShipmentEvent::where('order_id', $order->id)
            ->orderByDesc('event_date')
            ->orderByDesc('created_at')
            ->first();

        $order->update(['tracking_status' => $latest?->status_label]);
    }

    private function formatEvent(OrderShipmentEvent $e): array
    {
        return [
            'id'           => $e->id,
            'event_date'   => $e->event_date?->toDateString(),
            'location'     => $e->location,
            'status_label' => $e->status_label,
            'description'  => $e->description,
            'created_at'   => $e->created_at?->toIso8601String(),
        ];
    }

    private function writeLog(Request $request, Order $order, string $action, array $extra = []): void
    {
        try {
            $admin = $request->user();

            OrderLog::create([
                'order_id'         => $order->id,
                'order_ref'        => $order->ref,
                'admin_user_id'    => $admin?->id,
                'admin_user_email' => $admin?->email,
                'action'           => $action,
                'old_value'        => $extra['old_value'] ?? null,
                'new_value'        => $extra['new_value'] ?? null,
                'notes'            => $extra['notes'] ?? null,
                'ip_address'       => $request->ip(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('OrderLog write failed (shipment event)', [
                'order_ref' => $order->ref,
                'action'    => $action,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
