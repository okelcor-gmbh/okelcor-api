<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GLS parcel Track & Trace client — Track And Trace API v1 (GLS Group
 * Developer Portal, api-sandbox.gls-group.net).
 *
 * Both confirmed directly from the account's own portal ("Try this API"
 * panels + published schema docs — not guessed):
 *
 *   Token exchange (Authentication API v2), OAuth2 client-credentials:
 *     POST {token_endpoint}   https://api-sandbox.gls-group.net/oauth2/v2/token
 *     Authorization: Basic base64(api_key:api_secret)
 *     Content-Type: application/x-www-form-urlencoded
 *     Body: grant_type=client_credentials
 *
 *   Tracking:
 *     GET {tracking_endpoint}/{trackingNumber}?showEvents=true
 *       e.g. https://api-sandbox.gls-group.net/track-and-trace-v1/tracking/simple/trackids/{unitno}
 *     Authorization: Bearer {access_token}
 *     Accept: application/json
 *
 *   EventDTO (confirmed schema, per-event fields):
 *     code           — event type code, e.g. "DELIVD.PARCELSHOP"
 *     city           — e.g. "Berlin"
 *     postalCode     — e.g. "10437"
 *     country        — ISO 3166-1 alpha-2, e.g. "DE"
 *     description    — human-readable, in the request language (Accept-Language)
 *     eventDateTime  — ISO 8601, e.g. "2024-10-07T10:46:14+0200"
 *
 * App ID + API Key + API Secret are issued together per registered app — no
 * separate "customer ID"; App ID isn't used at runtime, only for the portal/
 * sales-approval side per GLS's own docs. The Basic Auth pair is
 * api_key:api_secret (standard OAuth2 client_id:client_secret convention).
 *
 * There's a sibling endpoint, `/tracking/simple/references/{reference}`,
 * keyed by shipment reference instead of GLS's own tracking number — not
 * used here since we always have the GLS tracking number once assigned.
 *
 * ⚠️ SANDBOX vs PRODUCTION: every confirmed portal panel for this account
 * defaults to the `api-sandbox.gls-group.net` host. GLS gates production API
 * access behind a separate approval step ("Prod requirements" noted on every
 * endpoint page) that hasn't been completed. Sandbox may return test/dummy
 * data rather than real parcel status — verify with a real tracking number
 * whether the response looks live before trusting this for real customers.
 * Swap `GLS_API_BASE_URL`/`GLS_API_TOKEN_ENDPOINT` to the `api.gls-group.net`
 * host once production access is granted — no other code change needed.
 *
 * ⚠️ Still not confirmed live end-to-end — the outer response envelope
 * (which field holds the parcel/status list before `events`) is inferred
 * defensively below; the EventDTO fields inside each event ARE from GLS's
 * published schema and authoritative.
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
            $url = rtrim(config('services.gls.tracking_endpoint'), '/') . '/' . rawurlencode($trackingNumber);

            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(10)
                ->get($url, [
                    'showEvents' => 'true',
                ]);

            Log::info('GLS tracking response', [
                'status' => $response->status(),
                'body'   => $response->json(),
            ]);

            if (! $response->ok()) {
                return ['error' => 'GLS tracking unavailable'];
            }

            $data = $response->json();

            // Envelope shape not confirmed live yet — the API docs describe a
            // multi-parcel-capable response (up to 10 IDs per request), so
            // this is most likely a list; handle both a bare list and a
            // wrapped object defensively. EventDTO fields inside are exact.
            $parcels = match (true) {
                isset($data[0])              => $data,
                isset($data['parcels'])      => $data['parcels'],
                isset($data['trackingInfo']) => $data['trackingInfo'],
                default                      => [$data],
            };
            $parcel = $parcels[0] ?? [];
            $events = $parcel['events'] ?? $data['events'] ?? [];

            return [
                'status' => $parcel['status'] ?? $parcel['code'] ?? null,
                'events' => collect($events)->map(fn ($e) => [
                    'timestamp'   => $e['eventDateTime'] ?? null,
                    'description' => $e['description'] ?? $e['code'] ?? null,
                    'location'    => trim(($e['city'] ?? '') . ($e['postalCode'] ? ', ' . $e['postalCode'] : '')) ?: null,
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
                ->timeout(10)
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
