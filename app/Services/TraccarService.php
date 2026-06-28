<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Traccar — open-source GPS / fleet tracking.
 *
 * We are a REST CLIENT of a Traccar server (it runs elsewhere — own VPS/cloud,
 * or the public demo server for trials). This service mirrors the DHL/ShipsGo
 * pattern: config-driven credentials, every call wrapped so a failure degrades
 * gracefully to ['error' => …] rather than throwing.
 *
 * Auth: a Traccar API token (Bearer) is preferred; Basic auth (email/password,
 * e.g. a demo account) is the fallback.
 *
 * Units: Traccar reports speed in KNOTS and distance in METRES — we normalise
 * to km/h and km in the shaped output so the frontend never has to convert.
 *
 * Docs: https://www.traccar.org/api-reference/
 */
class TraccarService
{
    private const KNOTS_TO_KMH = 1.852;

    /** Whether the integration has enough config to talk to a server. */
    public function isConfigured(): bool
    {
        $url = (string) config('services.traccar.url');
        if ($url === '') {
            return false;
        }

        return (bool) config('services.traccar.token')
            || (config('services.traccar.email') && config('services.traccar.password'));
    }

    /**
     * Lightweight readiness probe (auth + reachability). Returns a status array,
     * never throws. Use for an admin "connection" check.
     */
    public function status(): array
    {
        if (! $this->isConfigured()) {
            return ['configured' => false, 'connected' => false, 'message' => 'Traccar is not configured.'];
        }

        try {
            $resp = $this->request()->get($this->endpoint('/session'));

            if ($resp->successful()) {
                return [
                    'configured' => true,
                    'connected'  => true,
                    'server'     => config('services.traccar.url'),
                    'user'       => $resp->json('name') ?? $resp->json('email'),
                    'message'    => 'Connected.',
                ];
            }

            return [
                'configured' => true,
                'connected'  => false,
                'message'    => 'Authentication failed (HTTP ' . $resp->status() . ').',
            ];
        } catch (\Throwable $e) {
            Log::warning('Traccar status check failed', ['error' => $e->getMessage()]);
            return ['configured' => true, 'connected' => false, 'message' => 'Traccar server unreachable.'];
        }
    }

    /**
     * All devices the account can see, each merged with its latest position.
     *
     * @return array{devices: array}|array{error: string}
     */
    public function devicesWithPositions(): array
    {
        $devices = $this->get('/devices');
        if (isset($devices['error'])) {
            return $devices;
        }

        $positions = $this->get('/positions');
        $byDevice  = collect(is_array($positions) ? $positions : [])
            ->keyBy('deviceId');

        $shaped = collect($devices)->map(function ($d) use ($byDevice) {
            $pos = $byDevice->get($d['id'] ?? null);
            return $this->shapeDevice($d, $pos);
        })->values()->all();

        return ['devices' => $shaped];
    }

    /** A single device merged with its latest position, or ['error'=>…]. */
    public function device(int $deviceId): array
    {
        $devices = $this->get('/devices', ['id' => $deviceId]);
        if (isset($devices['error'])) {
            return $devices;
        }

        $device = collect($devices)->first();
        if (! $device) {
            return ['error' => 'Device not found.'];
        }

        $positions = $this->get('/positions', ['deviceId' => $deviceId]);
        $pos       = collect(is_array($positions) ? $positions : [])->first();

        return ['device' => $this->shapeDevice($device, $pos)];
    }

    /** Latest position only for a device (shaped), or null/error. */
    public function latestPosition(int $deviceId): array
    {
        $positions = $this->get('/positions', ['deviceId' => $deviceId]);
        if (isset($positions['error'])) {
            return $positions;
        }

        $pos = collect(is_array($positions) ? $positions : [])->first();
        return ['position' => $pos ? $this->shapePosition($pos) : null];
    }

    /**
     * Historical route (ordered position points) for a device over a window.
     *
     * @return array{route: array}|array{error: string}
     */
    public function route(int $deviceId, ?string $from = null, ?string $to = null): array
    {
        [$from, $to] = $this->window($from, $to);

        $positions = $this->get('/reports/route', [
            'deviceId' => $deviceId,
            'from'     => $from,
            'to'       => $to,
        ]);
        if (isset($positions['error'])) {
            return $positions;
        }

        $route = collect(is_array($positions) ? $positions : [])
            ->map(fn ($p) => $this->shapePosition($p))
            ->all();

        return ['route' => $route, 'from' => $from, 'to' => $to];
    }

