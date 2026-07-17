<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Today's EUR/USD rate for the admin order-currency conversion feature —
 * a genuine conversion (AdminOrderController::convertOrderCurrency), not a
 * display relabel. Uses Frankfurter (ECB-sourced, free, no API key).
 *
 * Deliberately does not degrade to a fallback/stale rate on failure — this
 * changes real money on a real order, so a failed lookup must fail the
 * whole conversion request loudly rather than silently applying a wrong
 * number. Rate is cached for the remainder of the calendar day (Frankfurter
 * itself only publishes once daily) so repeated conversions on the same
 * day don't hit the API again.
 */
class CurrencyConversionService
{
    /**
     * @return array{rate: float, date: string}
     */
    public function getRate(string $from, string $to): array
    {
        if (strtoupper($from) === strtoupper($to)) {
            return ['rate' => 1.0, 'date' => now()->toDateString()];
        }

        $cacheKey = 'fx_rate_' . strtoupper($from) . '_' . strtoupper($to) . '_' . now()->toDateString();

        return Cache::remember($cacheKey, now()->endOfDay(), function () use ($from, $to) {
            $response = Http::timeout(10)->get(rtrim(config('services.frankfurter.base_url'), '/') . '/latest', [
                'from' => strtoupper($from),
                'to'   => strtoupper($to),
            ]);

            $rate = $response->json('rates.' . strtoupper($to));

            if (! $response->ok() || $rate === null) {
                throw new \RuntimeException(
                    "Exchange rate lookup failed for {$from}->{$to}: " . $response->status() . ' ' . $response->body()
                );
            }

            return [
                'rate' => (float) $rate,
                'date' => (string) $response->json('date'),
            ];
        });
    }
}
