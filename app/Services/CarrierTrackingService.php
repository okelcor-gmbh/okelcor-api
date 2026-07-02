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
 *    'tracking_url' => ?string,
 *    'events' => [['event_date','time','location','status_label','description'], ...]]
 *
 * `stage` is derived from the order's own status (preparing/in_transit/
 * delivered) rather than parsed from carrier text — each carrier's event
 * vocabulary differs too much to infer a reliable 3-step stage from it.
 *
 * `tracking_url` is a best-effort deep link to the carrier's own public
 * tracking page (GLS/DHL/Maersk) — always present whenever a carrier +
 * tracking/container number is set, regardless of whether the API-driven
 * event sync is working. This is the fallback so tracking is never fully
 * blocked on a broken carrier integration (see GLS, currently inactive) or
 * on someone manually logging events — worst case, "here's a link that
 * definitely works" beats "nothing."
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
     * Best-effort live sync: attempts a carrier API call and persists any new
     * events, then always returns the same shape as fromPersistedEvents()
     * (carrier/tracking_number/stage/tracking_url/events) — a failed or
     * unconfigured carrier API (e.g. GLS today) doesn't block the response,
     * it just means events stays whatever was already persisted (manual
     * entries still show, and tracking_url still works). Only returns
     * ['error' => ...] when the order has no carrier/tracking info at all.
     */
    public function trackAndSync(Order $order): array
    {
        if (! $order->carrier || (! $order->tracking_number && ! $order->container_number)) {
            return ['error' => 'No carrier or tracking number set on this order'];
        }

        $result = $this->fetch($order);

        if (! isset($result['error'])) {
            $this->persistEvents($order, $result['events']);
        }

        return $this->buildResponse($order);
    }

    /**
     * Does not call out to any carrier — reads whatever's already persisted
     * in order_shipment_events (manual entries and/or prior auto-syncs) plus
     * the always-available tracking_url. Used by the customer endpoint so a
     * page view never blocks on a live carrier API call.
     */
    public function fromPersistedEvents(Order $order): array
    {
        return $this->buildResponse($order);
    }

    private function buildResponse(Order $order): array
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
            'tracking_url'    => $this->publicTrackingUrl($order),
            'events'          => $events,
        ];
    }

    /**
     * Deep link to the carrier's own public tracking page — no credentials,
     * no API, just a URL template per carrier. Best-effort patterns (worth
     * spot-checking against a real shipment); worst case a broken link is
     * far cheaper to notice/fix than a broken API integration.
     */
    private function publicTrackingUrl(Order $order): ?string
    {
        $carrier        = (string) ($order->carrier ?? '');
        $trackingNumber = $order->tracking_number;
        $container      = $order->container_number;

        if ($trackingNumber && stripos($carrier, 'gls') !== false) {
            return 'https://gls-group.eu/DE/en/parcel-tracking?match=' . urlencode($trackingNumber);
        }

        if ($trackingNumber && (stripos($carrier, 'dhl') !== false || $order->carrier_type === 'dhl')) {
            return 'https://www.dhl.com/de-en/home/tracking.html?tracking-id=' . urlencode($trackingNumber);
        }

        if ($container && stripos($carrier, 'maersk') !== false) {
            return 'https://www.maersk.com/tracking/' . urlencode($container);
        }

        return null;
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
