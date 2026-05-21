<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShipsGoService
{
    public function trackContainer(string $containerNumber): array
    {
        try {
            // Step 1 — Register container for tracking (idempotent — safe to call repeatedly)
            Http::withHeaders([
                'X-Shipsgo-User-Token' => config('services.shipsgo.key'),
                'Content-Type'         => 'application/json',
            ])->post('https://api.shipsgo.com/v2/ocean/shipments', [
                'container_no' => $containerNumber,
            ]);

            // Step 2 — Fetch current tracking status
            $response = Http::withHeaders([
                'X-Shipsgo-User-Token' => config('services.shipsgo.key'),
                'Content-Type'         => 'application/json',
            ])->get('https://api.shipsgo.com/v2/ocean/shipments', [
                'filters[container_no]' => 'eq:' . $containerNumber,
            ]);

            Log::info('ShipsGo response', [
                'status' => $response->status(),
                'body'   => $response->json(),
            ]);

            if (! $response->successful()) {
                return ['error' => 'unavailable'];
            }

            $data     = $response->json();
            $shipment = $data['data'][0] ?? $data;

            if (empty($shipment)) {
                return ['error' => 'not_found'];
            }

            $events = collect($shipment['events'] ?? [])
                ->values()
                ->map(fn ($e, $i) => [
                    'id'           => $i + 1,
                    'event_date'   => $e['event_date'] ?? $e['timestamp'] ?? $e['date'] ?? null,
                    'status_label' => $e['event']      ?? $e['description'] ?? $e['status'] ?? null,
                    'location'     => $e['location']   ?? $e['port'] ?? null,
                    'description'  => $e['event']      ?? $e['description'] ?? null,
                ])->toArray();

            return [
                'status'             => $shipment['status'] ?? null,
                'estimated_delivery' => $shipment['eta']    ?? null,
                'events'             => $events,
            ];
        } catch (\Throwable) {
            return ['error' => 'unavailable'];
        }
    }
}
