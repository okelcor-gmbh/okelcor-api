<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\QuoteRequest;
use App\Models\SavedFitment;
use App\Models\SearchEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * What customers are looking for, as opposed to what they bought.
 *
 * The dashboard could already answer "what sold". The questions this answers
 * are the ones that change what the business does next:
 *
 *   - what was searched for and NOT found (a stocking or naming decision);
 *   - what is in demand but out of stock (a purchasing decision);
 *   - which sizes and brands people ask for (a range decision);
 *   - how far demand gets — searched, then asked about, then ordered.
 *
 * Every figure is computed in SQL from real rows. Nothing here is estimated,
 * and nothing is passed to a model to invent: AdminInsightsService is handed
 * these numbers as facts to restate, the same rule the stockout forecast
 * already follows.
 *
 * WHAT THIS CANNOT SEE, and no amount of work here would change: page views,
 * scroll depth, click paths, time on page, which product cards were looked at
 * without being clicked. Those never reach the API. They belong to a frontend
 * analytics product, and claiming them here would mean inventing them.
 */
class CustomerBehaviourService
{
    /** Nothing below this many occurrences is a pattern; it is one person. */
    private const MIN_OCCURRENCES = 2;

    private const TOP_N = 15;

    /**
     * @return array<string, mixed>
     */
    public function report(Carbon $from, Carbon $to): array
    {
        if (! Schema::hasTable('search_events')) {
            // Deploy-order safety, and an honest empty state: the reporting
            // table may not exist yet, and an empty report is not the same
            // claim as "nobody searched for anything".
            return $this->unavailable($from, $to);
        }

        $totals = $this->totals($from, $to);

        return [
            'range' => [
                'from' => $from->toIso8601String(),
                'to'   => $to->toIso8601String(),
                'days' => max(1, $from->diffInDays($to)),
            ],
            'available' => true,
            'summary'   => $totals,
            'daily'                 => $this->daily($from, $to),
            'top_searches'          => $this->topSearches($from, $to),
            'unmet_demand'          => $this->unmetDemand($from, $to),
            'demand_vs_stock'       => $this->demandVsStock($from, $to),
            'brand_demand'          => $this->dimension('brand', $from, $to),
            'size_demand'           => $this->sizeDemand($from, $to),
            'category_demand'       => $this->dimension('category', $from, $to),
            'season_demand'         => $this->dimension('season', $from, $to),
            'countries'             => $this->dimension('country', $from, $to),
            'saved_fitments'        => $this->savedFitmentDemand(),
            'funnel'                => $this->funnel($from, $to),
            'signed_in_share'       => $this->signedInShare($from, $to),
        ];
    }

    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function totals(Carbon $from, Carbon $to): array
    {
        $row = $this->scope($from, $to)
            ->selectRaw('COUNT(*) as searches')
            ->selectRaw('COUNT(DISTINCT visitor_hash) as visitors')
            ->selectRaw('SUM(CASE WHEN has_results = 0 THEN 1 ELSE 0 END) as empty_searches')
            ->selectRaw('AVG(results_count) as avg_results')
            ->first();

        $searches = (int) ($row->searches ?? 0);
        $empty    = (int) ($row->empty_searches ?? 0);

        return [
            'searches'        => $searches,
            'visitors'        => (int) ($row->visitors ?? 0),
            'empty_searches'  => $empty,
            // The single number worth watching. A rising no-results rate means
            // people are asking for things the catalogue does not answer.
            'empty_rate'      => $searches > 0 ? round($empty / $searches * 100, 1) : 0.0,
            'avg_results'     => round((float) ($row->avg_results ?? 0), 1),
        ];
    }

    /**
     * A day-by-day series, ready to plot without the client filling gaps.
     *
     * Days with no traffic are emitted as zero rather than omitted — a gap in a
     * chart reads as missing data, which is a different claim from "nobody
     * searched that day".
     *
     * @return array<int, array<string, mixed>>
     */
    private function daily(Carbon $from, Carbon $to): array
    {
        $rows = $this->scope($from, $to)
            ->selectRaw('DATE(created_at) as day')
            ->selectRaw('COUNT(*) as searches')
            ->selectRaw('COUNT(DISTINCT visitor_hash) as visitors')
            ->selectRaw('SUM(CASE WHEN has_results = 0 THEN 1 ELSE 0 END) as empty_searches')
            ->groupBy('day')
            ->pluck('searches', 'day');

        $visitors = $this->scope($from, $to)
            ->selectRaw('DATE(created_at) as day')
            ->selectRaw('COUNT(DISTINCT visitor_hash) as visitors')
            ->groupBy('day')
            ->pluck('visitors', 'day');

        $empty = $this->scope($from, $to)
            ->foundNothing()
            ->selectRaw('DATE(created_at) as day')
            ->selectRaw('COUNT(*) as c')
            ->groupBy('day')
            ->pluck('c', 'day');

        $series = [];

        for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
            $key = $day->toDateString();

            $series[] = [
                'date'           => $key,
                'searches'       => (int) ($rows[$key] ?? 0),
                'visitors'       => (int) ($visitors[$key] ?? 0),
                'empty_searches' => (int) ($empty[$key] ?? 0),
            ];
        }

