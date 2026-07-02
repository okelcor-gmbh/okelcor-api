<?php

namespace App\Services;

use App\Models\EbayToken;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class EbaySellingService
{
    /** Tracks which token path was used on the most recent getAccessToken() call. */
    private string $tokenSource = 'unknown';

    private const SCOPES = [
        'https://api.ebay.com/oauth/api_scope/sell.inventory',
        'https://api.ebay.com/oauth/api_scope/sell.inventory.readonly',
        'https://api.ebay.com/oauth/api_scope/sell.account.readonly',
        'https://api.ebay.com/oauth/api_scope/sell.fulfillment',
        'https://api.ebay.com/oauth/api_scope/sell.fulfillment.readonly',
    ];

    // -------------------------------------------------------------------------
    // Environment helpers
    // -------------------------------------------------------------------------

    private function isSandbox(): bool
    {
        return config('services.ebay.environment') !== 'production';
    }

    private function oauthUrl(): string
    {
        return $this->isSandbox()
            ? 'https://api.sandbox.ebay.com/identity/v1/oauth2/token'
            : 'https://api.ebay.com/identity/v1/oauth2/token';
    }

    private function inventoryBaseUrl(): string
    {
        return $this->isSandbox()
            ? 'https://api.sandbox.ebay.com/sell/inventory/v1'
            : 'https://api.ebay.com/sell/inventory/v1';
    }

    private function authBaseUrl(): string
    {
        return $this->isSandbox()
            ? 'https://auth.sandbox.ebay.com/oauth2/authorize'
            : 'https://auth.ebay.com/oauth2/authorize';
    }

    private function accountBaseUrl(): string
    {
        return $this->isSandbox()
            ? 'https://api.sandbox.ebay.com/sell/account/v1'
            : 'https://api.ebay.com/sell/account/v1';
    }

    private function fulfillmentBaseUrl(): string
    {
        return $this->isSandbox()
            ? 'https://api.sandbox.ebay.com/sell/fulfillment/v1'
            : 'https://api.ebay.com/sell/fulfillment/v1';
    }

    private function cacheKey(): string
    {
        return 'ebay_sell_user_token_' . config('services.ebay.environment');
    }

    // -------------------------------------------------------------------------
    // OAuth — access token via refresh_token
    //
    // Priority order:
    //   1. Laravel Cache (hot path — avoids DB + HTTP on every API call)
    //   2. Active ebay_tokens DB record (persists across deployments, rotates)
    //   3. EBAY_REFRESH_TOKEN env var (backward-compat fallback only)
    // -------------------------------------------------------------------------

    public function getAccessToken(): string
    {
        if ($cached = Cache::get($this->cacheKey())) {
            $this->tokenSource = 'cache';
            return $cached;
        }

        $record = EbayToken::active()->latest()->first();

        if ($record) {
            $this->tokenSource = 'db_token_' . $record->id;
            return $this->callRefreshGrant($record->refresh_token, $record);
        }

        // Backward-compat: .env fallback for pre-EB-1 setups
        $envToken = config('services.ebay_sell.refresh_token');

        if (empty($envToken)) {
            $this->tokenSource = 'none';
            throw new \RuntimeException(
                'eBay seller account is not connected. ' .
                'Visit GET /api/v1/admin/ebay/auth-url to authorise the seller account.'
            );
        }

        $this->tokenSource = 'env_fallback';
        Log::warning('eBay: using .env EBAY_REFRESH_TOKEN fallback — reconnect via OAuth to migrate tokens to DB.');

        return $this->callRefreshGrant($envToken, null);
    }

    /**
     * Call eBay's token endpoint using a refresh_token grant.
     * If a DB record is provided, persists the new access_token (and any rotated
     * refresh_token) back to the database and updates last_refreshed_at.
     */
    private function callRefreshGrant(string $refreshToken, ?EbayToken $record): string
    {
        // Use the scopes that were originally authorized (stored on the token record).
        // Requesting scopes beyond what was originally granted causes eBay to reject the refresh.
        // New connections (via auth-url) get the full SCOPES list; existing tokens keep their own.
        $scopes = ($record && ! empty($record->scopes)) ? $record->scopes : self::SCOPES;

        $response = Http::asForm()
            ->withBasicAuth(
                config('services.ebay_sell.client_id'),
                config('services.ebay_sell.client_secret')
            )
            ->post($this->oauthUrl(), [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refreshToken,
                'scope'         => implode(' ', $scopes),
            ]);

        if (! $response->ok()) {
            $parsedErrors = $this->parseEbayErrors($response->body());

            Log::error('eBay token refresh failed.', [
                'action'       => 'ebay_token_refresh_failed',
                'api_family'   => 'Sell API (REST) — OAuth token endpoint',
                'endpoint'     => $this->oauthUrl(),
                'token_source' => $this->tokenSource,
                'http_status'  => $response->status(),
                'ebay_errors'  => $parsedErrors,
            ]);

            throw new \RuntimeException('eBay user token refresh failed: ' . $response->body());
        }

        $data        = $response->json();
        $accessToken = $data['access_token'];
        $expiresIn   = (int) ($data['expires_in'] ?? 7200);
        $cacheTtl    = max(60, $expiresIn - 60); // 60s buffer before expiry

        Cache::put($this->cacheKey(), $accessToken, $cacheTtl);

        if ($record) {
            $updates = [
                'access_token'            => $accessToken,
                'access_token_expires_at' => now()->addSeconds($expiresIn),
                'last_refreshed_at'       => now(),
            ];

            // eBay rotates refresh_token — persist the new one so future refreshes work
            if (! empty($data['refresh_token']) && $data['refresh_token'] !== $refreshToken) {
                $updates['refresh_token'] = $data['refresh_token'];
                Log::info('eBay: refresh_token rotated and persisted.', [
                    'action'         => 'ebay_token_refreshed',
                    'token_id'       => $record->id,
                    'marketplace_id' => $record->marketplace_id,
                ]);
            } else {
                Log::info('eBay: access_token refreshed.', [
                    'action'         => 'ebay_token_refreshed',
                    'token_id'       => $record->id,
                    'marketplace_id' => $record->marketplace_id,
                ]);
            }

            $record->update($updates);
        }

        return $accessToken;
    }

    // -------------------------------------------------------------------------
    // OAuth consent URL — controller generates the state, passes it here
    // -------------------------------------------------------------------------

    public function buildAuthUrl(string $state): string
    {
        return $this->authBaseUrl() . '?' . http_build_query([
            'client_id'     => config('services.ebay_sell.client_id'),
            'redirect_uri'  => config('services.ebay_sell.ru_name'),
            'response_type' => 'code',
            'scope'         => implode(' ', self::SCOPES),
            'state'         => $state,
        ]);
    }

    // -------------------------------------------------------------------------
    // Exchange authorization code for tokens (called by callback handler)
    // Deactivates any existing active token before creating the new one.
    // -------------------------------------------------------------------------

    public function exchangeCodeForTokens(string $code): EbayToken
    {
        $response = Http::asForm()
            ->withBasicAuth(
                config('services.ebay_sell.client_id'),
                config('services.ebay_sell.client_secret')
            )
            ->post($this->oauthUrl(), [
                'grant_type'   => 'authorization_code',
                'code'         => $code,
                'redirect_uri' => config('services.ebay_sell.ru_name'),
            ]);

        if (! $response->ok()) {
            Log::error('eBay authorization code exchange failed.', [
                'action' => 'ebay_token_refresh_failed',
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            throw new \RuntimeException('eBay code exchange failed: ' . $response->body());
        }

        $data = $response->json();

        if (empty($data['refresh_token'])) {
            throw new \RuntimeException('eBay token response did not include a refresh_token.');
        }

        // Deactivate all existing active tokens before storing the new one
        EbayToken::where('is_active', true)->update(['is_active' => false]);

        // Clear any stale cached access token
        Cache::forget($this->cacheKey());

        $expiresIn        = (int) ($data['expires_in'] ?? 7200);
        $rtExpiresIn      = (int) ($data['refresh_token_expires_in'] ?? 47304000); // eBay default ~18 months
        $marketplaceId    = config('services.ebay_sell.marketplace_id', 'EBAY_DE');

        $token = EbayToken::create([
            'marketplace_id'           => $marketplaceId,
            'access_token'             => $data['access_token'] ?? null,
            'refresh_token'            => $data['refresh_token'],
            'access_token_expires_at'  => now()->addSeconds($expiresIn),
            'refresh_token_expires_at' => now()->addSeconds($rtExpiresIn),
            'scopes'                   => self::SCOPES,
            'connected_at'             => now(),
            'is_active'                => true,
        ]);

        // Prime the cache so the first listing call after connect is instant
        if (! empty($data['access_token'])) {
            Cache::put($this->cacheKey(), $data['access_token'], max(60, $expiresIn - 60));
        }

        return $token;
    }

    // -------------------------------------------------------------------------
    // Create or update a listing for a product
    // Returns ['listing_id' => string, 'offer_id' => string]
    // -------------------------------------------------------------------------

    public function createOrUpdateListing(Product $product): array
    {
        $this->guardProduct($product);

        $token = $this->getAccessToken();
        $sku   = $product->sku;

        $this->upsertInventoryItem($product, $token);

        // eBay error 25751 occurs when POST /offer fires before eBay has indexed
        // the newly PUT inventory item. Verify the item is reachable before proceeding.
        $this->waitForInventoryItem($sku, $token);

        // eBay error 25002 (Item.Country) occurs when no merchant location is linked
        // to the offer. Ensure the configured location exists (auto-creates if missing).
        $this->ensureMerchantLocation($token);

        $offerId   = $this->upsertOffer($product, $token);
        $listingId = $this->publishOffer($offerId, $token);

        Log::info("eBay listing published: SKU {$sku} → listingId {$listingId}, offerId {$offerId}", [
            'api_family'   => 'Sell API (REST)',
            'token_source' => $this->tokenSource,
            'sku'          => $sku,
        ]);

        return ['listing_id' => $listingId, 'offer_id' => $offerId];
    }

    // -------------------------------------------------------------------------
    // Fetch current listing status from eBay for a given SKU.
    // Returns ['status' => string, 'offer_id' => string|null]
    // Status values: active | draft | ended | unknown
    // -------------------------------------------------------------------------

    public function getListingStatus(string $sku): array
    {
        $token = $this->getAccessToken();

        $response = Http::withToken($token)
            ->withHeaders($this->commonHeaders())
            ->get("{$this->inventoryBaseUrl()}/offer", ['sku' => $sku]);

        if (! $response->ok()) {
            $this->logEbayApiError(
                'get_listing_status',
                "{$this->inventoryBaseUrl()}/offer",
                $response->status(),
                $response->body(),
                ['sku' => $sku]
            );

            throw new \RuntimeException(
                "eBay getListingStatus failed for SKU {$sku}: " . $response->body()
            );
        }

        $offers = $response->json('offers') ?? [];

        if (empty($offers)) {
            // Product marked as listed but no offer found on eBay — listing may have ended
            return ['status' => 'ended', 'offer_id' => null];
        }

        $offer       = $offers[0];
        $offerId     = $offer['offerId'] ?? null;
        $offerStatus = strtoupper($offer['status'] ?? '');

        $status = match ($offerStatus) {
            'PUBLISHED'   => 'active',
            'UNPUBLISHED' => 'draft',
            default       => 'unknown',
        };

        return ['status' => $status, 'offer_id' => $offerId];
    }

    // -------------------------------------------------------------------------
    // Sync stock quantity only — no title/price changes
    // -------------------------------------------------------------------------

    public function syncInventory(Product $product): void
    {
        if (! $product->ebay_listed) {
            return;
        }

        $token    = $this->getAccessToken();
        $quantity = max(0, (int) $product->stock);

        $encodedSku = rawurlencode($product->sku);
        $endpoint   = "{$this->inventoryBaseUrl()}/inventory_item/{$encodedSku}";

        $response = Http::withToken($token)
            ->withHeaders($this->commonHeaders())
            ->put($endpoint, [
                'availability' => [
                    'shipToLocationAvailability' => [
                        'quantity' => $quantity,
                    ],
                ],
                // Partial updates require a full body in the Inventory API
                'condition' => 'NEW',
                'product'   => [
                    'title'     => $this->buildTitle($product),
                    'imageUrls' => $this->imageUrls($product),
                    'ean'       => $product->ean ? [$product->ean] : ['Does not apply'],
                ],
            ]);

        // PUT inventory_item returns 204 No Content on update (not 200)
        if (! $response->successful()) {
            $this->logEbayApiError(
                'sync_inventory',
                $endpoint,
                $response->status(),
                $response->body(),
                ['sku' => $product->sku, 'product_id' => $product->id]
            );

            throw new \RuntimeException("eBay syncInventory failed for SKU {$product->sku}: " . $response->body());
        }
    }

    // -------------------------------------------------------------------------
    // Update an existing listing — title, description, price, stock.
    // Strict: requires the product to already be listed (offer must exist on eBay).
    // Does NOT re-publish — updates the offer in place.
    // Returns ['offer_id' => string, 'listing_id' => string]
    // -------------------------------------------------------------------------

    public function updateListing(Product $product): array
    {
        $this->guardProduct($product);

        $token = $this->getAccessToken();
        $sku   = $product->sku;

        $existing = Http::withToken($token)
            ->withHeaders($this->commonHeaders())
            ->get("{$this->inventoryBaseUrl()}/offer", ['sku' => $sku]);

        if (! $existing->ok() || empty($existing->json('offers'))) {
            if ($existing->failed()) {
                $this->logEbayApiError(
                    'update_listing_fetch_offer',
                    "{$this->inventoryBaseUrl()}/offer",
                    $existing->status(),
                    $existing->body(),
                    ['sku' => $sku, 'product_id' => $product->id]
                );
            }

            throw new \RuntimeException(
                "No existing eBay offer found for SKU {$sku}. Use 'List on eBay' to create and publish a listing first."
            );
        }

        $offerId   = $existing->json('offers.0.offerId');
        $listingId = $existing->json('offers.0.listing.listingId') ?? $product->ebay_item_id ?? '';

        $this->upsertInventoryItem($product, $token);

        $response = Http::withToken($token)
            ->withHeaders($this->commonHeaders())
            ->put("{$this->inventoryBaseUrl()}/offer/{$offerId}", $this->buildOfferBody($product));

        // PUT /offer returns 204 No Content on success (not 200)
        if (! $response->successful()) {
            $this->logEbayApiError(
                'update_listing_put_offer',
                "{$this->inventoryBaseUrl()}/offer/{$offerId}",
                $response->status(),
                $response->body(),
                ['sku' => $sku, 'product_id' => $product->id, 'offer_id' => $offerId]
            );

            throw new \RuntimeException("eBay offer update failed for SKU {$sku}: " . $response->body());
        }

        Log::info("eBay listing updated: SKU {$sku} → offerId {$offerId}");

        return ['offer_id' => $offerId, 'listing_id' => (string) $listingId];
    }

    // -------------------------------------------------------------------------
    // Full sync — stock, price, title, description.
    // Permissive: skips if not listed or no offer found on eBay (does not throw).
    // Used by sync-all batch.
    // -------------------------------------------------------------------------

    public function syncFull(Product $product): void
    {
        if (! $product->ebay_listed) {
            return;
        }

        $token = $this->getAccessToken();
        $sku   = $product->sku;

        $this->upsertInventoryItem($product, $token);

        $existing = Http::withToken($token)
            ->withHeaders($this->commonHeaders())
            ->get("{$this->inventoryBaseUrl()}/offer", ['sku' => $sku]);

        if (! $existing->ok() || empty($existing->json('offers'))) {
            Log::info("eBay syncFull: no offer found for SKU {$sku} — inventory updated, offer skipped.");
            return;
        }

        $offerId  = $existing->json('offers.0.offerId');
        $response = Http::withToken($token)
            ->withHeaders($this->commonHeaders())
            ->put("{$this->inventoryBaseUrl()}/offer/{$offerId}", $this->buildOfferBody($product));

        // PUT /offer returns 204 No Content on success (not 200)
        if (! $response->successful()) {
            $this->logEbayApiError(
                'sync_full_put_offer',
                "{$this->inventoryBaseUrl()}/offer/{$offerId}",
                $response->status(),
                $response->body(),
                ['sku' => $sku, 'product_id' => $product->id, 'offer_id' => $offerId]
            );

            throw new \RuntimeException("eBay offer update (syncFull) failed for SKU {$sku}: " . $response->body());
        }
    }

    // -------------------------------------------------------------------------
    // Test connection — calls a lightweight Inventory API endpoint to verify
    // that the stored access token is accepted by eBay.
    // Returns ['message' => string] on success, throws on failure.
    // -------------------------------------------------------------------------

    public function pingConnection(): array
    {
        $token    = $this->getAccessToken();
        $response = Http::withToken($token)
            ->withHeaders($this->commonHeaders())
            ->get("{$this->inventoryBaseUrl()}/inventory_item", ['limit' => 1]);

        if (! $response->ok()) {
            $this->logEbayApiError(
                'ping_connection',
                "{$this->inventoryBaseUrl()}/inventory_item",
                $response->status(),
                $response->body()
            );

            throw new \RuntimeException(
                "eBay connection test failed (HTTP {$response->status()}): " . $response->body()
            );
        }

        return ['message' => 'eBay connection is working. Token is valid and the API is reachable.'];
    }

    // -------------------------------------------------------------------------
    // Sell Fulfillment API — order fetching
    //
    // Requires sell.fulfillment or sell.fulfillment.readonly scope.
    // If the stored token was issued before EB-5, reconnect via auth-url
    // to include the new scopes.
    // -------------------------------------------------------------------------

    /**
     * Fetch all orders from the Sell Fulfillment API.
     * Automatically paginates through all pages (eBay max: 200/page).
     *
     * @param  \Carbon\Carbon|null  $modifiedSince  Filter by lastmodifieddate
     * @return array                                Flat array of eBay order objects
     */
    public function fetchOrders(?\Carbon\Carbon $modifiedSince = null): array
    {
        $token   = $this->getAccessToken();
        $baseUrl = $this->fulfillmentBaseUrl() . '/order';
        $all     = [];
        $offset  = 0;
        $limit   = 200;

        $params = ['limit' => $limit];
        if ($modifiedSince) {
            $params['filter'] = 'lastmodifieddate:[' . $modifiedSince->utc()->toIso8601ZuluString() . '..]';
        }

        do {
            $params['offset'] = $offset;

            $response = Http::withToken($token)
                ->withHeaders($this->commonHeaders())
                ->get($baseUrl, $params);

            if (! $response->ok()) {
                $this->handleFulfillmentApiError('fetch_orders', $baseUrl, $response);
            }

            $data   = $response->json();
            $page   = $data['orders'] ?? [];
            $total  = (int) ($data['total'] ?? 0);
            $all    = array_merge($all, $page);
            $offset += $limit;
        } while ($offset < $total && ! empty($page));

        return $all;
    }

    /**
     * Fetch a single eBay order by its orderId.
     */
    public function fetchOrder(string $ebayOrderId): array
    {
        $token    = $this->getAccessToken();
        $encoded  = rawurlencode($ebayOrderId);
        $endpoint = $this->fulfillmentBaseUrl() . "/order/{$encoded}";

        $response = Http::withToken($token)
            ->withHeaders($this->commonHeaders())
            ->get($endpoint);

        if (! $response->ok()) {
            $this->handleFulfillmentApiError('fetch_order', $endpoint, $response, ['ebay_order_id' => $ebayOrderId]);
        }

        return $response->json();
    }

    /**
     * Fetch the shipping fulfillments (carrier + tracking number) eBay has on
     * file for an order — whatever was used to mark it shipped, whether that
     * happened via our own system or manually in eBay's Seller Hub. Returns
     * `['fulfillments' => [...]]`; empty array if the order has none yet.
     */
    public function fetchShippingFulfillments(string $ebayOrderId): array
    {
        $token    = $this->getAccessToken();
        $encoded  = rawurlencode($ebayOrderId);
        $endpoint = $this->fulfillmentBaseUrl() . "/order/{$encoded}/shipping_fulfillment";

        $response = Http::withToken($token)
            ->withHeaders($this->commonHeaders())
            ->get($endpoint);

        if (! $response->ok()) {
            $this->handleFulfillmentApiError('fetch_shipping_fulfillments', $endpoint, $response, ['ebay_order_id' => $ebayOrderId]);
        }

        return $response->json();
    }

    private function handleFulfillmentApiError(
        string $operation,
        string $endpoint,
        \Illuminate\Http\Client\Response $response,
        array $context = []
    ): never {
        $this->logEbayApiError($operation, $endpoint, $response->status(), $response->body(), $context);

        if ($response->status() === 403) {
            throw new \RuntimeException(
                'eBay Fulfillment API access denied. The connected seller account may not have fulfillment scope. ' .
                'Reconnect via GET /api/v1/admin/ebay/auth-url to reauthorise with updated permissions.'
            );
        }

        throw new \RuntimeException(
            "eBay {$operation} failed (HTTP {$response->status()}): " . $response->body()
        );
    }

    // -------------------------------------------------------------------------
    // Fetch business policies from eBay Account API.
    // Requires sell.account.readonly scope (already in SCOPES).
    // Returns ['payment' => [...], 'fulfillment' => [...], 'return' => [...]]
    // Each item: ['id' => string, 'name' => string]
    // -------------------------------------------------------------------------

    public function fetchPolicies(): array
    {
        $token         = $this->getAccessToken();
        $marketplaceId = config('services.ebay_sell.marketplace_id', 'EBAY_DE');

        $pluck = function (string $endpoint, string $jsonKey, string $idField) use ($token, $marketplaceId): array {
            $response = Http::withToken($token)
                ->withHeaders($this->commonHeaders())
                ->get("{$this->accountBaseUrl()}/{$endpoint}", ['marketplace_id' => $marketplaceId]);

            if (! $response->ok()) {
                Log::warning("eBay fetchPolicies: {$endpoint} request failed.", [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);

                return [];
            }

            return array_map(
                fn($p) => ['id' => $p[$idField] ?? null, 'name' => $p['name'] ?? null],
                $response->json($jsonKey) ?? []
            );
        };

        return [
            'payment'     => $pluck('payment_policy',     'paymentPolicies',     'paymentPolicyId'),
            'fulfillment' => $pluck('fulfillment_policy', 'fulfillmentPolicies', 'fulfillmentPolicyId'),
            'return'      => $pluck('return_policy',      'returnPolicies',      'returnPolicyId'),
        ];
    }

    // -------------------------------------------------------------------------
    // Delete listing and inventory item
    // -------------------------------------------------------------------------

    public function deleteListing(string $sku): void
    {
        $token = $this->getAccessToken();

        // Withdraw any active offer for this SKU first
        $offersResponse = Http::withToken($token)
            ->withHeaders($this->commonHeaders())
            ->get("{$this->inventoryBaseUrl()}/offer", ['sku' => $sku]);

        if ($offersResponse->ok()) {
            foreach ($offersResponse->json('offers') ?? [] as $offer) {
                $offerId = $offer['offerId'];
                Http::withToken($token)
                    ->withHeaders($this->commonHeaders())
                    ->delete("{$this->inventoryBaseUrl()}/offer/{$offerId}");
            }
        }

        // Delete the inventory item
        $encodedSku     = rawurlencode($sku);
        $deleteResponse = Http::withToken($token)
            ->withHeaders($this->commonHeaders())
            ->delete("{$this->inventoryBaseUrl()}/inventory_item/{$encodedSku}");

        if (! $deleteResponse->ok() && $deleteResponse->status() !== 404) {
            $this->logEbayApiError(
                'delete_inventory_item',
                "{$this->inventoryBaseUrl()}/inventory_item/{$encodedSku}",
                $deleteResponse->status(),
                $deleteResponse->body(),
                ['sku' => $sku]
            );

            throw new \RuntimeException("eBay deleteListing failed for SKU {$sku}: " . $deleteResponse->body());
        }

        Log::info("eBay listing deleted: SKU {$sku}");
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function buildOfferBody(Product $product): array
    {
        return [
            'sku'                 => $product->sku,
            'merchantLocationKey' => config('services.ebay_sell.merchant_location_key', 'OKELCOR-MAIN'),
            'marketplaceId'       => config('services.ebay_sell.marketplace_id', 'EBAY_DE'),
            'format'              => 'FIXED_PRICE',
            'availableQuantity'   => max(0, (int) $product->stock),
            'categoryId'          => config('services.ebay_sell.category_id', '11755'),
            'listingPolicies'     => [
                'fulfillmentPolicyId' => config('services.ebay_sell.fulfillment_policy_id'),
                'paymentPolicyId'     => config('services.ebay_sell.payment_policy_id'),
                'returnPolicyId'      => config('services.ebay_sell.return_policy_id'),
            ],
            'pricingSummary' => [
                'price' => [
                    'value'    => number_format((float) $product->price, 2, '.', ''),
                    'currency' => 'EUR',
                ],
            ],
            'listingDescription' => $this->buildDescription($product),
        ];
    }

    private function upsertInventoryItem(Product $product, string $token): void
    {
        $body = [
            'condition'    => 'NEW',
            'availability' => [
                'shipToLocationAvailability' => [
                    'quantity' => max(0, (int) $product->stock),
                ],
            ],
            'product' => [
                'title'       => $this->buildTitle($product),
                'description' => $this->buildDescription($product),
                'imageUrls'   => $this->imageUrls($product),
                'aspects'     => $this->buildAspects($product),
                'ean'         => $product->ean ? [$product->ean] : ['Does not apply'],
            ],
        ];

        $encodedSku = rawurlencode($product->sku);
        $endpoint   = "{$this->inventoryBaseUrl()}/inventory_item/{$encodedSku}";

        $response = Http::withToken($token)
            ->withHeaders($this->commonHeaders())
            ->put($endpoint, $body);

        // 200 = updated, 204 = created — both are success
        if (! in_array($response->status(), [200, 204])) {
            $this->logEbayApiError(
                'upsert_inventory_item',
                $endpoint,
                $response->status(),
                $response->body(),
                ['sku' => $product->sku, 'product_id' => $product->id]
            );

            throw new \RuntimeException("eBay inventory item upsert failed for SKU {$product->sku}: " . $response->body());
        }
    }

    private function upsertOffer(Product $product, string $token): string
    {
        $offerBody = $this->buildOfferBody($product);

        // Check if an offer already exists for this SKU
        $existing = Http::withToken($token)
            ->withHeaders($this->commonHeaders())
            ->get("{$this->inventoryBaseUrl()}/offer", ['sku' => $product->sku]);

        if ($existing->ok() && ! empty($existing->json('offers'))) {
            $offerId = $existing->json('offers.0.offerId');

            $response = Http::withToken($token)
                ->withHeaders($this->commonHeaders())
                ->put("{$this->inventoryBaseUrl()}/offer/{$offerId}", $offerBody);

            // PUT /offer returns 204 No Content on success (not 200)
            if (! $response->successful()) {
                $this->logEbayApiError(
                    'upsert_offer_put',
                    "{$this->inventoryBaseUrl()}/offer/{$offerId}",
                    $response->status(),
                    $response->body(),
                    ['sku' => $product->sku, 'product_id' => $product->id, 'offer_id' => $offerId]
                );

                throw new \RuntimeException("eBay offer update failed for SKU {$product->sku}: " . $response->body());
            }

            return $offerId;
        }

        // Create new offer — eBay returns HTTP 201 Created on success (not 200)
        $response = Http::withToken($token)
            ->withHeaders($this->commonHeaders())
            ->post("{$this->inventoryBaseUrl()}/offer", $offerBody);

        if (! $response->successful()) {
            $this->logEbayApiError(
                'upsert_offer_post',
                "{$this->inventoryBaseUrl()}/offer",
                $response->status(),
                $response->body(),
                ['sku' => $product->sku, 'product_id' => $product->id]
            );

            throw new \RuntimeException("eBay offer create failed for SKU {$product->sku}: " . $response->body());
        }

        return $response->json('offerId');
    }

    private function publishOffer(string $offerId, string $token): string
    {
        $response = Http::withToken($token)
            ->withHeaders($this->commonHeaders())
            ->post("{$this->inventoryBaseUrl()}/offer/{$offerId}/publish");

        if (! $response->ok()) {
            $this->logEbayApiError(
                'publish_offer',
                "{$this->inventoryBaseUrl()}/offer/{$offerId}/publish",
                $response->status(),
                $response->body(),
                ['offer_id' => $offerId]
            );

            throw new \RuntimeException("eBay offer publish failed for offerId {$offerId}: " . $response->body());
        }

        return (string) $response->json('listingId');
    }

    private function buildTitle(Product $product): string
    {
        // eBay title limit: 80 characters
        $parts = array_filter([
            $product->brand,
            $product->size,
            $product->spec,
            $product->season,
        ]);

        return mb_substr(implode(' ', $parts), 0, 80);
    }

    private function buildDescription(Product $product): string
    {
        $lines = array_filter([
            $product->name,
            $product->brand  ? "Brand: {$product->brand}"   : null,
            $product->size   ? "Size: {$product->size}"     : null,
            $product->spec   ? "Spec: {$product->spec}"     : null,
            $product->season ? "Season: {$product->season}" : null,
            $product->type   ? "Type: {$product->type}"     : null,
        ]);

        return implode("\n", $lines);
    }

    private function buildAspects(Product $product): array
    {
        $aspects     = [];
        $marketplace = config('services.ebay_sell.marketplace_id', 'EBAY_DE');
        $isGerman    = in_array($marketplace, ['EBAY_DE', 'EBAY_AT', 'EBAY_CH'], true);

        if ($isGerman) {
            // EBAY_DE / EBAY_AT / EBAY_CH category 10183 (PKW-Reifen) requires German aspect names.
            // Proven by errorId 25002: "Das Artikelmerkmal Marke fehlt" on publishOffer.
            if ($product->brand)        $aspects['Marke']                 = [$product->brand];
            if ($product->width)        $aspects['Reifenbreite']          = [(string) $product->width];
            if ($product->height)       $aspects['Querschnitt']           = [(string) $product->height];
            if ($product->rim)          $aspects['Felgengröße']           = [(string) $product->rim];
            if ($product->load_index)   $aspects['Lastindex']             = [(string) $product->load_index];
            if ($product->speed_rating) $aspects['Geschwindigkeitsindex'] = [(string) $product->speed_rating];
            if ($product->season)       $aspects['Saison']                = [$this->mapSeasonDe($product->season)];
        } else {
            if ($product->brand)        $aspects['Brand']        = [$product->brand];
            if ($product->width)        $aspects['Tyre Width']   = [(string) $product->width];
            if ($product->height)       $aspects['Aspect Ratio'] = [(string) $product->height];
            if ($product->rim)          $aspects['Rim Diameter'] = [(string) $product->rim];
            if ($product->load_index)   $aspects['Load Rating']  = [(string) $product->load_index];
            if ($product->speed_rating) $aspects['Speed Rating'] = [(string) $product->speed_rating];
            if ($product->season)       $aspects['Season']       = [$product->season];
        }

        return $aspects;
    }

    private function mapSeasonDe(string $season): string
    {
        return match (strtolower(trim($season))) {
            'summer', 'sommer', 'sommerreifen'                                       => 'Sommerreifen',
            'winter', 'winterreifen'                                                 => 'Winterreifen',
            'all-season', 'all season', 'all-weather', 'all weather',
            'all year', 'ganzjahr', 'ganzjahresreifen'                              => 'Ganzjahresreifen',
            default                                                                  => $season,
        };
    }

    private function imageUrls(Product $product): array
    {
        $urls = [];

        if ($product->primary_image) {
            $urls[] = url(Storage::url($product->primary_image));
        }

        foreach ($product->images as $img) {
            $urls[] = url(Storage::url($img->path));
        }

        return array_values(array_unique($urls));
    }

    private function guardProduct(Product $product): void
    {
        if (! EbayToken::active()->exists()) {
            throw new \RuntimeException(
                'eBay seller account is not connected. Visit GET /api/v1/admin/ebay/auth-url to authorise the seller account.'
            );
        }

        if (empty($product->sku)) {
            throw new \InvalidArgumentException("Product ID {$product->id} has no SKU — cannot list on eBay.");
        }

        $title = trim($this->buildTitle($product));
        if (empty($title)) {
            throw new \InvalidArgumentException("Product SKU {$product->sku} has no title — fill in brand, size, spec, or season fields.");
        }

        if (empty($product->price) || (float) $product->price <= 0) {
            throw new \InvalidArgumentException("Product SKU {$product->sku} has no price — cannot list on eBay.");
        }

        if ((int) $product->stock <= 0) {
            throw new \InvalidArgumentException("Product SKU {$product->sku} has no stock (quantity must be > 0) — cannot list on eBay.");
        }

        $imageUrls = $this->imageUrls($product);
        if (empty($imageUrls)) {
            throw new \InvalidArgumentException("Product SKU {$product->sku} has no images — eBay requires at least one image.");
        }

        foreach ($imageUrls as $url) {
            if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'])) {
                throw new \InvalidArgumentException("Product SKU {$product->sku} has an invalid image URL — eBay requires absolute HTTP/HTTPS image URLs.");
            }
        }

        if (empty(config('services.ebay_sell.marketplace_id'))) {
            throw new \RuntimeException('eBay marketplace ID is not configured. Set EBAY_MARKETPLACE_ID in .env');
        }

        if (empty(config('services.ebay_sell.category_id'))) {
            throw new \RuntimeException('eBay category ID is not configured. Set EBAY_CATEGORY_ID in .env');
        }

        if (
            empty(config('services.ebay_sell.fulfillment_policy_id')) ||
            empty(config('services.ebay_sell.payment_policy_id')) ||
            empty(config('services.ebay_sell.return_policy_id'))
        ) {
            throw new \RuntimeException(
                'eBay policy IDs are not configured. Set EBAY_FULFILLMENT_POLICY_ID, EBAY_PAYMENT_POLICY_ID, ' .
                'EBAY_RETURN_POLICY_ID in .env'
            );
        }
    }

    private function commonHeaders(): array
    {
        return ['Content-Language' => $this->marketplaceLocale()];
    }

    private function marketplaceLocale(): string
    {
        // Maps eBay marketplace IDs to their required BCP-47 Content-Language values.
        // eBay Inventory API stores items per-locale; POST /offer requires the item
        // to exist in the locale matching the offer's marketplaceId.
        $map = [
            'EBAY_DE' => 'de-DE',
            'EBAY_AT' => 'de-AT',
            'EBAY_CH' => 'de-CH',
            'EBAY_GB' => 'en-GB',
            'EBAY_AU' => 'en-AU',
            'EBAY_FR' => 'fr-FR',
            'EBAY_IT' => 'it-IT',
            'EBAY_ES' => 'es-ES',
            'EBAY_NL' => 'nl-NL',
            'EBAY_BE' => 'fr-BE',
            'EBAY_PL' => 'pl-PL',
            'EBAY_US' => 'en-US',
            'EBAY_CA' => 'en-CA',
        ];

        $marketplaceId = config('services.ebay_sell.marketplace_id', 'EBAY_DE');

        return $map[$marketplaceId] ?? 'de-DE';
    }

    private function marketplaceCountry(): string
    {
        $map = [
            'EBAY_DE' => 'DE', 'EBAY_AT' => 'AT', 'EBAY_CH' => 'CH',
            'EBAY_GB' => 'GB', 'EBAY_AU' => 'AU', 'EBAY_FR' => 'FR',
            'EBAY_IT' => 'IT', 'EBAY_ES' => 'ES', 'EBAY_NL' => 'NL',
            'EBAY_BE' => 'BE', 'EBAY_PL' => 'PL', 'EBAY_US' => 'US',
            'EBAY_CA' => 'CA',
        ];

        return $map[config('services.ebay_sell.marketplace_id', 'EBAY_DE')] ?? 'DE';
    }

    // -------------------------------------------------------------------------
    // Ensure a merchant location exists on eBay for the configured location key.
    //
    // eBay error 25002 (Item.Country missing) occurs when an offer is published
    // without a merchantLocationKey, because eBay cannot determine the item's
    // country of origin. A merchant location record provides the country.
    //
    // Auto-creates the location on first call if it does not exist.
    // Caches the existence check for 24 hours to avoid a GET on every listing.
    // -------------------------------------------------------------------------

    private function ensureMerchantLocation(string $token): void
    {
        $key      = config('services.ebay_sell.merchant_location_key', 'OKELCOR-MAIN');
        $cacheKey = 'ebay_merchant_location_' . $key;

        if (Cache::get($cacheKey)) {
            return;
        }

        $endpoint = "{$this->inventoryBaseUrl()}/location/" . rawurlencode($key);
        $response = Http::withToken($token)->withHeaders($this->commonHeaders())->get($endpoint);

        if ($response->ok()) {
            Cache::put($cacheKey, true, now()->addHours(24));
            Log::info('eBay: merchant location verified.', [
                'api_family'   => 'Sell API (REST)',
                'location_key' => $key,
                'token_source' => $this->tokenSource,
            ]);
            return;
        }

        // Location does not exist — create it using configured seller address
        $country = $this->marketplaceCountry();
        $create  = Http::withToken($token)
            ->withHeaders($this->commonHeaders())
            ->post($endpoint, [
                'location' => [
                    'address' => array_filter([
                        'city'       => config('services.ebay_sell.seller_location', 'Germany'),
                        'country'    => $country,
                        'postalCode' => config('services.ebay_sell.seller_postal_code'),
                    ]),
                ],
                'name'                   => 'Okelcor Main Location',
                'merchantLocationStatus' => 'ENABLED',
                'locationTypes'          => ['WAREHOUSE'],
            ]);

        if (! $create->successful()) {
            $this->logEbayApiError(
                'create_merchant_location',
                $endpoint,
                $create->status(),
                $create->body()
            );

            throw new \RuntimeException(
                "eBay merchant location create failed (key: {$key}): " . $create->body()
            );
        }

        Cache::put($cacheKey, true, now()->addHours(24));

        Log::info('eBay: merchant location created.', [
            'api_family'   => 'Sell API (REST)',
            'location_key' => $key,
            'country'      => $country,
            'postal_code'  => config('services.ebay_sell.seller_postal_code'),
            'token_source' => $this->tokenSource,
        ]);
    }

    // -------------------------------------------------------------------------
    // Verify inventory item is indexed on eBay before creating/updating an offer.
    //
    // eBay error 25751 ("SKU not found for marketplace") occurs when POST /offer
    // fires before eBay has finished indexing the just-PUT inventory item.
    // We retry up to $maxAttempts times with a 1-second gap.
    // -------------------------------------------------------------------------

    private function waitForInventoryItem(string $sku, string $token, int $maxAttempts = 3): void
    {
        $encodedSku = rawurlencode($sku);
        $endpoint   = "{$this->inventoryBaseUrl()}/inventory_item/{$encodedSku}";

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if ($attempt > 1) {
                sleep(1);
            }

            $response = Http::withToken($token)
                ->withHeaders($this->commonHeaders())
                ->get($endpoint);

            if ($response->ok()) {
                Log::info('eBay inventory item verified before offer creation.', [
                    'api_family'   => 'Sell API (REST)',
                    'operation'    => 'verify_inventory_item',
                    'endpoint'     => $endpoint,
                    'token_source' => $this->tokenSource,
                    'attempt'      => $attempt,
                    'sku'          => $sku,
                ]);
                return;
            }

            Log::warning('eBay inventory item not yet available — retrying.', [
                'api_family'   => 'Sell API (REST)',
                'operation'    => 'verify_inventory_item',
                'endpoint'     => $endpoint,
                'token_source' => $this->tokenSource,
                'sku'          => $sku,
                'attempt'      => $attempt,
                'max_attempts' => $maxAttempts,
                'http_status'  => $response->status(),
                'ebay_errors'  => $this->parseEbayErrors($response->body()),
            ]);
        }

        throw new \RuntimeException(
            "eBay inventory item for SKU {$sku} was not available after {$maxAttempts} attempts (error 25751). "
            . "eBay is still indexing the item. Please retry in a few seconds."
        );
    }

    // -------------------------------------------------------------------------
    // Step-by-step diagnostic for the Artisan ebay:debug-product command.
    //
    // Steps (all always run except offer_create_test and publish):
    //   validation        → local field checks
    //   token             → OAuth access token retrieval
    //   inventory_get_before → GET before any PUT (what eBay currently has)
    //   inventory_put     → PUT inventory_item
    //   inventory_get_after  → GET after PUT (confirm eBay indexed it)
    //   offer_check       → GET offers for this SKU
    //   offer_create_test → POST/PUT offer without publishing (only $withOffer)
    //   publish           → publish offer (only $withPublish)
    // -------------------------------------------------------------------------

    public function diagnoseProduct(
        Product $product,
        bool $withPublish = false,
        bool $withOffer = false
    ): array {
        $encodedSku     = rawurlencode($product->sku);
        $itemEndpoint   = "{$this->inventoryBaseUrl()}/inventory_item/{$encodedSku}";
        $offersEndpoint = "{$this->inventoryBaseUrl()}/offer";

        $report = [
            'product' => [
                'id'            => $product->id,
                'sku'           => $product->sku,
                'name'          => $product->name,
                'brand'         => $product->brand,
                'price'         => $product->price,
                'stock'         => $product->stock,
                'primary_image' => $product->primary_image,
                'image_url'     => $product->primary_image
                    ? url(Storage::url($product->primary_image))
                    : null,
                'image_count'   => count($this->imageUrls($product)),
                'all_image_urls' => $this->imageUrls($product),
            ],
            'config' => [
                'marketplace_id'         => config('services.ebay_sell.marketplace_id', 'EBAY_DE'),
                'category_id'            => config('services.ebay_sell.category_id'),
                'fulfillment_policy_id'  => config('services.ebay_sell.fulfillment_policy_id'),
                'payment_policy_id'      => config('services.ebay_sell.payment_policy_id'),
                'return_policy_id'       => config('services.ebay_sell.return_policy_id'),
                'environment'            => config('services.ebay.environment', 'sandbox'),
                'seller_postal_code'     => config('services.ebay_sell.seller_postal_code'),
                'seller_location'        => config('services.ebay_sell.seller_location', 'Germany'),
                'merchant_location_key'  => config('services.ebay_sell.merchant_location_key', 'OKELCOR-MAIN'),
            ],
            'steps'  => [],
            'result' => null,
            'error'  => null,
        ];

        // ── Step A: Validation ────────────────────────────────────────────────
        try {
            $this->guardProduct($product);
            $report['steps']['validation'] = [
                'status'     => 'pass',
                'sku'        => $product->sku,
                'title'      => $this->buildTitle($product),
                'price'      => $product->price,
                'stock'      => $product->stock,
                'image_urls' => $this->imageUrls($product),
                'aspects'    => $this->buildAspects($product),
            ];
        } catch (\Throwable $e) {
            $report['steps']['validation'] = ['status' => 'fail', 'error' => $e->getMessage()];
            $report['result'] = 'failed_at_validation';
            $report['error']  = $e->getMessage();
            return $report;
        }

        // ── Step B: Token ─────────────────────────────────────────────────────
        try {
            $token = $this->getAccessToken();
            $report['steps']['token'] = [
                'status'           => 'pass',
                'source'           => $this->tokenSource,
                'marketplace_id'   => config('services.ebay_sell.marketplace_id', 'EBAY_DE'),
                'content_language' => $this->marketplaceLocale(),
            ];
        } catch (\Throwable $e) {
            $report['steps']['token'] = ['status' => 'fail', 'error' => $e->getMessage()];
            $report['result'] = 'failed_at_token';
            $report['error']  = $e->getMessage();
            return $report;
        }

        // ── Step B2: Merchant location check/create ───────────────────────────
        $locationKey      = config('services.ebay_sell.merchant_location_key', 'OKELCOR-MAIN');
        $locationEndpoint = "{$this->inventoryBaseUrl()}/location/" . rawurlencode($locationKey);
        $locationGet      = Http::withToken($token)->withHeaders($this->commonHeaders())->get($locationEndpoint);

        if ($locationGet->ok()) {
            $report['steps']['merchant_location'] = [
                'status'      => 'pass',
                'key'         => $locationKey,
                'endpoint'    => $locationEndpoint,
                'http_status' => $locationGet->status(),
                'action'      => 'exists',
                'raw_preview' => mb_substr($locationGet->body(), 0, 400),
            ];
        } else {
            // Attempt to create it
            $country    = $this->marketplaceCountry();
            $locationCreate = Http::withToken($token)
                ->withHeaders($this->commonHeaders())
                ->post($locationEndpoint, [
                    'location' => [
                        'address' => array_filter([
                            'city'       => config('services.ebay_sell.seller_location', 'Germany'),
                            'country'    => $country,
                            'postalCode' => config('services.ebay_sell.seller_postal_code'),
                        ]),
                    ],
                    'name'                   => 'Okelcor Main Location',
                    'merchantLocationStatus' => 'ENABLED',
                    'locationTypes'          => ['WAREHOUSE'],
                ]);

            $createOk = $locationCreate->successful();

            $report['steps']['merchant_location'] = [
                'status'      => $createOk ? 'pass' : 'fail',
                'key'         => $locationKey,
                'endpoint'    => $locationEndpoint,
                'http_status' => $locationCreate->status(),
                'action'      => 'created',
                'country'     => $country,
                'raw_preview' => mb_substr($locationCreate->body(), 0, 400),
                'ebay_errors' => $createOk ? [] : $this->parseEbayErrors($locationCreate->body()),
            ];

            if (! $createOk) {
                $report['result'] = 'failed_at_merchant_location';
                $report['error']  = "Merchant location create failed (key: {$locationKey}): " . $locationCreate->body();
                return $report;
            }

            Cache::put('ebay_merchant_location_' . $locationKey, true, now()->addHours(24));
        }

        // ── Step C: GET inventory BEFORE PUT ──────────────────────────────────
        $preGetResponse = Http::withToken($token)->withHeaders($this->commonHeaders())->get($itemEndpoint);
        $report['steps']['inventory_get_before'] = [
            'status'       => 'info',
            'endpoint'     => $itemEndpoint,
            'http_status'  => $preGetResponse->status(),
            'sku_exists'   => $preGetResponse->ok(),
            'raw_preview'  => mb_substr($preGetResponse->body(), 0, 800),
            'ebay_errors'  => $preGetResponse->ok() ? [] : $this->parseEbayErrors($preGetResponse->body()),
        ];

        // ── Step D: PUT inventory item ────────────────────────────────────────
        $inventoryBody = [
            'condition'    => 'NEW',
            'availability' => [
                'shipToLocationAvailability' => ['quantity' => max(0, (int) $product->stock)],
            ],
            'product' => [
                'title'       => $this->buildTitle($product),
                'description' => $this->buildDescription($product),
                'imageUrls'   => $this->imageUrls($product),
                'aspects'     => $this->buildAspects($product),
                'ean'         => $product->ean ? [$product->ean] : ['Does not apply'],
            ],
        ];

        $putResponse = Http::withToken($token)->withHeaders($this->commonHeaders())->put($itemEndpoint, $inventoryBody);
        $putOk       = in_array($putResponse->status(), [200, 204]);

        $report['steps']['inventory_put'] = [
            'status'          => $putOk ? 'pass' : 'fail',
            'endpoint'        => $itemEndpoint,
            'http_status'     => $putResponse->status(),
            'request_payload' => [
                'condition'   => $inventoryBody['condition'],
                'quantity'    => $inventoryBody['availability']['shipToLocationAvailability']['quantity'],
                'title'       => $inventoryBody['product']['title'],
                'image_count' => count($inventoryBody['product']['imageUrls']),
                'image_urls'  => $inventoryBody['product']['imageUrls'],
                'aspects'     => $inventoryBody['product']['aspects'],
            ],
            'raw_preview' => mb_substr($putResponse->body(), 0, 800),
            'ebay_errors' => $putOk ? [] : $this->parseEbayErrors($putResponse->body()),
        ];

        if (! $putOk) {
            $report['result'] = 'failed_at_inventory_put';
            $report['error']  = "PUT inventory_item HTTP {$putResponse->status()}: " . $putResponse->body();
            return $report;
        }

        // ── Step E: GET inventory AFTER PUT (verify indexing) ─────────────────
        sleep(1);
        $postGetResponse = Http::withToken($token)->withHeaders($this->commonHeaders())->get($itemEndpoint);

        $report['steps']['inventory_get_after'] = [
            'status'      => $postGetResponse->ok() ? 'pass' : 'fail',
            'endpoint'    => $itemEndpoint,
            'http_status' => $postGetResponse->status(),
            'sku_found'   => $postGetResponse->ok(),
            'raw_preview' => mb_substr($postGetResponse->body(), 0, 800),
            'ebay_errors' => $postGetResponse->ok() ? [] : $this->parseEbayErrors($postGetResponse->body()),
        ];

        if (! $postGetResponse->ok()) {
            $report['result'] = 'failed_at_inventory_get_after';
            $report['error']  = "GET inventory_item returned HTTP {$postGetResponse->status()} — SKU not yet indexed on eBay: " . $postGetResponse->body();
            return $report;
        }

        // ── Step F: GET existing offers for this SKU ──────────────────────────
        $offerCheckResponse = Http::withToken($token)->withHeaders($this->commonHeaders())
            ->get($offersEndpoint, ['sku' => $product->sku]);

        $existingOffers = ($offerCheckResponse->ok()) ? ($offerCheckResponse->json('offers') ?? []) : [];
        $offerBody      = $this->buildOfferBody($product);

        $report['steps']['offer_check'] = [
            'status'               => 'info',
            'endpoint'             => $offersEndpoint . '?sku=' . rawurlencode($product->sku),
            'http_status'          => $offerCheckResponse->status(),
            'existing_count'       => count($existingOffers),
            'existing_ids'         => array_column($existingOffers, 'offerId'),
            'raw_preview'          => mb_substr($offerCheckResponse->body(), 0, 800),
            'offer_payload_will_send' => [
                'sku'                 => $offerBody['sku'],
                'marketplaceId'       => $offerBody['marketplaceId'],
                'format'              => $offerBody['format'],
                'categoryId'          => $offerBody['categoryId'],
                'price'               => $offerBody['pricingSummary']['price'],
                'quantity'            => $offerBody['availableQuantity'],
                'fulfillmentPolicyId' => $offerBody['listingPolicies']['fulfillmentPolicyId'],
                'paymentPolicyId'     => $offerBody['listingPolicies']['paymentPolicyId'],
                'returnPolicyId'      => $offerBody['listingPolicies']['returnPolicyId'],
            ],
        ];

        $report['result'] = 'ready_to_publish';

        // ── Step G: Offer create test (--offer flag) ──────────────────────────
        // POSTs or PUTs the offer WITHOUT publishing. Leaves a draft on eBay.
        if ($withOffer) {
            if (! empty($existingOffers)) {
                $offerId      = $existingOffers[0]['offerId'];
                $offerTestR   = Http::withToken($token)->withHeaders($this->commonHeaders())
                    ->put("{$this->inventoryBaseUrl()}/offer/{$offerId}", $offerBody);
                $offerAction  = "PUT /offer/{$offerId}";
            } else {
                $offerTestR   = Http::withToken($token)->withHeaders($this->commonHeaders())
                    ->post($offersEndpoint, $offerBody);
                $offerAction  = 'POST /offer';
                $offerId      = $offerTestR->json('offerId') ?? null;
            }

            // POST /offer returns 201 Created on success; PUT /offer returns 200
            $offerOk = $offerTestR->successful();

            $report['steps']['offer_create_test'] = [
                'status'          => $offerOk ? 'pass' : 'fail',
                'action'          => $offerAction,
                'endpoint'        => $offersEndpoint,
                'http_status'     => $offerTestR->status(),
                'offer_id'        => $offerOk ? ($offerId ?? $offerTestR->json('offerId')) : null,
                'request_payload' => $report['steps']['offer_check']['offer_payload_will_send'],
                'raw_body'        => $offerTestR->body(),
                'ebay_errors'     => $offerOk ? [] : $this->parseEbayErrors($offerTestR->body()),
            ];

            if (! $offerOk) {
                $report['result'] = 'failed_at_offer_create';
                $report['error']  = "Offer create/update HTTP {$offerTestR->status()}: " . $offerTestR->body();
                if (! $withPublish) {
                    return $report;
                }
            }
        }

        if (! $withPublish) {
            return $report;
        }

        // ── Step H: Publish (--publish flag) ─────────────────────────────────
        try {
            $offerId   = $this->upsertOffer($product, $token);
            $listingId = $this->publishOffer($offerId, $token);

            $report['steps']['publish'] = [
                'status'     => 'pass',
                'offer_id'   => $offerId,
                'listing_id' => $listingId,
            ];
            $report['result'] = 'published';
        } catch (\Throwable $e) {
            $report['steps']['publish'] = [
                'status'      => 'fail',
                'error'       => $e->getMessage(),
                'ebay_errors' => $this->parseEbayErrors(
                    substr($e->getMessage(), (int) strrpos($e->getMessage(), ': {') + 2)
                ),
            ];
            $report['result'] = 'failed_at_publish';
            $report['error']  = $e->getMessage();
        }

        return $report;
    }

    /**
     * Log a structured error record for any failed eBay Sell API call.
     * Never logs token values. Includes operation, endpoint, HTTP status,
     * parsed eBay error codes, and the token source that was used.
     *
     * @param array $context Extra context: sku, product_id, offer_id, etc.
     */
    private function logEbayApiError(
        string $operation,
        string $endpoint,
        int $httpStatus,
        string $rawBody,
        array $context = []
    ): void {
        Log::error('eBay Sell API call failed.', array_merge([
            'api_family'   => 'Sell API (REST)',
            'operation'    => $operation,
            'endpoint'     => $endpoint,
            'http_status'  => $httpStatus,
            'token_source' => $this->tokenSource,
            'ebay_errors'  => $this->parseEbayErrors($rawBody),
        ], $context));
    }

    /**
     * Parse eBay REST API error response body into a structured array.
     * Handles both the standard {"errors":[...]} shape and OAuth error shape.
     * Returns a raw snippet for non-JSON bodies.
     */
    private function parseEbayErrors(string $body): array
    {
        if (empty($body)) {
            return [];
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            if (isset($decoded['errors']) && is_array($decoded['errors'])) {
                return array_map(fn ($e) => [
                    'errorId'  => $e['errorId'] ?? null,
                    'domain'   => $e['domain'] ?? null,
                    'category' => $e['category'] ?? null,
                    'message'  => $e['message'] ?? null,
                ], $decoded['errors']);
            }

            // OAuth token endpoint uses 'error' key
            if (isset($decoded['error'])) {
                return [[
                    'error'       => $decoded['error'],
                    'description' => $decoded['error_description'] ?? null,
                ]];
            }
        } catch (\Throwable) {
            // Not valid JSON — return a safe raw snippet
            return [['raw' => mb_substr($body, 0, 300)]];
        }

        return [];
    }
}
