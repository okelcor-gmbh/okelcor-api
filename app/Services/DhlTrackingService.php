<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DhlTrackingService
{
    public function track(string $trackingNumber): array
    {
        try {
            $response = Http::withHeaders([
                'DHL-API-Key' => config('services.dhl.api_key'),
                'Accept'      => 'application/json',
            ])->get('https://api-eu.dhl.com/track/shipments', [
                'trackingNumber' => $trackingNumber,
            ]);

            Log::info('DHL response', [
                'status' => $response->status(),
                'body'   => $response->json(),
            ]);

            if ($response->status() === 404) {
                return ['error' => 'not_found'];
            }

            if (! $response->ok()) {
                return ['error' => 'unavailable'];
            }

            $shipment = $response->json('shipments.0');

            if (! $shipment) {
                return ['error' => 'not_found'];
            }

            $events = collect($shipment['events'] ?? [])
                ->values()
                ->map(fn ($e, $i) => [
                    'id'           => $i + 1,
                    'event_date'   => $e['timestamp'],
                    'status_label' => $e['description'],
                    'location'     => $e['location']['address']['addressLocality'] ?? null,
                    'description'  => $e['description'],
                ])->toArray();

            return [
                'status'             => $shipment['status']['description'] ?? null,
                'estimated_delivery' => $shipment['estimatedTimeOfDelivery'] ?? null,
                'events'             => $events,
            ];
        } catch (\Throwable) {
            return ['error' => 'unavailable'];
        }
    }
}
