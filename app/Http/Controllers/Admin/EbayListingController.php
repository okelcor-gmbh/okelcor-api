<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
    // POST /admin/ebay/disconnect
    // Deactivates the current token. Does not touch existing eBay listings.
    // -------------------------------------------------------------------------

    public function disconnect(): JsonResponse
    {
        $count = EbayToken::where('is_active', true)->count();

        EbayToken::where('is_active', true)->update(['is_active' => false]);

        // Clear any cached access token so the next call attempts a fresh refresh
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
            ->get(['id', 'sku', 'name', 'brand', 'price', 'stock', 'ebay_listed', 'ebay_item_id']);

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
        $product = Product::findOrFail($id);

        $listingId = $this->ebay->createOrUpdateListing($product);

        $product->update([
            'ebay_listed'  => true,
            'ebay_item_id' => $listingId,
        ]);

        return response()->json([
            'data'    => [
                'listing_id' => $listingId,
                'sku'        => $product->sku,
            ],
            'message' => "Product SKU {$product->sku} listed on eBay (listing #{$listingId}).",
        ]);
    }

    // -------------------------------------------------------------------------
    // DELETE /admin/products/{id}/ebay/remove
    // -------------------------------------------------------------------------

    public function removeListing(int $id): JsonResponse
    {
        $product = Product::findOrFail($id);

        $this->ebay->deleteListing($product->sku);

        $product->update([
            'ebay_listed'  => false,
            'ebay_item_id' => null,
        ]);

        return response()->json([
            'message' => "eBay listing removed for SKU {$product->sku}.",
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /admin/ebay/sync-all
    // -------------------------------------------------------------------------

    public function syncAll(): JsonResponse
    {
        $products = Product::where('ebay_listed', true)->get();

        $synced = 0;
        $errors = [];

        foreach ($products as $product) {
            try {
                $this->ebay->syncInventory($product);
                $synced++;
            } catch (\Throwable $e) {
                $errors[] = "SKU {$product->sku}: {$e->getMessage()}";
            }
        }

        return response()->json([
            'data'    => ['synced' => $synced, 'errors' => $errors],
            'message' => "Synced {$synced} of {$products->count()} listings.",
        ]);
    }
}
