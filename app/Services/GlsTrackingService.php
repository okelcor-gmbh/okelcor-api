<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GLS parcel Track & Trace client — ShipIT-Farm API v1 (GLS Group Developer
 * Portal, api.gls-group.net).
 *
 * Both confirmed directly from the account's own portal ("Try this API" panels):
 *
 *   Token exchange (Authentication API v2), standard OAuth2 client-credentials:
 *     POST {token_endpoint}                      e.g. https://api-sandbox.gls-group.net/oauth2/v2/token
 *     Authorization: Basic base64(api_key:api_secret)
 *     Content-Type: application/x-www-form-urlencoded
 *     Body: grant_type=client_credentials
 *
 *   Tracking:
 *     POST {tracking_endpoint}                   https://api.gls-group.net/shipit-farm/v1/backend/rs/tracking/parceldetails
 *     Authorization: Bearer {access_token}
 *     Accept: application/json
 *     Content-Type: application/glsVersion1+json  (GLS's own custom media type)
 *
 * App ID + API Key + API Secret are issued together per registered app — no
 * separate "customer ID". The Basic Auth credential pair is api_key:api_secret
 * (standard OAuth2 client_id:client_secret convention) — App ID isn't used at
 * runtime, only for the portal/sales-approval side per GLS's own docs.
 *
 * ⚠️ STILL UNVERIFIED (the portal's tracking "Try it" panel returned "Unknown
 * Error" with no Authorization header set — consistent with this theory, but
 * not yet proven; run the token exchange for a real access_token, then retry
 * `parceldetails` with `Authorization: Bearer <token>` and share the result):
 *   - Exact token response field name (`access_token` assumed — standard OAuth2).
 *   - Exact request body field names for `parceldetails` — GLS's public docs
 *     for the equivalent legacy endpoint show DateFrom/DateTo (mandatory) +
 *     ParcelNumber (identifier); the portal's own curl example only showed
 *     `{"ParcelNumber": "..."}` with no dates, so DateFrom/DateTo are omitted
 *     below to match what's actually confirmed working in the portal.
 *   - Whether the response includes a status/event-history field at all, or
 *     only static shipment attributes (weight/product/addresses) — GLS's
 *     public docs for the equivalent endpoint suggest the latter, which
 *     would mean this endpoint can't power a status timeline. Degrades
 *     safely to an empty events list either way, but flag this back once
 *     confirmed — a different GLS product might be needed for full history.
 */
class GlsTrackingService
{
    public function isConfigured(): bool
    {
        return (bool) config('services.gls.app_id')
            && (bool) config('services.gls.api_key')
            && (bool) config('services.gls.api_secret')
            && (bool) config('services.gls.token_endpoint')
            && (bool) config('services.gls.tracking_endpoint');
    }

    public function track(string $trackingNumber): array
    {
        if (! $this->isConfigured()) {
            return ['error' => 'GLS tracking not configured'];
        }

        $token = $this->accessToken();
        if (! $token) {
            return ['error' => 'GLS tracking unavailable'];
        }

        try {
            $response = Http::withToken($token)
                ->withHeaders([
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/glsVersion1+json',
                ])
                ->post(config('services.gls.tracking_endpoint'), [
                    'ParcelNumber' => $trackingNumber,
                ]);

            Log::info('GLS tracking response', [
                'status' => $response->status(),
                'body'   => $response->json(),
            ]);

            if (! $response->ok()) {
                return ['error' => 'GLS tracking unavailable'];
            }

            $data = $response->json();
            $unit = $data['UnitDetail'] ?? ($data['UnitItems'][0] ?? []);
            // Best-effort — history field name unconfirmed; several plausible
            // shapes checked so a real response has a decent chance of matching.
            $states = $unit['History'] ?? $unit['Events'] ?? $unit['history'] ?? [];

            return [
                'status' => $unit['Status'] ?? null,
                'events' => collect($states)->map(fn ($e) => [
                    'timestamp'   => $e['Timestamp'] ?? $e['Date'] ?? $e['timestamp'] ?? null,
                    'description' => $e['Description'] ?? $e['Status'] ?? $e['description'] ?? null,
                    'location'    => $e['Location'] ?? $e['City'] ?? $e['location'] ?? null,
                ])->toArray(),
            ];
        } catch (\Throwable) {
            return ['error' => 'GLS tracking unavailable'];
        }
    }

    /**
     * OAuth2 client-credentials token exchange — confirmed shape from the
     * portal's own "Try this API" panel for Authentication API v2: HTTP Basic
     * Auth (api_key:api_secret) + form-encoded body, not a JSON body. Cached
     * for its lifetime (minus a safety buffer); only caches on success so a
     * failed exchange retries next call rather than sitting dead for the TTL.
     */
    private function accessToken(): ?string
    {
        $cached = Cache::get('gls_api_access_token');
        if ($cached) {
            return $cached;
        }

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->withBasicAuth(config('services.gls.api_key'), config('services.gls.api_secret'))
                ->post(config('services.gls.token_endpoint'), [
                    'grant_type' => 'client_credentials',
                ]);

            if (! $response->ok()) {
                Log::warning('GLS token exchange failed', ['status' => $response->status(), 'body' => $response->body()]);
                return null;
            }

            $token = $response->json('access_token');

            if ($token) {
                Cache::put('gls_api_access_token', $token, 3300);
            }

            return $token;
        } catch (\Throwable $e) {
            Log::warning('GLS token exchange threw', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
