<?php

namespace App\Jobs;

use App\Models\EbayLiveListing;
use App\Models\Product;
use App\Services\EbaySellingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Refreshes the ebay_live_listings snapshot from eBay itself. One offer
 * call per SKU makes this minutes-long for a real catalogue, which is why
 * it is a job: on a queue worker it queues, on the sync driver the caller
 * dispatches it after the response (same pattern as the bulk email send).
 */
class SyncEbayLiveListingsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 1800;

    public function handle(EbaySellingService $ebay): void
    {
        @set_time_limit(0);

        $rows = $ebay->fetchAllLiveListings();
        $now  = now();

        // Match into our catalogue by SKU in one query, not one per row.
        $productIds = Product::withTrashed()
            ->whereIn('sku', array_column($rows, 'sku'))
            ->pluck('id', 'sku');

        DB::transaction(function () use ($rows, $productIds, $now) {
            EbayLiveListing::query()->delete();

            foreach (array_chunk($rows, 200) as $chunk) {
                EbayLiveListing::insert(array_map(fn ($r) => [
                    'sku'        => $r['sku'],
                    'title'      => isset($r['title']) ? mb_substr((string) $r['title'], 0, 255) : null,
                    'offer_id'   => $r['offer_id'],
                    'listing_id' => $r['listing_id'],
                    'status'     => mb_substr($r['status'], 0, 30),
                    'price'      => $r['price'],
                    'currency'   => $r['currency'] !== null ? mb_substr($r['currency'], 0, 3) : null,
                    'quantity'   => $r['quantity'],
                    'product_id' => $productIds[$r['sku']] ?? null,
                    'fetched_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }
        });

        Log::info('eBay live listing snapshot refreshed', [
            'listings'  => count($rows),
            'unmatched' => count($rows) - collect($rows)->filter(fn ($r) => isset($productIds[$r['sku']]))->count(),
        ]);
    }
}
