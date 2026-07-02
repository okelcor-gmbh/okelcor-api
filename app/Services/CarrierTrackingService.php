<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderShipmentEvent;
use Illuminate\Support\Carbon;

/**
 * Routes an order to the right carrier backend (GLS / DHL / ocean-freight
 * ShipsGo — which covers Maersk and other shipping lines) and normalizes the
 * response into one shape used by both the admin and customer tracking
 * endpoints:
 *
 *   ['carrier' => ?string, 'tracking_number' => ?string, 'stage' => string,
 *    'events' => [['event_date','time','location','status_label','description'], ...]]
 *
 * `stage` is derived from the order's own status (preparing/in_transit/
 * delivered) rather than parsed from carrier text — each carrier's event
 * vocabulary differs too much to infer a reliable 3-step stage from it.
 *
 * Normalized events are persisted into the existing order_shipment_events
 * table (deduped) so the admin's manual timeline and this auto-synced data
 * share one source of truth, and orders.tracking_status stays current.
 */
class CarrierTrackingService
{
    public function __construct(
        private GlsTrackingService $gls,
        private DhlTrackingService $dhl,
        private ShipsGoService $shipsGo,
    ) {}

    /**
     * Fetch live tracking for an order, persist any new events, and return
     * the normalized shape. Never throws — returns ['error' => ...] instead.
     */
    public function trackAndSync(Order $order): array
    {
        $result = $this->fetch($order);

        if (isset($result['error'])) {
            return $result;
        }

        $this->persistEvents($order, $result['events']);

        return array_merge($result, ['stage' => $this->stage($order)]);
    }

    /**
     * Same as trackAndSync() but does not call out to the carrier — reads
     * whatever has already been persisted in order_shipment_events. Used by
     * the customer endpoint so a page view never blocks on a live carrier
     * API call; freshness comes from the hourly tracking:sync-carriers job.
     */
    public function fromPersistedEvents(Order $order): array
    {
        $events = OrderShipmentEvent::where('order_id', $order->id)
            ->orderByDesc('event_date')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (OrderShipmentEvent $e) => [
                'event_date'   => $e->event_date?->toDateString(),
                'time'         => $e->created_at?->format('H:i'),
                'location'     => $e->location,
                'status_label' => $e->status_label,
                'description'  => $e->description,
            ])
            ->values()
            ->toArray();

        return [
            'carrier'         => $order->carrier,
            'tracking_number' => $order->tracking_number ?: $order->container_number,
            'stage'           => $this->stage($order),
            'events'          => $events,
        ];
    }

    // -------------------------------------------------------------------------

    /**
     * Calls the right carrier backend for this order. Returns the raw
     * (not-yet-persisted) normalized {carrier, tracking_number, events} shape,
     * or ['error' => ...].
     */
    private function fetch(Order $order): array
    {
        $carrier        = (string) ($order->carrier ?? '');
        $trackingNumber = $order->tracking_number;
        $container      = $order->container_number;

        // Ocean freight — ShipsGo aggregates many lines, including Maersk.
        if ($order->carrier_type === 'sea' || $container) {
            if (! $container) {
                return ['error' => 'No container number on this order'];
            }

            $raw = $this->shipsGo->trackContainer($container);
            if (isset($raw['error'])) {
                return $raw;
            }

            return [
                'carrier'         => $order->carrier ?: 'Sea Freight',
                'tracking_number' => $container,
                'events'          => collect($raw['events'] ?? [])->map(fn ($e) => [
                    'event_date'   => $this->toDate($e['date'] ?? $e['event_date'] ?? $e['timestamp'] ?? null),
                    'time'         => $this->toTime($e['date'] ?? $e['event_date'] ?? $e['timestamp'] ?? null),
                    'location'     => $e['location'] ?? $e['port'] ?? null,
                    'status_label' => $this->shortLabel($e['description'] ?? $e['event'] ?? $e['status'] ?? 'Update'),
                    'description'  => $e['description'] ?? $e['event'] ?? $e['status'] ?? null,
                ])->filter(fn ($e) => $e['event_date'] !== null)->values()->toArray(),
            ];
        }

        if (! $trackingNumber) {
            return ['error' => 'No tracking number on this order'];
        }

        if (stripos($carrier, 'gls') !== false) {
            $raw = $this->gls->track($trackingNumber);
            if (isset($raw['error'])) {
                return $raw;
            }

            return [
                'carrier'         => $order->carrier ?: 'GLS',
                'tracking_number' => $trackingNumber,
                'events'          => $this->mapTimestampedEvents($raw['events'] ?? []),
            ];
        }

        if (stripos($carrier, 'dhl') !== false || $order->carrier_type === 'dhl') {
            $raw = $this->dhl->track($trackingNumber);
            if (isset($raw['error'])) {
                return $raw;
            }

            return [
                'carrier'         => $order->carrier ?: 'DHL',
                'tracking_number' => $trackingNumber,
                'events'          => $this->mapTimestampedEvents($raw['events'] ?? []),
            ];
        }

        return ['error' => 'No trackable carrier assigned'];
    }

    /** Shared mapping for DHL/GLS raw events (both shaped as timestamp/description/location). */
    private function mapTimestampedEvents(array $events): array
    {
        return collect($events)->map(fn ($e) => [
            'event_date'   => $this->toDate($e['timestamp'] ?? null),
            'time'         => $this->toTime($e['timestamp'] ?? null),
            'location'     => $e['location'] ?? null,
            'status_label' => $this->shortLabel($e['description'] ?? 'Update'),
            'description'  => $e['description'] ?? null,
        ])->filter(fn ($e) => $e['event_date'] !== null)->values()->toArray();
    }

    private function persistEvents(Order $order, array $events): void
    {
        foreach ($events as $event) {
            if (! $event['event_date']) {
                continue;
            }

            OrderShipmentEvent::firstOrCreate([
                'order_id'     => $order->id,
                'event_date'   => $event['event_date'],
                'status_label' => $event['status_label'],
                'location'     => $event['location'],
            ], [
                'order_ref'   => $order->ref,
                'description' => $event['description'],
            ]);
        }

        $latest = OrderShipmentEvent::where('order_id', $order->id)
            ->orderByDesc('event_date')
            ->orderByDesc('created_at')
            ->first();

        if ($latest) {
            $order->update(['tracking_status' => $latest->status_label]);
        }
    }

    private function stage(Order $order): string
    {
        return match ($order->status) {
            'delivered' => 'delivered',
            'shipped'   => 'in_transit',
            default     => 'preparing',
        };
    }

    private function toDate(?string $raw): ?string
    {
        if (! $raw) {
            return null;
        }

        try {
            return Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function toTime(?string $raw): ?string
    {
        if (! $raw) {
            return null;
        }

        try {
            return Carbon::parse($raw)->format('H:i');
        } catch (\Throwable) {
            return null;
        }
    }

    private function shortLabel(string $description): string
    {
        return mb_strlen($description) > 100
            ? mb_substr($description, 0, 97) . '...'
            : $description;
    }
}
