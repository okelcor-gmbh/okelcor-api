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

            if (! $response->ok()) {
                return ['error' => 'DHL tracking unavailable'];
            }

            $shipment = $response->json('shipments.0');

            return [
                'status'   => $shipment['status']['description'] ?? null,
                'location' => $shipment['status']['location']['address']['addressLocality'] ?? null,
                'eta'      => $shipment['estimatedTimeOfDelivery'] ?? null,
                'events'   => collect($shipment['events'] ?? [])->map(fn ($e) => [
                    'timestamp'   => $e['timestamp'],
                    'description' => $e['description'],
                    'location'    => $e['location']['address']['addressLocality'] ?? null,
                ])->toArray(),
            ];
        } catch (\Throwable) {
            return ['error' => 'DHL tracking unavailable'];
        }
    }
}
