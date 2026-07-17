<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\EbayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Supplier intelligence — competitor/market price lookups to sanity-check
 * Okelcor's own pricing and sourcing decisions.
 *
 * Tyre-type aware: Okelcor sells four genuinely different tyre categories
 * (Product::type — pcr/tbr/used/otr), and they don't all belong on the same
 * search channel. eBay is a real, relevant channel for PCR and Used (Okelcor
 * itself sells there — see services.ebay_sell config) and still turns up
 * results for TBR, but OTR (off-the-road: loader/earthmover tyres) is
 * essentially never sold there — it's a freight-quote, B2B-wholesale
 * category. Rather than return a near-empty/junk eBay result for OTR, that
 * lookup is skipped entirely and only the wholesale marketplace links are
 * returned, with an explanation.
 */
class SupplierController extends Controller
{
    private const TYPES = ['pcr', 'tbr', 'used', 'otr'];

    public function __construct(private EbayService $ebay) {}

    /**
     * GET /api/v1/admin/supplier/search?q={query}&limit={limit}&type={pcr|tbr|used|otr}
     */
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'q'     => ['required', 'string', 'min:2', 'max:200'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'type'  => ['sometimes', 'nullable', Rule::in(self::TYPES)],
        ]);

        return response()->json($this->runSearch(
            $request->q,
            (int) $request->input('limit', 20),
            $request->input('type'),
        ));
    }

    /**
     * GET /api/v1/admin/supplier/for-product/{id}
     *
     * Convenience lookup: builds the search directly from one of Okelcor's
     * own catalogue products (brand + size + type), so nobody has to
     * hand-copy a product's spec string into the free-text search. Also
     * includes Okelcor's own current prices alongside the market summary,
     * so the comparison ("are we priced sensibly against what's out
     * there?") doesn't need a second lookup.
     */
    public function forProduct(int $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $type    = strtolower($product->type);
        $query   = trim($product->brand . ' ' . $product->size);

        $result = $this->runSearch($query, 20, $type);
        $result['your_product'] = [
            'id'         => $product->id,
            'sku'        => $product->sku,
            'brand'      => $product->brand,
            'name'       => $product->name,
            'size'       => $product->size,
            'type'       => $product->type,
            'price'      => (float) $product->price,
            'price_b2b'  => $product->price_b2b !== null ? (float) $product->price_b2b : null,
            'price_b2c'  => $product->price_b2c !== null ? (float) $product->price_b2c : null,
        ];

        if ($result['summary']['avg_price'] !== null) {
            $result['your_product']['price_vs_market_pct'] = round(
                (((float) $product->price - $result['summary']['avg_price']) / $result['summary']['avg_price']) * 100,
                1
            );
        }

        return response()->json($result);
    }

    /**
     * GET /api/v1/admin/supplier/alibaba-link?q={query}
     */
    public function alibabaLink(Request $request): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'min:2', 'max:200']]);

        return response()->json([
            'data'    => ['url' => $this->alibabaUrl($request->q)],
            'message' => 'success',
        ]);
    }

    /**
     * GET /api/v1/admin/supplier/made-in-china-link?q={query}
     *
     * Made-in-China is the other major B2B wholesale sourcing marketplace
     * commonly used alongside Alibaba for exactly this category (bulk
     * TBR/OTR from Chinese manufacturers) — genuinely the stronger channel
     * for those two types, where eBay has little to offer.
     */
    public function madeInChinaLink(Request $request): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'min:2', 'max:200']]);

        return response()->json([
            'data'    => ['url' => $this->madeInChinaUrl($request->q)],
            'message' => 'success',
        ]);
    }

    /**
     * Shared by search() and forProduct(). Returns eBay listings (skipped
     * for OTR — see class docblock) plus both wholesale marketplace links
     * and a price summary, in one response.
     */
    private function runSearch(string $query, int $limit, ?string $type): array
    {
        $marketplaceLinks = [
            'alibaba'        => $this->alibabaUrl($query),
            'made_in_china'  => $this->madeInChinaUrl($query),
        ];

        if ($type === 'otr') {
            return [
                'data'              => [],
                'marketplace_links' => $marketplaceLinks,
                'summary'           => $this->emptySummary(),
                'note'              => 'OTR (off-the-road) tyres are rarely listed on eBay — this is a '
                    . 'freight-quote, wholesale-sourcing category. Skipped the eBay lookup; use the '
                    . 'marketplace links instead.',
                'message'           => 'success',
            ];
        }

        try {
            $results = $this->ebay->searchTyres($query, $limit, $type);
        } catch (\Throwable $e) {
            return [
                'data'              => [],
                'marketplace_links' => $marketplaceLinks,
                'summary'           => $this->emptySummary(),
                'message'           => 'eBay search unavailable: ' . $e->getMessage(),
            ];
        }

        return [
            'data'              => $results,
            'marketplace_links' => $marketplaceLinks,
            'summary'           => $this->summarize($results),
            'meta'              => ['total' => count($results)],
            'message'           => 'success',
        ];
    }

    private function summarize(array $results): array
    {
        $prices = array_values(array_filter(
            array_map(fn ($r) => $r['price'] !== null ? (float) $r['price'] : null, $results),
            fn ($p) => $p !== null
        ));

        if (! $prices) {
            return $this->emptySummary();
        }

        return [
            'count'      => count($prices),
            'currency'   => collect($results)->pluck('currency')->filter()->first(),
            'min_price'  => round(min($prices), 2),
            'max_price'  => round(max($prices), 2),
            'avg_price'  => round(array_sum($prices) / count($prices), 2),
        ];
    }

    private function emptySummary(): array
    {
        return ['count' => 0, 'currency' => null, 'min_price' => null, 'max_price' => null, 'avg_price' => null];
    }

    private function alibabaUrl(string $query): string
    {
        return 'https://www.alibaba.com/trade/search?SearchText=' . urlencode($query);
    }

    private function madeInChinaUrl(string $query): string
    {
        return 'https://www.made-in-china.com/products-search/hot-china-products/' . urlencode($query) . '.html';
    }
}
