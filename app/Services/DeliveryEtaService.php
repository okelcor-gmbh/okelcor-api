<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * Delivery ETA estimate from live GPS position + speed.
 *
 * Deliberately a straight-line estimate (great-circle distance × a road factor)
 * divided by an effective speed — instant, no routing API. It is an HONEST rough
 * ETA, not a traffic-aware drive time; the frontend renders a live "Xd Yh left"
 * countdown from the returned `eta` timestamp.
 */
class DeliveryEtaService
{
    private const EARTH_RADIUS_KM = 6371.0;

    /** Great-circle distance between two points in km. */
    public function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Estimate remaining distance + time from current position to destination.
     *
     * @param  float|null  $avgSpeedKmh  Recent moving average; falls back to the
     *                                   configured cruising speed when missing/stationary.
     * @return array{distance_remaining_km: float, minutes_remaining: int|null, eta: string|null, speed_kmh_used: float}
     */
    public function estimate(
        float $curLat,
        float $curLon,
        float $destLat,
        float $destLon,
        ?float $avgSpeedKmh = null
    ): array {
        $roadFactor = (float) config('services.traccar.road_factor', 1.3);
        $roadKm     = round($this->haversineKm($curLat, $curLon, $destLat, $destLon) * $roadFactor, 2);

        // Use the moving average only if it's a sane cruising speed; otherwise the
        // configured default (avoids "infinite ETA" when the vehicle is stopped).
        $speed = ($avgSpeedKmh !== null && $avgSpeedKmh > 5)
            ? $avgSpeedKmh
            : (float) config('services.traccar.default_speed_kmh', 60);

        $minutes = $speed > 0 ? (int) round(($roadKm / $speed) * 60) : null;
        $eta     = $minutes !== null ? Carbon::now()->addMinutes($minutes)->toIso8601String() : null;

        return [
            'distance_remaining_km' => $roadKm,
            'minutes_remaining'     => $minutes,
            'eta'                   => $eta,
            'speed_kmh_used'        => round($speed, 1),
        ];
    }
}
