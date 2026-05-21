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
                return ['error' => 'Tracking unavailable'];
            }

            $data = $response->json();

            return [
                'status'   => $data['status']         ?? null,
                'vessel'   => $data['vessel']          ?? null,
                'location' => $data['location']        ?? null,
                'eta'      => $data['eta']              ?? null,
                'events'   => $data['events']           ?? [],
            ];
        } catch (\Throwable) {
            return ['error' => 'Tracking unavailable'];
        }
    }
}