        return $series;
    }

    /**
     * The most-typed terms, with how often they came back with nothing.
     *
     * @return array<int, array<string, mixed>>
     */
    private function topSearches(Carbon $from, Carbon $to): array
    {
        return $this->scope($from, $to)
            ->whereNotNull('term')
            ->selectRaw('term')
            ->selectRaw('COUNT(*) as searches')
            ->selectRaw('COUNT(DISTINCT visitor_hash) as visitors')
            ->selectRaw('SUM(CASE WHEN has_results = 0 THEN 1 ELSE 0 END) as empty_searches')
            ->selectRaw('MAX(results_count) as best_results')
            ->groupBy('term')
            ->having('searches', '>=', self::MIN_OCCURRENCES)
            ->orderByDesc('searches')
            ->limit(self::TOP_N)
            ->get()
            ->map(fn ($r) => [
                'term'           => $r->term,
                'searches'       => (int) $r->searches,
                'visitors'       => (int) $r->visitors,
                'empty_searches' => (int) $r->empty_searches,
                'best_results'   => (int) $r->best_results,
            ])
            ->all();
    }

    /**
     * Searched repeatedly, found nothing, every time.
     *
     * The most directly actionable list in this report: each row is either a
     * product to stock or a word the catalogue does not recognise for something
     * it already sells.
     *
     * @return array<int, array<string, mixed>>
     */
    private function unmetDemand(Carbon $from, Carbon $to): array
    {
        return $this->scope($from, $to)
            ->foundNothing()
            ->whereNotNull('term')
            ->selectRaw('term')
            ->selectRaw('COUNT(*) as searches')
            ->selectRaw('COUNT(DISTINCT visitor_hash) as visitors')
            ->selectRaw('MAX(created_at) as last_searched')
            ->groupBy('term')
            ->having('searches', '>=', self::MIN_OCCURRENCES)
            ->orderByDesc('searches')
            ->limit(self::TOP_N)
            ->get()
            ->map(fn ($r) => [
                'term'          => $r->term,
                'searches'      => (int) $r->searches,
                'visitors'      => (int) $r->visitors,
                'last_searched' => Carbon::parse($r->last_searched)->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Brands people ask for, against whether they can actually be bought.
     *
     * A brand searched often whose products are all out of stock is a
     * purchasing decision the sales figures cannot show, because a product
     * nobody could buy sold nothing.
     *
     * @return array<int, array<string, mixed>>
     */
    private function demandVsStock(Carbon $from, Carbon $to): array
    {
        $demand = $this->scope($from, $to)
            ->whereNotNull('brand')
            ->selectRaw('brand')
            ->selectRaw('COUNT(*) as searches')
            ->groupBy('brand')
            ->orderByDesc('searches')
            ->limit(self::TOP_N)
            ->pluck('searches', 'brand');

        if ($demand->isEmpty()) {
            return [];
        }

        $stock = Product::query()
            ->whereIn('brand', $demand->keys())
            ->where('is_active', true)
            ->selectRaw('brand')
            ->selectRaw('COUNT(*) as products')
            ->selectRaw('SUM(CASE WHEN in_stock = 1 THEN 1 ELSE 0 END) as in_stock_products')
            ->groupBy('brand')
            ->get()
            ->keyBy('brand');

        $out = [];

        foreach ($demand as $brand => $searches) {
            $row = $stock->get($brand);

            $products = (int) ($row->products ?? 0);
            $inStock  = (int) ($row->in_stock_products ?? 0);

            $out[] = [
                'brand'             => $brand,
                'searches'          => (int) $searches,
                'products'          => $products,
                'in_stock_products' => $inStock,
                // Named rather than left for the reader to infer, so the list
                // can be sorted and filtered on the thing that matters.
                'status' => match (true) {
                    $products === 0 => 'not_stocked',
                    $inStock === 0  => 'all_out_of_stock',
                    default         => 'available',
                },
            ];
        }

        return $out;
    }

    /**
     * Rim / width / height demand, the numbers a tyre buyer actually orders by.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function sizeDemand(Carbon $from, Carbon $to): array
    {
        return [
            'rim'    => $this->dimension('rim', $from, $to),
            'width'  => $this->dimension('width', $from, $to),
            'height' => $this->dimension('height', $from, $to),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function dimension(string $column, Carbon $from, Carbon $to): array
    {
        return $this->scope($from, $to)
            ->whereNotNull($column)
            ->selectRaw("{$column} as value")
            ->selectRaw('COUNT(*) as searches')
            ->selectRaw('SUM(CASE WHEN has_results = 0 THEN 1 ELSE 0 END) as empty_searches')
            ->groupBy($column)
            ->orderByDesc('searches')
            ->limit(self::TOP_N)
            ->get()
            ->map(fn ($r) => [
                'value'          => (string) $r->value,
                'searches'       => (int) $r->searches,
                'empty_searches' => (int) $r->empty_searches,
            ])
            ->all();
    }

    /**
     * What customers saved to "My Garage" — demand they took the trouble to
     * record, which is a stronger signal than a single search.
     *
     * @return array<int, array<string, mixed>>
     */
    private function savedFitmentDemand(): array
    {
        if (! Schema::hasTable('saved_fitments')) {
            return [];
        }

        return SavedFitment::query()
            ->selectRaw('size')
            ->selectRaw('COUNT(*) as saves')
            ->selectRaw('COUNT(DISTINCT customer_id) as customers')
            ->whereNotNull('size')
            ->groupBy('size')
            ->orderByDesc('saves')
            ->limit(self::TOP_N)
            ->get()
            ->map(fn ($r) => [
                'size'      => (string) $r->size,
                'saves'     => (int) $r->saves,
                'customers' => (int) $r->customers,
            ])
            ->all();
    }

    /**
     * Searched → asked → ordered, over the same window.
     *
     * Deliberately NOT presented as one visitor's journey: searches are
     * anonymous and orders are not, so no row here is joined to another. These
     * are three counts of three populations over one period, and the note in
     * the payload says so. A funnel implying individual progression from data
     * that cannot support it would be a more confident lie than no funnel.
     *
     * @return array<string, mixed>
     */
    private function funnel(Carbon $from, Carbon $to): array
    {
        $searches = $this->scope($from, $to)->count();
        $visitors = (int) $this->scope($from, $to)->distinct()->count('visitor_hash');

        $inquiries = QuoteRequest::whereBetween('created_at', [$from, $to])->count();
        $orders    = Order::whereBetween('created_at', [$from, $to])->count();

        return [
            'searches'  => $searches,
            'visitors'  => $visitors,
            'inquiries' => $inquiries,
            'orders'    => $orders,
            // Rates between populations, not between individuals.
            'inquiry_rate_per_visitor' => $visitors > 0 ? round($inquiries / $visitors * 100, 2) : null,
            'order_rate_per_visitor'   => $visitors > 0 ? round($orders / $visitors * 100, 2) : null,
            'note' => 'Counts of three separate populations over the same period. Searches are anonymous, so no visitor is followed into an inquiry or an order — read these as proportions, not as one person’s journey.',
        ];
    }

    /** @return array<string, mixed> */
    private function signedInShare(Carbon $from, Carbon $to): array
    {
        $total    = $this->scope($from, $to)->count();
        $signedIn = $this->scope($from, $to)->whereNotNull('customer_id')->count();

        return [
            'searches'          => $total,
            'signed_in'         => $signedIn,
            'signed_in_percent' => $total > 0 ? round($signedIn / $total * 100, 1) : 0.0,
        ];
    }

    private function scope(Carbon $from, Carbon $to)
    {
        return SearchEvent::query()->whereBetween('created_at', [$from, $to]);
    }

    /** @return array<string, mixed> */
    private function unavailable(Carbon $from, Carbon $to): array
    {
        return [
            'range'     => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'days' => 0],
            'available' => false,
            'reason'    => 'Search recording is not active yet. Figures will appear once the migration has run and customers have used the catalogue.',
            'summary'   => ['searches' => 0, 'visitors' => 0, 'empty_searches' => 0, 'empty_rate' => 0.0, 'avg_results' => 0.0],
            'daily'     => [],
            'top_searches' => [], 'unmet_demand' => [], 'demand_vs_stock' => [],
            'brand_demand' => [], 'size_demand' => ['rim' => [], 'width' => [], 'height' => []],
            'category_demand' => [], 'season_demand' => [], 'countries' => [],
            'saved_fitments' => [], 'funnel' => null, 'signed_in_share' => null,
        ];
    }

    /**
     * A compact version for the AI insight generator — facts to restate, not
     * numbers to estimate.
     *
     * @return array<string, mixed>
     */
    public function snapshotForInsights(int $days = 14): array
    {
        $to   = Carbon::now();
        $from = $to->copy()->subDays($days)->startOfDay();

        if (! Schema::hasTable('search_events')) {
            return ['available' => false];
        }

        $report = $this->report($from, $to);

        return [
            'available'          => true,
            'window_days'        => $days,
            'searches'           => $report['summary']['searches'],
            'unique_visitors'    => $report['summary']['visitors'],
            'no_result_rate_pct' => $report['summary']['empty_rate'],
            'top_searches'       => array_slice($report['top_searches'], 0, 8),
            'searched_but_never_found' => array_slice($report['unmet_demand'], 0, 8),
            'brands_in_demand_without_stock' => array_values(array_filter(
                $report['demand_vs_stock'],
                fn ($r) => $r['status'] !== 'available'
            )),
            'top_rim_sizes'   => array_slice($report['size_demand']['rim'], 0, 6),
            'inquiries'       => $report['funnel']['inquiries'] ?? 0,
            'orders'          => $report['funnel']['orders'] ?? 0,
        ];
    }
}