    /**
     * Trip summaries for a device over a window.
     *
     * @return array{trips: array}|array{error: string}
     */
    public function trips(int $deviceId, ?string $from = null, ?string $to = null): array
    {
        [$from, $to] = $this->window($from, $to);

        $trips = $this->get('/reports/trips', [
            'deviceId' => $deviceId,
            'from'     => $from,
            'to'       => $to,
        ]);
        if (isset($trips['error'])) {
            return $trips;
        }

        $shaped = collect(is_array($trips) ? $trips : [])->map(fn ($t) => [
            'start_time'    => $t['startTime'] ?? null,
            'end_time'      => $t['endTime'] ?? null,
            'start_address' => $t['startAddress'] ?? null,
            'end_address'   => $t['endAddress'] ?? null,
            'start_lat'     => $t['startLat'] ?? null,
            'start_lon'     => $t['startLon'] ?? null,
            'end_lat'       => $t['endLat'] ?? null,
            'end_lon'       => $t['endLon'] ?? null,
            'distance_km'   => isset($t['distance']) ? round($t['distance'] / 1000, 2) : null,
            'avg_speed_kmh' => isset($t['averageSpeed']) ? round($t['averageSpeed'] * self::KNOTS_TO_KMH, 1) : null,
            'max_speed_kmh' => isset($t['maxSpeed']) ? round($t['maxSpeed'] * self::KNOTS_TO_KMH, 1) : null,
            'duration_ms'   => $t['duration'] ?? null,
        ])->all();

        return ['trips' => $shaped, 'from' => $from, 'to' => $to];
    }

    /**
     * Geofence zones (id, name, description, WKT area).
     *
     * @return array{geofences: array}|array{error: string}
     */
    public function geofences(): array
    {
        $geofences = $this->get('/geofences');
        if (isset($geofences['error'])) {
            return $geofences;
        }

        $shaped = collect(is_array($geofences) ? $geofences : [])->map(fn ($g) => [
            'id'          => $g['id'] ?? null,
            'name'        => $g['name'] ?? null,
            'description' => $g['description'] ?? null,
            'area'        => $g['area'] ?? null, // WKT (CIRCLE/POLYGON/LINESTRING)
        ])->all();

        return ['geofences' => $shaped];
    }

    // -------------------------------------------------------------------------
    // Shaping
    // -------------------------------------------------------------------------

    private function shapeDevice(array $d, ?array $pos): array
    {
        return [
            'id'          => $d['id'] ?? null,
            'name'        => $d['name'] ?? null,
            'unique_id'   => $d['uniqueId'] ?? null,
            'status'      => $d['status'] ?? 'unknown', // online | offline | unknown
            'disabled'    => (bool) ($d['disabled'] ?? false),
            'category'    => $d['category'] ?? null,
            'last_update' => $d['lastUpdate'] ?? null,
            'position'    => $pos ? $this->shapePosition($pos) : null,
        ];
    }

    private function shapePosition(array $p): array
    {
        return [
            'latitude'  => $p['latitude'] ?? null,
            'longitude' => $p['longitude'] ?? null,
            'altitude'  => $p['altitude'] ?? null,
            'speed_kmh' => isset($p['speed']) ? round($p['speed'] * self::KNOTS_TO_KMH, 1) : null,
            'course'    => $p['course'] ?? null,
            'address'   => $p['address'] ?? null,
            'fix_time'  => $p['fixTime'] ?? ($p['deviceTime'] ?? null),
            'valid'     => (bool) ($p['valid'] ?? false),
        ];
    }

    // -------------------------------------------------------------------------
    // HTTP plumbing
    // -------------------------------------------------------------------------

    /** GET an endpoint; returns decoded JSON array or ['error'=>…]. */
    private function get(string $path, array $query = []): array
    {
        if (! $this->isConfigured()) {
            return ['error' => 'Traccar is not configured.'];
        }

        try {
            $resp = $this->request()->get($this->endpoint($path), $query);

            if (! $resp->successful()) {
                Log::warning('Traccar request failed', [
                    'path'   => $path,
                    'status' => $resp->status(),
                ]);
                return ['error' => 'Traccar request failed (HTTP ' . $resp->status() . ').'];
            }

            return $resp->json() ?? [];
        } catch (\Throwable $e) {
            Log::warning('Traccar request error', ['path' => $path, 'error' => $e->getMessage()]);
            return ['error' => 'Traccar server unreachable.'];
        }
    }

    private function request(): PendingRequest
    {
        $req = Http::acceptJson()->timeout((int) config('services.traccar.timeout', 15));

        if ($token = config('services.traccar.token')) {
            return $req->withToken($token);
        }

        return $req->withBasicAuth(
            (string) config('services.traccar.email'),
            (string) config('services.traccar.password'),
        );
    }

    private function endpoint(string $path): string
    {
        return config('services.traccar.url') . '/api' . $path;
    }

    /** Default the report window to the last 24h when not supplied. */
    private function window(?string $from, ?string $to): array
    {
        $to   = $to ?: Carbon::now()->toIso8601String();
        $from = $from ?: Carbon::now()->subDay()->toIso8601String();

        return [$from, $to];
    }
}
