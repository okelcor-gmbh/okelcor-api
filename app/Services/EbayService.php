<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class EbayService
{
    private function isSandbox(): bool
    {
        return config('services.ebay.environment') !== 'production';
    }

    private function oauthBaseUrl(): string
    {
        return $this->isSandbox()
            ? 'https://api.sandbox.ebay.com/identity/v1/oauth2/token'
            : 'https://api.ebay.com/identity/v1/oauth2/token';
    }

    private function browseBaseUrl(): string
    {
        return $this->isSandbox()
            ? 'https://api.sandbox.ebay.com/buy/browse/v1/item_summary/search'
            : 'https://api.ebay.com/buy/browse/v1/item_summary/search';
    }

    public function getAccessToken(): string
    {
        $cacheKey = 'ebay_access_token_' . config('services.ebay.environment');

        return Cache::remember($cacheKey, 7000, function () {
            $response = Http::asForm()
                ->withBasicAuth(
                    config('services.ebay.client_id'),
                    config('services.ebay.client_secret')
                )
                ->post($this->oauthBaseUrl(), [
                    'grant_type' => 'client_credentials',
                    'scope'      => 'https://api.ebay.com/oauth/api_scope',
                ]);

            if (! $response->ok()) {
                throw new \RuntimeException('eBay OAuth failed: ' . $response->body());
            }

            return $response->json('access_token');
        });
    }

    public function searchTyres(string $query, int $limit = 20, ?string $tyreType = null): array
    {
        $token = $this->getAccessToken();

        // Strip overly specific tyre spec suffixes — keep brand + size only
        $searchQuery = $this->simplifyTyreQuery($query, $tyreType);

        $response = Http::withToken($token)
            ->withHeaders(['X-EBAY-C-MARKETPLACE-ID' => 'EBAY_DE'])
            ->get($this->browseBaseUrl(), [
                'q'     => $searchQuery,
                'limit' => min($limit, 50),
            ]);

        if (! $response->ok()) {
            throw new \RuntimeException(
                'eBay Browse API error ' . $response->status() . ': ' . $response->body()
            );
        }

        $items = $response->json('itemSummaries') ?? [];

        return array_map(fn ($item) => [
            'title'              => $item['title'] ?? null,
            'price'              => $item['price']['value'] ?? null,
            'currency'           => $item['price']['currency'] ?? null,
            'condition'          => $item['condition'] ?? null,
            'seller'             => $item['seller']['username'] ?? null,
            'url'                => $item['itemWebUrl'] ?? null,
            'image'              => $item['image']['imageUrl'] ?? null,
            'quantity_available' => $item['estimatedAvailabilities'][0]['estimatedAvailableQuantity'] ?? null,
        ], $items);
    }

    /**
     * Extract "BRAND SIZE" from a full product name for use as an eBay
     * search query. Okelcor sells four tyre types (see Product::type —
     * pcr/tbr/used/otr) whose sizes are written in genuinely different
     * notations, so a single regex tuned for passenger-car tyres silently
     * failed to extract a size for TBR (truck/bus, e.g. "295/80R22.5" —
     * note the decimal rim) and OTR (off-the-road, e.g. "23.5R25" or
     * "20.5-25" — no three-digit width segment at all). Examples:
     *   PCR/TBR: "YOKOHAMA 225/45R 18 95Y Tl Ad.Sp.V-105 Mo Summer"
     *   OTR:      "BRIDGESTONE 23.5R25 VSDL Loader Tyre"
     */
    private function simplifyTyreQuery(string $query, ?string $tyreType = null): string
    {
        $size = $this->extractSize($query, $tyreType);

        // Extract brand — first ALL-CAPS word (2+ chars) in the string
        preg_match('/\b([A-Z]{2,}[A-Z0-9\-]*)\b/', $query, $brandMatch);
        $brand = $brandMatch[1] ?? '';

        if ($brand && $size) {
            return "$brand $size";
        }

        return mb_substr($query, 0, 60);
    }

    /**
     * Tries the passenger/truck size pattern first (the large majority of
     * Okelcor's catalogue — PCR, TBR, and Used are all sized this way),
     * then falls back to the OTR pattern. When $tyreType is known to be
     * 'otr', the order is reversed so an OTR size isn't misread by the
     * PCR/TBR pattern first (e.g. "20.5R25" could otherwise partially
     * match as width "205").
     */
    private function extractSize(string $query, ?string $tyreType): ?string
    {
        // Normalise: collapse "225/45R 18" → "225/45R18"
        $normalized = preg_replace('/(\d{3}\/\d{2,3}R)\s*(\d{2}(?:\.\d)?)/', '$1$2', $query);
        // Normalise: collapse "23.5 R 25" / "23.5 - 25" → "23.5R25"
        $normalized = preg_replace('/(\d{1,2}\.\d)\s*[R\-]\s*(\d{2})/', '$1R$2', $normalized);

        $pcrTbrPattern = '/\d{3}\/\d{2,3}R\d{2}(?:\.\d)?/';
        $otrPattern    = '/\d{1,2}\.\dR\d{2}/';

        $patterns = $tyreType === 'otr'
            ? [$otrPattern, $pcrTbrPattern]
            : [$pcrTbrPattern, $otrPattern];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized, $match)) {
                return $match[0];
            }
        }

        return null;
    }
}
