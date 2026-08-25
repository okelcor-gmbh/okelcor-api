<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncEbayLiveListingsJob;
use App\Models\EbayListingLog;
use App\Models\EbayLiveListing;
use App\Models\Product;
use App\Services\AdminAuditLogger;
use App\Services\EbaySellingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The eBay pricing audit — built after the business discovered eBay sales
 * were loss-making. For every listed product it answers, from data we
 * already hold: what do we sell it for, what did it cost us, what does
 * eBay take, and what is actually left — flagging every loss-maker and
 * thin margin, alongside what the product REALLY sold for (synced eBay
 * orders) and a suggested price that reaches the target margin after fees.
 *
 * Market comparison per product reuses the existing supplier-intel
 * endpoint (GET /admin/supplier/for-product/{id}) — the panel calls it
 * lazily per row rather than this endpoint hammering the Browse API for
 * the whole catalogue in one request.
 */
class AdminEbayAuditController extends Controller
{
    public function __construct(private readonly EbaySellingService $ebay)
    {
    }

    // ── GET /api/v1/admin/ebay/audit — ebay.manage ───────────────────────────
    public function index(): JsonResponse
    {
        $feePercent    = (float) config('services.ebay_sell.fee_percent', 11.0);
        $feeFixed      = (float) config('services.ebay_sell.fee_fixed', 0.35);
        $thinPercent   = (float) config('services.ebay_sell.thin_margin_percent', 8.0);
        $targetPercent = (float) config('services.ebay_sell.target_margin_percent', 15.0);

        $products = Product::where('ebay_listed', true)
            ->orderBy('brand')->orderBy('name')
            ->get([
                'id', 'sku', 'brand', 'name', 'size', 'type', 'season',
                'price', 'price_b2b', 'price_b2c', 'cost_price', 'stock',
                'ebay_item_id', 'ebay_status', 'ebay_last_synced_at', 'ebay_sync_error',
            ]);

        // What each SKU actually sold for on eBay in the last 90 days —
        // list price is the intention, this is the evidence.
        $sold = collect();
        try {
            $sold = DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.source', 'ebay')
                ->where('orders.created_at', '>=', now()->subDays(90))
                ->whereIn('order_items.sku', $products->pluck('sku')->filter()->values())
                ->groupBy('order_items.sku')
                ->select(
                    'order_items.sku',
                    DB::raw('SUM(order_items.quantity) as units'),
                    DB::raw('AVG(order_items.unit_price) as avg_price'),
                    DB::raw('MAX(orders.created_at) as last_sold_at'),
                )
                ->get()
                ->keyBy('sku');
        } catch (\Throwable $e) {
            Log::warning('EbayAudit: sold-stats query failed', ['error' => $e->getMessage()]);
        }

        // The live snapshot: what eBay is actually showing buyers right now
        // (refreshed by the sync-live action). Graceful pre-migration.
        $live          = collect();
        $liveFetchedAt = null;
        try {
            $live          = EbayLiveListing::all()->keyBy('sku');
            $liveFetchedAt = $live->max('fetched_at')?->toIso8601String();
        } catch (\Throwable) {
        }

        $rows = $products->map(function (Product $p) use ($sold, $live, $liveFetchedAt, $feePercent, $feeFixed, $thinPercent, $targetPercent) {
            $liveRow = $p->sku ? $live->get($p->sku) : null;

            // The margin is judged on the price buyers actually see when we
            // have it — the DB price is only the intention.
            $price = $liveRow?->price !== null && $liveRow !== null ? (float) $liveRow->price : (float) $p->price;
            $cost  = $p->cost_price !== null ? (float) $p->cost_price : null;

            $fee = round($price * $feePercent / 100 + $feeFixed, 2);
            $net = $cost !== null ? round($price - $fee - $cost, 2) : null;
            $netPercent = ($net !== null && $price > 0) ? round($net / $price * 100, 1) : null;

            $verdict = match (true) {
                $cost === null            => 'missing_cost',
                $net < 0                  => 'loss',
                $netPercent < $thinPercent => 'thin',
                default                   => 'healthy',
            };

            // The price at which, after % + fixed fees, the target margin
            // (as % of price) is left: price = (cost + fixed) / (1 - fee% - target%).
            $suggested = null;
            $divisor   = 1 - ($feePercent + $targetPercent) / 100;
            if ($cost !== null && $divisor > 0) {
                $suggested = round(($cost + $feeFixed) / $divisor, 2);
            }

            $soldRow = $p->sku ? $sold->get($p->sku) : null;

            $dbPrice = (float) $p->price;
            // Drift: eBay shows one price, our system believes another.
            $priceDrift = ($liveRow !== null && $liveRow->price !== null && abs((float) $liveRow->price - $dbPrice) >= 0.01)
                ? round((float) $liveRow->price - $dbPrice, 2)
                : null;

            return [
                'id'            => $p->id,
                'sku'           => $p->sku,
                'brand'         => $p->brand,
                'name'          => $p->name,
                'size'          => $p->size,
                'type'          => $p->type,
                'season'        => $p->season,
                'stock'         => $p->stock,
                'ebay_price'    => $price,
                'db_price'      => $dbPrice,
                'live'          => $liveRow ? [
                    'price'      => $liveRow->price !== null ? (float) $liveRow->price : null,
                    'currency'   => $liveRow->currency,
                    'status'     => $liveRow->status,
                    'quantity'   => $liveRow->quantity,
                    'listing_id' => $liveRow->listing_id,
                ] : null,
                // A snapshot exists but this "listed" product is not in it —
                // eBay is not actually showing it.
                'live_missing'  => $liveFetchedAt !== null && $liveRow === null,
                'price_drift'   => $priceDrift,
                'cost_price'    => $cost,
                'price_b2b'     => $p->price_b2b !== null ? (float) $p->price_b2b : null,
                'price_b2c'     => $p->price_b2c !== null ? (float) $p->price_b2c : null,
                'fee_estimate'  => $fee,
                'net_margin'    => $net,
                'net_margin_pct' => $netPercent,
                'verdict'       => $verdict,
                'suggested_price' => $suggested,
                'sold_90d'      => $soldRow ? [
                    'units'        => (int) $soldRow->units,
                    'avg_price'    => round((float) $soldRow->avg_price, 2),
                    'last_sold_at' => $soldRow->last_sold_at,
                ] : null,
                'ebay_status'   => $p->ebay_status,
                'ebay_item_id'  => $p->ebay_item_id,
                'ebay_sync_error' => $p->ebay_sync_error,
            ];
        })->values();

        $estimatedLossPerSale = round(
            $rows->where('verdict', 'loss')->sum(fn ($r) => abs($r['net_margin'])),
            2
        );

        // Live eBay listings whose SKU matches nothing in the catalogue —
        // selling on eBay outside the system entirely.
        $knownSkus = $products->pluck('sku')->filter()->flip();
        $unmatched = $live->values()
            ->filter(fn ($l) => ! isset($knownSkus[$l->sku]))
            ->map(fn ($l) => [
                'sku'        => $l->sku,
                'title'      => $l->title,
                'price'      => $l->price !== null ? (float) $l->price : null,
                'currency'   => $l->currency,
                'status'     => $l->status,
                'quantity'   => $l->quantity,
                'listing_id' => $l->listing_id,
            ])->values();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'counts' => [
                    'listed'       => $rows->count(),
                    'loss'         => $rows->where('verdict', 'loss')->count(),
                    'thin'         => $rows->where('verdict', 'thin')->count(),
                    'missing_cost' => $rows->where('verdict', 'missing_cost')->count(),
                    'healthy'      => $rows->where('verdict', 'healthy')->count(),
                    'price_drift'  => $rows->whereNotNull('price_drift')->count(),
                    'live_missing' => $rows->where('live_missing', true)->count(),
                    'unmatched'    => $unmatched->count(),
                ],
                // If every loss-maker sold once at today's price, this is
                // the money burned. The headline number for the meeting.
                'loss_per_full_sale' => $estimatedLossPerSale,
                'live' => [
                    'fetched_at'    => $liveFetchedAt,
                    'total_on_ebay' => $live->count(),
                ],
                'unmatched_listings' => $unmatched,
                'fee_model' => [
                    'fee_percent'           => $feePercent,
                    'fee_fixed'             => $feeFixed,
                    'thin_margin_percent'   => $thinPercent,
                    'target_margin_percent' => $targetPercent,
                ],
            ],
            'message' => 'success',
        ]);
    }

    // ── POST /api/v1/admin/ebay/audit/sync-live — ebay.manage + ebay-sync ────
    //
    // Refreshes the live snapshot from eBay itself. One offer call per SKU
    // makes it minutes-long, so it runs as a job: queued on a real driver,
    // after-the-response on sync (same pattern as bulk email). The board
    // shows meta.live.fetched_at — refresh the page when it moves.
    public function syncLive(Request $request): JsonResponse
    {
        if (config('queue.default') === 'sync') {
            SyncEbayLiveListingsJob::dispatchAfterResponse();
        } else {
            SyncEbayLiveListingsJob::dispatch();
        }

        AdminAuditLogger::info('ebay_live_sync_started', 'eBay live listing snapshot refresh started', $request, $request->user(), []);

        return response()->json([
            'message' => 'Fetching live listings from eBay — this takes a minute or two. Refresh the board to see when the snapshot updates.',
        ], 202);
    }

    // ── POST /api/v1/admin/ebay/audit/{id}/apply-price — ebay.manage ─────────
    //
    // The audited correction, in one atomic step: set the product's price
    // and push it to the live eBay offer. The old and new price are written
    // to the ebay listing log, so every audit correction is traceable.
    //
    // NOTE the coupling, on purpose: `price` is both the website retail
    // price and the eBay offer price (buildOfferBody reads product->price).
    // The meeting asked for exactly this — audit once, correct both.
    public function applyPrice(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'price' => ['required', 'numeric', 'min:0.5', 'max:100000'],
        ]);

        $product = Product::findOrFail($id);

        if (! $product->ebay_listed) {
            return response()->json(['message' => 'Product is not listed on eBay.'], 422);
        }

        $oldPrice = (float) $product->price;
        $newPrice = round((float) $data['price'], 2);

        $product->update(['price' => $newPrice]);

        try {
            $result = $this->ebay->updateListing($product);
        } catch (\Throwable $e) {
            // eBay refused: put the price back — a corrected website price
            // with a stale eBay offer is exactly the inconsistency this
            // audit exists to remove.
            $product->update(['price' => $oldPrice]);

            return response()->json([
                'message' => 'eBay rejected the price update — nothing was changed. ' . mb_substr($e->getMessage(), 0, 200),
                'code'    => 'ebay_update_failed',
            ], 502);
        }

        $product->update([
            'ebay_offer_id'       => $result['offer_id'] ?? $product->ebay_offer_id,
            'ebay_status'         => 'active',
            'ebay_last_synced_at' => now(),
            'ebay_sync_error'     => null,
        ]);

        try {
            EbayListingLog::create([
                'product_id'      => $product->id,
                'admin_user_id'   => $request->user()->id,
                'sku'             => $product->sku,
                'action'          => 'audit_price_change',
                'ebay_item_id'    => $product->ebay_item_id,
                'ebay_offer_id'   => $product->ebay_offer_id,
                'status'          => 'active',
                'payload_summary' => ['old_price' => $oldPrice, 'new_price' => $newPrice],
            ]);
        } catch (\Throwable $e) {
            Log::warning('EbayAudit: listing log write failed', ['error' => $e->getMessage()]);
        }

        AdminAuditLogger::warning('ebay_price_corrected', "eBay price corrected for {$product->sku}: {$oldPrice} → {$newPrice}", $request, $request->user(), [
            'product_id' => $product->id,
            'old_price'  => $oldPrice,
            'new_price'  => $newPrice,
        ]);

        return response()->json([
            'data'    => ['id' => $product->id, 'price' => $newPrice],
            'message' => "Price updated on the site and on eBay: {$product->sku} → " . number_format($newPrice, 2) . ' €.',
        ]);
    }
}
