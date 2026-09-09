<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\AdminAuditLogger;
use App\Services\TierPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * The tier pricing tool — the model the team agreed with the order
 * manager: Tyre100's price (cost_price) is the truth, the tier sets the
 * margin, the website price bakes in Stripe's fee, and the eBay price
 * swaps that for the eBay uplift. This controller assigns tiers, previews
 * what the formula would charge, and applies it to the website price.
 * The eBay side needs no endpoint here: every eBay list/update push
 * prices the offer through the same formula (EbaySellingService).
 */
class AdminTierPricingController extends Controller
{
    public function __construct(private readonly TierPricingService $pricing)
    {
    }

    // ── GET /api/v1/admin/pricing/preview — pricing.manage ───────────────────
    public function preview(): JsonResponse
    {
        if (! Schema::hasColumn('products', 'price_tier')) {
            return response()->json([
                'message' => 'Tier pricing is not migrated yet — run the migration first.',
            ], 503);
        }

        $columns = ['id', 'sku', 'brand', 'name', 'size', 'type', 'season', 'stock',
                    'price', 'cost_price', 'price_tier', 'ebay_listed', 'is_active'];
        $hasCostStamp = Schema::hasColumn('products', 'cost_price_updated_at');
        if ($hasCostStamp) {
            $columns[] = 'cost_price_updated_at';
        }

        $rows = Product::orderBy('brand')->orderBy('name')
            ->get($columns)
            ->map(function (Product $p) {
                $cost = $p->cost_price !== null ? (float) $p->cost_price : null;
                $canPrice = $cost !== null && $cost > 0 && $p->price_tier !== null;

                $website = $canPrice ? $this->pricing->websitePrice($cost, $p->price_tier) : null;
                $ebay    = $canPrice ? $this->pricing->ebayPrice($cost, $p->price_tier) : null;

                $currentPrice = (float) $p->price;

                return [
                    'id'            => $p->id,
                    'sku'           => $p->sku,
                    'brand'         => $p->brand,
                    'name'          => $p->name,
                    'size'          => $p->size,
                    'type'          => $p->type,
                    'season'        => $p->season,
                    'stock'         => (int) $p->stock,
                    'is_active'     => (bool) $p->is_active,
                    'ebay_listed'   => (bool) $p->ebay_listed,
                    'tier'          => $p->price_tier,
                    'cost_price'    => $cost,
                    // Null = the cost predates tracking; treat as stale.
                    'cost_updated_at' => $p->cost_price_updated_at?->toIso8601String(),
                    'current_price' => $currentPrice,
                    'website_price' => $website,
                    'ebay_price'    => $ebay,
                    // What applying the formula would do to the site price.
                    'price_change'  => ($website !== null && abs($website - $currentPrice) >= 0.01)
                        ? round($website - $currentPrice, 2)
                        : null,
                ];
            })->values();

        $brands = $rows->pluck('brand')->filter()->unique()->sort()->values();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'counts' => [
                    'total'        => $rows->count(),
                    'missing_tier' => $rows->whereNull('tier')->count(),
                    'missing_cost' => $rows->whereNull('cost_price')->count(),
                    'ready'        => $rows->whereNotNull('website_price')->count(),
                    'would_change' => $rows->whereNotNull('price_change')->count(),
                ],
                'brands'        => $brands,
                'pricing_model' => $this->pricing->model(),
            ],
            'message' => 'success',
        ]);
    }

    // ── POST /api/v1/admin/pricing/set-tier — pricing.manage ─────────────────
    //
    // Assign a tier to ticked products, or to a whole brand at once —
    // tiers are a brand-level judgment in practice (Michelin is premium
    // everywhere), so the brand sweep is the fast path.
    public function setTier(Request $request): JsonResponse
    {
        if (! Schema::hasColumn('products', 'price_tier')) {
            return response()->json([
                'message' => 'Tier pricing is not migrated yet — run the migration first.',
            ], 503);
        }

        $data = $request->validate([
            'tier'  => ['required', Rule::in(TierPricingService::TIERS)],
            'ids'   => ['required_without:brand', 'array', 'max:1000'],
            'ids.*' => ['integer'],
            'brand' => ['required_without:ids', 'string', 'max:190'],
        ]);

        $query = Product::query();
        if (! empty($data['ids'])) {
            $query->whereIn('id', $data['ids']);
        } else {
            $query->where('brand', $data['brand']);
        }

        $updated = $query->update(['price_tier' => $data['tier']]);

        AdminAuditLogger::info('pricing_tier_assigned', "Tier '{$data['tier']}' assigned to {$updated} product(s)", $request, $request->user(), [
            'tier'  => $data['tier'],
            'brand' => $data['brand'] ?? null,
            'count' => $updated,
        ]);

        return response()->json([
            'data'    => ['updated' => $updated],
            'message' => "{$updated} product(s) set to " . $data['tier'] . '.',
        ]);
    }

    // ── POST /api/v1/admin/pricing/apply — pricing.manage ────────────────────
    //
    // Write the formula's website price into products.price — ticked ids,
    // or every product the formula can price (`all: true`). Only rows
    // with a cost price AND a tier are touched; the rest are reported,
    // never guessed. eBay offers are NOT pushed here — the next eBay
    // list/update carries the eBay-formula price automatically.
    public function apply(Request $request): JsonResponse
    {
        if (! Schema::hasColumn('products', 'price_tier')) {
            return response()->json([
                'message' => 'Tier pricing is not migrated yet — run the migration first.',
            ], 503);
        }

        $data = $request->validate([
            'ids'   => ['required_without:all', 'array', 'max:1000'],
            'ids.*' => ['integer'],
            'all'   => ['required_without:ids', 'boolean'],
        ]);

        $products = Product::query()
            ->when(! empty($data['ids']), fn ($q) => $q->whereIn('id', $data['ids']))
            ->get();

        $updated = 0;
        $skipped = [];

        foreach ($products as $product) {
            $cost = $product->cost_price !== null ? (float) $product->cost_price : null;

            if ($cost === null || $cost <= 0 || $product->price_tier === null) {
                // In `all` mode an unpriceable row is simply not in scope —
                // only report it when it was explicitly asked for.
                if (! empty($data['ids'])) {
                    $skipped[] = [
                        'id'     => $product->id,
                        'sku'    => $product->sku,
                        'reason' => $product->price_tier === null ? 'No tier assigned.' : 'No cost (Tyre100) price.',
                    ];
                }
                continue;
            }

            $website = $this->pricing->websitePrice($cost, $product->price_tier);
            if ($website === null || $website <= 0) {
                continue;
            }

            if (abs($website - (float) $product->price) >= 0.01) {
                $product->update(['price' => $website]);
                $updated++;
            }
        }

        AdminAuditLogger::warning('pricing_formula_applied', "Tier pricing formula applied: {$updated} website price(s) updated", $request, $request->user(), [
            'updated_count' => $updated,
            'skipped_count' => count($skipped),
            'scope'         => ! empty($data['ids']) ? 'ids' : 'all',
        ]);

        return response()->json([
            'data' => [
                'updated' => $updated,
                'skipped' => $skipped,
            ],
            'meta'    => ['updated_count' => $updated, 'skipped_count' => count($skipped)],
            'message' => "{$updated} website price(s) updated from the tier formula.",
        ]);
    }

    // ── POST /api/v1/admin/pricing/import-costs — pricing.manage ─────────────
    //
    // The Tyre100 price refresh, until their API exists: a CSV with a sku
    // (or ean) column and a cost column updates cost_price ONLY, stamping
    // when each cost was last confirmed against Tyre100. Nothing else on
    // the product is touched, so this file can come straight from a
    // massaged Tyre100 price list.
    public function importCosts(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'extensions:csv,txt', 'max:20480'],
        ]);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        if ($handle === false) {
            return response()->json(['message' => 'Could not read the file.'], 422);
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            return response()->json(['message' => 'The file is empty.'], 422);
        }

        // Tolerant header matching: sku/artikel/article, ean, cost/price/preis/ek.
        $map = [];
        foreach ($header as $i => $h) {
            $h = strtolower(trim((string) $h));
            if (in_array($h, ['sku', 'artikel', 'artikelnummer', 'article', 'article_number'], true)) $map['sku'] = $i;
            if ($h === 'ean') $map['ean'] = $i;
            if (in_array($h, ['cost', 'cost_price', 'price', 'preis', 'ek', 'ek_preis', 'net_price'], true)) $map['cost'] ??= $i;
        }

        if (! isset($map['cost']) || (! isset($map['sku']) && ! isset($map['ean']))) {
            fclose($handle);
            return response()->json([
                'message' => 'The file needs a sku (or ean) column and a cost column. Found: ' . implode(', ', array_map('strval', $header)),
            ], 422);
        }

        $hasStamp = Schema::hasColumn('products', 'cost_price_updated_at');
        $updated = 0;
        $confirmed = 0;
        $unmatched = [];
        $rowNo = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNo++;
            $sku  = isset($map['sku']) ? trim((string) ($row[$map['sku']] ?? '')) : '';
            $ean  = isset($map['ean']) ? trim((string) ($row[$map['ean']] ?? '')) : '';
            $cost = str_replace(',', '.', trim((string) ($row[$map['cost']] ?? '')));

            if (($sku === '' && $ean === '') || $cost === '' || ! is_numeric($cost) || (float) $cost <= 0) {
                continue;
            }

            $product = null;
            if ($sku !== '') {
                $product = Product::where('sku', $sku)->first();
            }
            if (! $product && $ean !== '') {
                $product = Product::where('ean', $ean)->first();
            }

            if (! $product) {
                if (count($unmatched) < 50) {
                    $unmatched[] = ['row' => $rowNo, 'ref' => $sku !== '' ? $sku : $ean];
                }
                continue;
            }

            $changed = $product->cost_price === null || abs((float) $product->cost_price - (float) $cost) >= 0.01;
            $attrs = ['cost_price' => round((float) $cost, 2)];
            if ($hasStamp) {
                $attrs['cost_price_updated_at'] = now();
            }
            $product->update($attrs);
            $changed ? $updated++ : $confirmed++;
        }
        fclose($handle);

        AdminAuditLogger::info('pricing_costs_imported', "Tyre100 costs imported: {$updated} changed, {$confirmed} confirmed current", $request, $request->user(), [
            'updated'   => $updated,
            'confirmed' => $confirmed,
            'unmatched' => count($unmatched),
        ]);

        return response()->json([
            'data' => [
                'updated'   => $updated,
                'confirmed' => $confirmed,
                'unmatched' => $unmatched,
            ],
            'message' => "{$updated} cost(s) updated, {$confirmed} confirmed already current.",
        ]);
    }
}
