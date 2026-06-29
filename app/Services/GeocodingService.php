<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Forward geocoding (address → lat/lng) via OpenStreetMap Nominatim.
 *
 * Free, keyless. Nominatim's usage policy requires a descriptive User-Agent with
 * a contact and asks for ≤1 request/second — so results (including failures) are
 * cached, and callers persist the coordinates on the record to avoid re-querying.
 *
 * Never throws — returns null on any failure so tracking/ETA degrades cleanly.
 */
class GeocodingService
{
    /** @return array{lat: float, lon: float}|null */
    public function geocode(string $address): ?array
    {
        $address = trim($address);
        if ($address === '') {
            return null;
        }

        $key = 'geocode:' . md5(mb_strtolower($address));

        if (Cache::has($key)) {
            $cached = Cache::get($key);
            return $cached === 'failed' ? null : $cached;
        }

        $result = $this->lookup($address);

        // Cache successes for 30 days; failures for 1 day (so a transient outage
        // doesn't permanently block, but we don't hammer Nominatim either).
        Cache::put($key, $result ?? 'failed', $result ? now()->addDays(30) : now()->addDay());

        return $result;
    }

    /** @return array{lat: float, lon: float}|null */
    private function lookup(string $address): ?array
    {
        try {
            $contact = (string) config('services.nominatim.email', 'support@okelcor.com');

            $resp = Http::withHeaders([
                'User-Agent' => 'OkelcorAPI/1.0 (' . $contact . ')',
                'Accept'     => 'application/json',
            ])->timeout(10)->get(config('services.nominatim.url') . '/search', [
                'q'      => $address,
                'format' => 'json',
                'limit'  => 1,
            ]);

            if (! $resp->successful()) {
                return null;
            }

            $first = $resp->json()[0] ?? null;
            if (! $first || ! isset($first['lat'], $first['lon'])) {
                return null;
            }

            return ['lat' => (float) $first['lat'], 'lon' => (float) $first['lon']];
        } catch (\Throwable $e) {
            Log::warning('Geocoding failed', ['address' => $address, 'error' => $e->getMessage()]);
            return null;
        }
    }
}
