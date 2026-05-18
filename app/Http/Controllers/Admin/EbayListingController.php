<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EbayListingLog;
use App\Models\EbayToken;
use App\Models\Product;
use App\Services\EbaySellingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EbayListingController extends Controller
{
    public function __construct(private EbaySellingService $ebay) {}

    // -------------------------------------------------------------------------
    // GET /admin/ebay/auth-url
    // Generates a signed state, caches it for 15 min, returns the eBay consent URL.
    // -------------------------------------------------------------------------

    public function authUrl(): JsonResponse
    {
        $state    = bin2hex(random_bytes(16));
        $cacheKey = 'ebay_oauth_state:' . $state;
        Cache::put($cacheKey, true, now()->addMinutes(15));

        $url = $this->ebay->buildAuthUrl($state);

        return response()->json([
            'data' => [
                'url'   => $url,
                'state' => $state,
            ],
            'message' => 'Visit this URL in a browser logged in as the eBay seller. The link expires in 15 minutes.',
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /admin/ebay/callback  (PUBLIC — no Sanctum auth)
    // eBay redirects the seller's browser here after they authorise the app.
    // State param is the CSRF guard. On success/failure, redirects to the
    // admin frontend eBay page with an ?ebay_connected= query param.
    // -------------------------------------------------------------------------

    public function callback(Request $request): RedirectResponse
    {
        $frontendBase = config('app.frontend_url') . '/admin/ebay';
        $code         = $request->query('code');
        $state        = $request->query('state');
        $error        = $request->query('error');

        // eBay returned an error (e.g., seller declined the consent screen)
        if ($error) {
            Log::warning('eBay OAuth consent declined.', [
                'action' => 'ebay_token_refresh_failed',
                'error'  => $error,
            ]);

            return redirect()->away($frontendBase . '?ebay_connected=false&error=' . urlencode((string) $error));
        }

        // Verify state (CSRF) — Cache::pull atomically reads and deletes (one-time use)
        if (empty($state) || ! Cache::pull('ebay_oauth_state:' . $state)) {
            Log::warning('eBay OAuth callback: invalid or expired state parameter.');

            return redirect()->away($frontendBase . '?ebay_connected=false&error=invalid_state');
        }

        if (empty($code)) {
            return redirect()->away($frontendBase . '?ebay_connected=false&error=missing_code');
        }

        try {
            $token = $this->ebay->exchangeCodeForTokens($code);

            Log::info('eBay seller account connected.', [
                'action'         => 'ebay_connected',
                'token_id'       => $token->id,
                'marketplace_id' => $token->marketplace_id,
            ]);

            return redirect()->away($frontendBase . '?ebay_connected=true');
        } catch (\Throwable $e) {
            Log::error('eBay OAuth token exchange failed.', [
                'action' => 'ebay_token_refresh_failed',
                'error'  => $e->getMessage(),
            ]);

            return redirect()->away($frontendBase . '?ebay_connected=false&error=token_exchange_failed');
        }
    }

    // -------------------------------------------------------------------------
    // GET /admin/ebay/status
    // Returns connection status and config health check.
    // -------------------------------------------------------------------------

    public function status(): JsonResponse
    {
        $token = EbayToken::active()->latest()->first();

        $missingConfig = [];
        foreach ([
            'EBAY_CLIENT_ID'             => config('services.ebay_sell.client_id'),
            'EBAY_RU_NAME'               => config('services.ebay_sell.ru_name'),
            'EBAY_FULFILLMENT_POLICY_ID' => config('services.ebay_sell.fulfillment_policy_id'),
            'EBAY_PAYMENT_POLICY_ID'     => config('services.ebay_sell.payment_policy_id'),
            'EBAY_RETURN_POLICY_ID'      => config('services.ebay_sell.return_policy_id'),
        ] as $key => $value) {
            if (empty($value)) {
                $missingConfig[] = $key;
            }
        }

        if (! $token) {
            return response()->json([
                'data' => [
                    'connected'         => false,
                    'marketplace_id'    => config('services.ebay_sell.marketplace_id', 'EBAY_DE'),
                    'seller_username'   => null,
                    'connected_at'      => null,
                    'last_refreshed_at' => null,
                    'missing_config'    => $missingConfig,
                ],
                'message' => 'eBay seller account not connected.',
            ]);
        }

        return response()->json([
            'data' => [
                'connected'         => true,
                'marketplace_id'    => $token->marketplace_id,
                'seller_username'   => $token->seller_username,
                'connected_at'      => $token->connected_at?->toIso8601String(),
                'last_refreshed_at' => $token->last_refreshed_at?->toIso8601String(),
                'missing_config'    => $missingConfig,
            ],
            'message' => 'success',
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /admin/ebay/readiness
    // Pre-listing readiness checklist — config health + live token test.
    // Returns structured checks with pass/warning/fail + missing_config list.
    // Does NOT expose credential values.
    // -------------------------------------------------------------------------

    public function readiness(): JsonResponse
    {
        $checks  = [];
        $missing = [];

        $pass = function (string $key, string $label, string $msg) use (&$checks): void {
            $checks[] = ['key' => $key, 'label' => $label, 'status' => 'pass', 'message' => $msg];
        };
        $warn = function (string $key, string $label, string $msg) use (&$checks): void {
            $checks[] = ['key' => $key, 'label' => $label, 'status' => 'warning', 'message' => $msg];
        };
        $fail = function (string $key, string $label, string $msg) use (&$checks, &$missing): void {
            $checks[]  = ['key' => $key, 'label' => $label, 'status' => 'fail', 'message' => $msg];
            $missing[] = $key;
        };

        // App credentials
        ! empty(config('services.ebay_sell.client_id'))
            ? $pass('client_id', 'eBay Client ID', 'EBAY_CLIENT_ID is configured.')
            : $fail('client_id', 'eBay Client ID', 'EBAY_CLIENT_ID is missing.');

        ! empty(config('services.ebay_sell.client_secret'))
            ? $pass('client_secret', 'eBay Client Secret', 'EBAY_CLIENT_SECRET is configured.')
            : $fail('client_secret', 'eBay Client Secret', 'EBAY_CLIENT_SECRET is missing.');

        ! empty(config('services.ebay_sell.ru_name'))
            ? $pass('ru_name', 'eBay RuName', 'EBAY_RU_NAME is configured.')
            : $fail('ru_name', 'eBay RuName', 'EBAY_RU_NAME is missing — required for the OAuth redirect flow.');

        // Seller account
        $connected = EbayToken::active()->exists();
        $connected
            ? $pass('connected', 'Seller account connected', 'An active seller OAuth token is present.')
            : $fail('connected', 'Seller account connected', 'Seller account is not connected. Use GET /admin/ebay/auth-url to start the OAuth flow.');

        // Marketplace + category
        $marketplaceId = config('services.ebay_sell.marketplace_id');
        ! empty($marketplaceId)
            ? $pass('marketplace_id', 'Marketplace ID', "Marketplace is set to {$marketplaceId}.")
            : $fail('marketplace_id', 'Marketplace ID', 'EBAY_MARKETPLACE_ID is missing.');

        $categoryId = config('services.ebay_sell.category_id');
        if (empty($categoryId)) {
            $fail('category_id', 'Default category ID', 'EBAY_CATEGORY_ID is missing.');
        } else {
            $pass('category_id', 'Default category ID', "Category ID is set to {$categoryId}.");

            // Cross-check: 179680 is an ebay.com (US) category and is NOT valid for EBAY_DE.
            // eBay category IDs are marketplace-specific and are not portable.
            $knownUsOnlyCategories = ['179680', '6030'];
            if (
                str_contains((string) $marketplaceId, 'DE') &&
                in_array((string) $categoryId, $knownUsOnlyCategories, true)
            ) {
                $fail(
                    'category_marketplace_mismatch',
                    'Category / Marketplace match',
                    "Category {$categoryId} is from ebay.com (US) and is NOT valid for {$marketplaceId}. "
                        . "For car tyres on EBAY_DE use 10183 (PKW-Reifen); for truck/bus tyres use 10209 (LKW/Bus-Reifen). "
                        . "Update EBAY_CATEGORY_ID in .env."
                );
            }
        }

        // Business policies
        ! empty(config('services.ebay_sell.payment_policy_id'))
            ? $pass('payment_policy_id', 'Payment policy ID', 'EBAY_PAYMENT_POLICY_ID is configured.')
            : $fail('payment_policy_id', 'Payment policy ID', 'EBAY_PAYMENT_POLICY_ID is missing. Fetch IDs from GET /admin/ebay/policies.');

        ! empty(config('services.ebay_sell.fulfillment_policy_id'))
            ? $pass('fulfillment_policy_id', 'Fulfillment policy ID', 'EBAY_FULFILLMENT_POLICY_ID is configured.')
            : $fail('fulfillment_policy_id', 'Fulfillment policy ID', 'EBAY_FULFILLMENT_POLICY_ID is missing. Fetch IDs from GET /admin/ebay/policies.');

        ! empty(config('services.ebay_sell.return_policy_id'))
            ? $pass('return_policy_id', 'Return policy ID', 'EBAY_RETURN_POLICY_ID is configured.')
            : $fail('return_policy_id', 'Return policy ID', 'EBAY_RETURN_POLICY_ID is missing. Fetch IDs from GET /admin/ebay/policies.');

        // Seller location
        ! empty(config('services.ebay_sell.seller_postal_code'))
            ? $pass('seller_postal_code', 'Seller postal code', 'EBAY_SELLER_POSTAL_CODE is configured.')
            : $fail('seller_postal_code', 'Seller postal code', 'EBAY_SELLER_POSTAL_CODE is missing — required for eBay item location.');

        // Environment (warning only — sandbox is valid for testing)
        $env = config('services.ebay.environment', 'sandbox');
        $env === 'production'
            ? $pass('environment', 'eBay environment', 'Environment is set to production.')
            : $warn('environment', 'eBay environment', "Environment is set to {$env}. Set EBAY_ENVIRONMENT=production before going live.");

        // Live token test (only fires if account is connected)
        if ($connected) {
            try {
                $this->ebay->getAccessToken();
                $pass('token_refresh', 'Token refresh', 'Access token is valid and refreshed successfully.');
            } catch (\Throwable) {
                $fail('token_refresh', 'Token refresh', 'Token refresh failed — the seller account may need to be reconnected via GET /admin/ebay/auth-url.');
            }
        } else {
            $fail('token_refresh', 'Token refresh', 'Cannot test token refresh — no active seller account connected.');
        }

        return response()->json([
            'data' => [
                'connected'       => $connected,
                'environment'     => $env,
                'marketplace_id'  => $marketplaceId,
                'category_id'     => $categoryId,
                'policies'        => [
                    'payment_policy_id'     => config('services.ebay_sell.payment_policy_id'),
                    'fulfillment_policy_id' => config('services.ebay_sell.fulfillment_policy_id'),
                    'return_policy_id'      => config('services.ebay_sell.return_policy_id'),
                ],
                'seller_location' => [
                    'postal_code' => config('services.ebay_sell.seller_postal_code'),
                    'location'    => config('services.ebay_sell.seller_location', 'Germany'),
                ],
                'checks'         => $checks,
                'missing_config' => $missing,
            ],
            'message' => empty($missing)
                ? 'All required checks passed.'
                : count($missing) . ' required check(s) failed — resolve before listing products.',
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /admin/ebay/test-connection
    // Refreshes the token and calls a lightweight eBay endpoint to verify
    // that credentials and the stored token are working.
    // -------------------------------------------------------------------------

    public function testConnection(): JsonResponse
    {
        try {
            $result = $this->ebay->pingConnection();

            return response()->json([
                'data'    => ['ok' => true],
                'message' => $result['message'],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'data'    => ['ok' => false],
                'message' => $this->safeError($e),
            ], 502);
        }
    }

    // -------------------------------------------------------------------------
    // GET /admin/ebay/policies
    // Fetches payment, fulfillment, and return business policies from eBay
    // for the configured marketplace. Returns id + name for each policy.
    // -------------------------------------------------------------------------

    public function policies(): JsonResponse
    {
        try {
            $data = $this->ebay->fetchPolicies();

            return response()->json([
                'data'    => $data,
                'message' => 'success',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $this->safeError($e),
            ], 502);
        }
    }

    // -------------------------------------------------------------------------
    // POST /admin/ebay/disconnect
    // Deactivates the current token. Does not touch existing eBay listings.
    // -------------------------------------------------------------------------

    public function disconnect(): JsonResponse
    {
        $count = EbayToken::where('is_active', true)->count();

        EbayToken::where('is_active', true)->update(['is_active' => false]);

        Cache::forget('ebay_sell_user_token_' . config('services.ebay.environment'));

        Log::info('eBay seller account disconnected.', [
            'action'             => 'ebay_disconnected',
            'tokens_deactivated' => $count,
        ]);

        return response()->json([
            'message' => 'eBay seller account disconnected. Existing product listings on eBay are not affected.',
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /admin/ebay/listings
    // -------------------------------------------------------------------------

    public function listings(): JsonResponse
    {
        $products = Product::where('ebay_listed', true)
            ->orderBy('updated_at', 'desc')
            ->get([
                'id', 'sku', 'name', 'brand', 'price', 'stock',
                'ebay_listed', 'ebay_item_id', 'ebay_offer_id',
                'ebay_status', 'ebay_last_synced_at', 'ebay_sync_error',
            ]);

        return response()->json([
            'data' => $products,
            'meta' => ['total' => $products->count()],
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /admin/products/{id}/ebay/list
    // -------------------------------------------------------------------------

    public function listProduct(int $id): JsonResponse
    {
        $product  = Product::findOrFail($id);
        $adminId  = auth()->id();

        try {
            $result = $this->ebay->createOrUpdateListing($product);

            $product->update([
                'ebay_listed'         => true,
                'ebay_item_id'        => $result['listing_id'],
                'ebay_offer_id'       => $result['offer_id'],
                'ebay_status'         => 'active',
                'ebay_last_synced_at' => now(),
                'ebay_sync_error'     => null,
            ]);

            $this->writeLog([
                'product_id'    => $product->id,
                'admin_user_id' => $adminId,
                'sku'           => $product->sku,
                'action'        => 'publish',
                'ebay_item_id'  => $result['listing_id'],
                'ebay_offer_id' => $result['offer_id'],
                'status'        => 'active',
            ]);

            return response()->json([
                'data' => [
                    'listing_id' => $result['listing_id'],
                    'offer_id'   => $result['offer_id'],
                    'sku'        => $product->sku,
                    'ebay_status'=> 'active',
                ],
                'message' => "Product SKU {$product->sku} listed on eBay (listing #{$result['listing_id']}).",
            ]);
        } catch (\InvalidArgumentException $e) {
            $safe = $this->safeError($e);

            $product->update([
                'ebay_status'     => 'error',
                'ebay_sync_error' => $safe,
            ]);

            $this->writeLog([
                'product_id'    => $product->id,
                'admin_user_id' => $adminId,
                'sku'           => $product->sku,
                'action'        => 'validation_failed',
                'status'        => 'error',
                'error_message' => $safe,
            ]);

            return response()->json(['message' => $safe], 422);
        } catch (\Throwable $e) {
            $safe       = $this->safeError($e);
            $ebayErrors = $this->extractEbayErrors($e);

            $product->update([
                'ebay_status'     => 'error',
                'ebay_sync_error' => $safe,
            ]);

            $this->writeLog([
                'product_id'      => $product->id,
                'admin_user_id'   => $adminId,
                'sku'             => $product->sku,
                'action'          => 'publish_failed',
                'status'          => 'error',
                'error_message'   => $safe,
                'payload_summary' => empty($ebayErrors) ? null : ['ebay_errors' => $ebayErrors],
            ]);

            return response()->json([
                'data'    => ['ebay_errors' => $ebayErrors],
                'message' => $safe,
            ], 502);
        }
    }

    // -------------------------------------------------------------------------
    // DELETE /admin/products/{id}/ebay/remove
    // -------------------------------------------------------------------------

    public function removeListing(int $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $adminId = auth()->id();

        try {
            $this->ebay->deleteListing($product->sku);

            $product->update([
                'ebay_listed'         => false,
                'ebay_item_id'        => null,
                'ebay_offer_id'       => null,
                'ebay_status'         => 'withdrawn',
                'ebay_last_synced_at' => now(),
                'ebay_sync_error'     => null,
            ]);

            $this->writeLog([
                'product_id'    => $product->id,
                'admin_user_id' => $adminId,
                'sku'           => $product->sku,
                'action'        => 'remove',
                'status'        => 'withdrawn',
            ]);

            return response()->json([
                'message' => "eBay listing removed for SKU {$product->sku}.",
            ]);
        } catch (\Throwable $e) {
            $safe       = $this->safeError($e);
            $ebayErrors = $this->extractEbayErrors($e);

            $this->writeLog([
                'product_id'      => $product->id,
                'admin_user_id'   => $adminId,
                'sku'             => $product->sku,
                'action'          => 'remove_failed',
                'status'          => 'error',
                'error_message'   => $safe,
                'payload_summary' => empty($ebayErrors) ? null : ['ebay_errors' => $ebayErrors],
            ]);

            return response()->json([
                'data'    => ['ebay_errors' => $ebayErrors],
                'message' => $safe,
            ], 502);
        }
    }

    // -------------------------------------------------------------------------
    // PATCH /admin/products/{id}/ebay/update
    // Updates an existing eBay listing — title, description, price, stock.
    // Requires the product to already be listed (ebay_listed = true).
    // Does NOT re-publish the offer.
    // -------------------------------------------------------------------------

    public function updateProduct(int $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $adminId = auth()->id();

        if (! $product->ebay_listed) {
            return response()->json([
                'message' => 'Product is not listed on eBay. Use the "List on eBay" action to create a listing first.',
            ], 422);
        }

        try {
            $result = $this->ebay->updateListing($product);

            $product->update([
                'ebay_item_id'        => $result['listing_id'] ?: $product->ebay_item_id,
                'ebay_offer_id'       => $result['offer_id'],
                'ebay_status'         => 'active',
                'ebay_last_synced_at' => now(),
                'ebay_sync_error'     => null,
            ]);

            $this->writeLog([
                'product_id'      => $product->id,
                'admin_user_id'   => $adminId,
                'sku'             => $product->sku,
                'action'          => 'update',
                'ebay_item_id'    => $result['listing_id'] ?: $product->ebay_item_id,
                'ebay_offer_id'   => $result['offer_id'],
                'status'          => 'active',
                'payload_summary' => ['price' => $product->price, 'stock' => $product->stock],
            ]);

            return response()->json([
                'data' => [
                    'offer_id'    => $result['offer_id'],
                    'listing_id'  => $result['listing_id'],
                    'sku'         => $product->sku,
                    'ebay_status' => 'active',
                ],
                'message' => "eBay listing updated for SKU {$product->sku}.",
            ]);
        } catch (\InvalidArgumentException $e) {
            $safe = $this->safeError($e);

            $product->update([
                'ebay_status'     => 'error',
                'ebay_sync_error' => $safe,
            ]);

            $this->writeLog([
                'product_id'    => $product->id,
                'admin_user_id' => $adminId,
                'sku'           => $product->sku,
                'action'        => 'validation_failed',
                'ebay_item_id'  => $product->ebay_item_id,
                'status'        => 'error',
                'error_message' => $safe,
            ]);

            return response()->json(['message' => $safe], 422);
        } catch (\Throwable $e) {
            $safe       = $this->safeError($e);
            $ebayErrors = $this->extractEbayErrors($e);

            $product->update([
                'ebay_status'     => 'error',
                'ebay_sync_error' => $safe,
            ]);

            $this->writeLog([
                'product_id'      => $product->id,
                'admin_user_id'   => $adminId,
                'sku'             => $product->sku,
                'action'          => 'update_failed',
                'ebay_item_id'    => $product->ebay_item_id,
                'status'          => 'error',
                'error_message'   => $safe,
                'payload_summary' => empty($ebayErrors) ? null : ['ebay_errors' => $ebayErrors],
            ]);

            return response()->json([
                'data'    => ['ebay_errors' => $ebayErrors],
                'message' => $safe,
            ], 502);
        }
    }

    // -------------------------------------------------------------------------
    // POST /admin/ebay/sync-all
    // Syncs stock, price, title, and description for all listed products.
    // Logs each result individually.
    // A single product failure does not stop the rest of the batch.
    // -------------------------------------------------------------------------

    public function syncAll(): JsonResponse
    {
        $products = Product::where('ebay_listed', true)->get();
        $adminId  = auth()->id();
        $synced   = 0;
        $errors   = [];

        foreach ($products as $product) {
            try {
                $this->ebay->syncFull($product);

                // Best-effort status refresh — failure here does not break the sync
                $ebayStatus = $product->ebay_status ?? 'active';
                try {
                    $statusResult = $this->ebay->getListingStatus($product->sku);
                    $ebayStatus   = $statusResult['status'];

                    if (! empty($statusResult['offer_id']) && $statusResult['offer_id'] !== $product->ebay_offer_id) {
                        $product->ebay_offer_id = $statusResult['offer_id'];
                    }
                } catch (\Throwable) {
                    // Status refresh failed — keep existing status, do not fail the sync
                }

                $product->update([
                    'ebay_status'         => $ebayStatus,
                    'ebay_last_synced_at' => now(),
                    'ebay_sync_error'     => null,
                    'ebay_offer_id'       => $product->ebay_offer_id,
                ]);

                $this->writeLog([
                    'product_id'      => $product->id,
                    'admin_user_id'   => $adminId,
                    'sku'             => $product->sku,
                    'action'          => 'sync',
                    'ebay_item_id'    => $product->ebay_item_id,
                    'ebay_offer_id'   => $product->ebay_offer_id,
                    'status'          => $ebayStatus,
                    'payload_summary' => ['stock' => $product->stock, 'price' => $product->price],
                ]);

                $synced++;
            } catch (\Throwable $e) {
                $safe = $this->safeError($e);

                $product->update([
                    'ebay_status'     => 'error',
                    'ebay_sync_error' => $safe,
                ]);

                $this->writeLog([
                    'product_id'    => $product->id,
                    'admin_user_id' => $adminId,
                    'sku'           => $product->sku,
                    'action'        => 'sync_failed',
                    'ebay_item_id'  => $product->ebay_item_id,
                    'status'        => 'error',
                    'error_message' => $safe,
                ]);

                $errors[] = "SKU {$product->sku}: {$safe}";
            }
        }

        return response()->json([
            'data'    => ['synced' => $synced, 'errors' => $errors],
            'message' => "Synced {$synced} of {$products->count()} listings.",
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /admin/products/{id}/ebay/refresh-status
    // Fetches the current listing status from eBay and updates the product.
    // -------------------------------------------------------------------------

    public function refreshStatus(int $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $adminId = auth()->id();

        if (! $product->ebay_listed) {
            return response()->json(['message' => 'Product is not listed on eBay.'], 422);
        }

        try {
            $result = $this->ebay->getListingStatus($product->sku);

            $product->update([
                'ebay_status'         => $result['status'],
                'ebay_offer_id'       => $result['offer_id'] ?? $product->ebay_offer_id,
                'ebay_last_synced_at' => now(),
                'ebay_sync_error'     => null,
            ]);

            $this->writeLog([
                'product_id'    => $product->id,
                'admin_user_id' => $adminId,
                'sku'           => $product->sku,
                'action'        => 'refresh_status',
                'ebay_item_id'  => $product->ebay_item_id,
                'ebay_offer_id' => $result['offer_id'] ?? $product->ebay_offer_id,
                'status'        => $result['status'],
            ]);

            return response()->json([
                'data' => [
                    'sku'                 => $product->sku,
                    'ebay_status'         => $result['status'],
                    'ebay_offer_id'       => $result['offer_id'] ?? $product->ebay_offer_id,
                    'ebay_last_synced_at' => now()->toIso8601String(),
                    'ebay_sync_error'     => null,
                ],
                'message' => 'eBay listing status refreshed.',
            ]);
        } catch (\Throwable $e) {
            $safe = $this->safeError($e);

            $product->update([
                'ebay_status'     => 'unknown',
                'ebay_sync_error' => $safe,
            ]);

            $this->writeLog([
                'product_id'    => $product->id,
                'admin_user_id' => $adminId,
                'sku'           => $product->sku,
                'action'        => 'refresh_status_failed',
                'ebay_item_id'  => $product->ebay_item_id,
                'status'        => 'unknown',
                'error_message' => $safe,
            ]);

            return response()->json(['message' => $safe], 502);
        }
    }

    // -------------------------------------------------------------------------
    // GET /admin/ebay/logs
    // Paginated log of all eBay listing actions.
    // Filters: product_id, sku, action, status, date_from, date_to
    // -------------------------------------------------------------------------

    public function logs(Request $request): JsonResponse
    {
        $query = EbayListingLog::with(['product:id,sku,name,brand', 'adminUser:id,email,name'])
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', $request->product_id))
            ->when($request->filled('sku'),        fn ($q) => $q->where('sku', $request->sku))
            ->when($request->filled('action'),     fn ($q) => $q->where('action', $request->action))
            ->when($request->filled('status'),     fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('date_from'),  fn ($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'),    fn ($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->latest('created_at');

        $perPage = min((int) ($request->per_page ?? 50), 200);
        $result  = $query->paginate($perPage);

        return response()->json([
            'data' => $result->items(),
            'meta' => [
                'current_page' => $result->currentPage(),
                'per_page'     => $result->perPage(),
                'total'        => $result->total(),
                'last_page'    => $result->lastPage(),
            ],
            'message' => 'success',
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Write a log entry to ebay_listing_logs.
     * Wrapped in try/catch so log failure never blocks the primary action.
     */
    private function writeLog(array $data): void
    {
        try {
            EbayListingLog::create($data);
        } catch (\Throwable $e) {
            Log::warning('eBay: failed to write listing log.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Extract eBay error objects from the raw exception message.
     * EbaySellingService embeds the raw eBay JSON response body after the last ': '.
     * Returns structured error array suitable for inclusion in API responses.
     */
    private function extractEbayErrors(\Throwable $e): array
    {
        $msg = $e->getMessage();

        // Find the JSON portion: messages end with ": {json}" or ": [{json}]"
        $jsonStart = strrpos($msg, ': {');
        if ($jsonStart === false) {
            $jsonStart = strrpos($msg, ': [');
        }
        if ($jsonStart === false) {
            return [];
        }

        $json = substr($msg, $jsonStart + 2);

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            if (isset($decoded['errors']) && is_array($decoded['errors'])) {
                return array_map(fn ($err) => array_filter([
                    'errorId'     => $err['errorId'] ?? null,
                    'domain'      => $err['domain'] ?? null,
                    'category'    => $err['category'] ?? null,
                    'message'     => $err['message'] ?? null,
                    'longMessage' => $err['longMessage'] ?? null,
                    'parameters'  => $err['parameters'] ?? null,
                ], fn ($v) => $v !== null), $decoded['errors']);
            }

            // OAuth token endpoint errors
            if (isset($decoded['error'])) {
                return [[
                    'error'       => $decoded['error'],
                    'description' => $decoded['error_description'] ?? null,
                ]];
            }
        } catch (\Throwable) {}

        return [];
    }

    /**
     * Map raw exception messages to safe, user-readable error strings.
     * Never leaks internal stack traces or sensitive config values.
     */
    private function safeError(\Throwable $e): string
    {
        $msg = $e->getMessage();

        if (str_contains($msg, 'not connected') || str_contains($msg, 'EBAY_REFRESH_TOKEN') || str_contains($msg, 'auth-url')) {
            return 'eBay seller account is not connected. Use GET /admin/ebay/auth-url to connect first.';
        }

        if (str_contains($msg, 'token refresh failed') || str_contains($msg, 'invalid_grant')) {
            return 'eBay authentication failed — the seller account may need to be reconnected via /admin/ebay/auth-url.';
        }

        // eBay REST API returns errorId 1001 / "Invalid access token" on HTTP 401
        // This triggers when a cached access token has expired before the cache TTL
        if (
            str_contains($msg, '"errorId":1001') ||
            str_contains($msg, 'Invalid access token') ||
            str_contains($msg, 'invalid_token') ||
            str_contains($msg, 'IAF token') ||
            str_contains($msg, 'token is expired')
        ) {
            return 'eBay access token is invalid or expired. Disconnect and reconnect the seller account via /admin/ebay/auth-url.';
        }

        if (str_contains($msg, 'policy IDs')) {
            return 'eBay listing policy IDs are not configured. Set EBAY_FULFILLMENT_POLICY_ID, EBAY_PAYMENT_POLICY_ID, and EBAY_RETURN_POLICY_ID in your environment.';
        }

        if (str_contains($msg, 'marketplace ID is not configured')) {
            return 'eBay marketplace ID is not configured. Set EBAY_MARKETPLACE_ID in .env';
        }

        if (str_contains($msg, 'category ID is not configured')) {
            return 'eBay category ID is not configured. Set EBAY_CATEGORY_ID in .env';
        }

        if (str_contains($msg, 'No existing eBay offer found') || str_contains($msg, "Use 'List on eBay'")) {
            return 'No eBay listing found for this product. Use "List on eBay" to create and publish a listing first.';
        }

        if (str_contains($msg, 'no SKU')) {
            return 'Product has no SKU — a unique SKU is required for eBay listing.';
        }

        if (str_contains($msg, 'has no title')) {
            return 'Product has no title — fill in brand, size, spec, or season fields before listing.';
        }

        if (str_contains($msg, 'no price')) {
            return 'Product has no price — a price greater than 0 is required for eBay listing.';
        }

        if (str_contains($msg, 'no stock') || str_contains($msg, 'quantity must be > 0')) {
            return 'Product has no stock (quantity is 0 or less) — update stock before listing on eBay.';
        }

        if (str_contains($msg, 'no images')) {
            return 'Product has no images — eBay requires at least one image before listing.';
        }

        if (str_contains($msg, 'invalid image URL') || str_contains($msg, 'absolute HTTP')) {
            return 'Product has an invalid image URL — eBay requires absolute HTTPS image URLs.';
        }

        // ─── eBay API error-code patterns ────────────────────────────────────────
        // These match against eBay errorId values or message text embedded in the
        // exception by EbaySellingService. Checked BEFORE the generic operation
        // patterns so specific errors get specific messages.

        $marketplace = config('services.ebay_sell.marketplace_id', 'EBAY_DE');

        // Category not valid for this marketplace (e.g. US category used on EBAY_DE)
        if (
            preg_match('/"errorId"\s*:\s*(25002|25003|21917182|95500)\b/', $msg) ||
            str_contains($msg, 'not enabled for listing') ||
            str_contains($msg, 'category is not valid') ||
            (str_contains($msg, 'Category') && str_contains($msg, 'not supported'))
        ) {
            $categoryId = config('services.ebay_sell.category_id', '?');
            return "eBay category ID {$categoryId} is not valid for marketplace {$marketplace}. "
                . "Update EBAY_CATEGORY_ID with a category that belongs to {$marketplace}. "
                . "For car tyres on EBAY_DE use 10183 (PKW-Reifen); for truck/bus tyres use 10209 (LKW/Bus-Reifen). "
                . "Run GET /admin/ebay/readiness to check all pre-listing config.";
        }

        // Business policy doesn't belong to this marketplace
        if (
            preg_match('/"errorId"\s*:\s*(20400|20402|20403|20404|25004|25005)\b/', $msg) ||
            str_contains($msg, 'policy does not belong') ||
            str_contains($msg, 'policy not found') ||
            str_contains($msg, 'Business policy') ||
            (str_contains($msg, 'policy') && str_contains($msg, 'not valid'))
        ) {
            return "One or more eBay business policy IDs are not valid for {$marketplace}. "
                . "Fetch the correct IDs from GET /admin/ebay/policies and update "
                . "EBAY_FULFILLMENT_POLICY_ID, EBAY_PAYMENT_POLICY_ID, EBAY_RETURN_POLICY_ID in .env.";
        }

        // Seller does not have permission to list in this category
        if (
            str_contains($msg, 'Insufficient permissions') ||
            str_contains($msg, 'not authorized to list') ||
            str_contains($msg, 'seller account does not have') ||
            (str_contains($msg, 'permission') && str_contains($msg, 'category'))
        ) {
            return "eBay seller account does not have permission to list in this category. "
                . "Check seller account standing and category access on eBay Seller Hub.";
        }

        // eBay cannot fetch/verify the product image URL
        if (
            str_contains($msg, 'image') && (
                str_contains($msg, 'not accessible') ||
                str_contains($msg, 'could not be downloaded') ||
                str_contains($msg, 'invalid image')
            )
        ) {
            return "eBay could not access the product image. "
                . "Ensure the image URL is publicly reachable over HTTPS and returns a valid JPEG/PNG. "
                . "Check that the storage symlink is set up on the server (php artisan storage:link).";
        }
        // ─────────────────────────────────────────────────────────────────────────

        if (str_contains($msg, 'inventory item upsert failed')) {
            return $this->withEbayDetail($e, 'eBay rejected the product data (inventory item). Check SKU, title length, and image URLs.');
        }

        if (str_contains($msg, 'offer create failed') || str_contains($msg, 'offer update failed')) {
            return $this->withEbayDetail($e, 'eBay rejected the listing offer. Check category ID and listing policy IDs.');
        }

        if (str_contains($msg, 'offer update (syncFull) failed')) {
            return $this->withEbayDetail($e, 'eBay offer update failed during sync. Check category ID and policy IDs.');
        }

        if (str_contains($msg, 'offer publish failed')) {
            return $this->withEbayDetail($e, 'eBay could not publish the listing. The offer may be missing required fields or the category may be restricted.');
        }

        if (str_contains($msg, 'syncInventory failed')) {
            return $this->withEbayDetail($e, 'eBay stock update failed. The listing may have ended or been removed on eBay.');
        }

        if (str_contains($msg, 'connection test failed')) {
            return $this->withEbayDetail($e, 'eBay connection test failed. Verify credentials are correct and the seller account is still connected.');
        }

        if (str_contains($msg, 'getListingStatus failed')) {
            return $this->withEbayDetail($e, 'eBay status check failed. The listing may no longer exist on eBay.');
        }

        // Inventory indexing race condition — eBay errorId 25751
        // waitForInventoryItem() throws this when PUT inventory_item is not yet readable
        if (str_contains($msg, 'error 25751') || str_contains($msg, 'was not available after')) {
            return 'eBay inventory item not yet indexed — please retry in a few seconds (error 25751). '
                . 'eBay needs a moment to index newly-uploaded inventory before an offer can be created.';
        }

        // Surface sanitised eBay API errors (already safe — originate from our service)
        if (str_contains($msg, 'eBay')) {
            return $msg;
        }

        return 'eBay operation failed. Check the eBay logs for details.';
    }

    /**
     * Append the actual eBay error message/errorId to a base string.
     * This surfaces the raw eBay rejection reason so operators know exactly
     * what to fix — instead of seeing a generic "check your config" message.
     */
    private function withEbayDetail(\Throwable $e, string $base): string
    {
        $errors = $this->extractEbayErrors($e);

        if (empty($errors)) {
            return $base;
        }

        $parts = [];
        foreach ($errors as $err) {
            $text = $err['longMessage'] ?? $err['message'] ?? null;
            if ($text) {
                $id     = isset($err['errorId']) ? " [errorId {$err['errorId']}]" : '';
                $parts[] = $text . $id;
            }
        }

        if (empty($parts)) {
            return $base;
        }

        return $base . ' eBay said: "' . implode(' | ', $parts) . '".';
    }
}
