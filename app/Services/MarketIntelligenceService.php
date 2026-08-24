<?php

namespace App\Services;

use App\Models\MarketReferenceStat;
use App\Support\CountryNormaliser;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per market, built from every signal this system already holds.
 *
 * The question this answers is the business one — *which market should
 * Okelcor push into next* — rather than the developer one the behaviour
 * report answers. It joins five sources that have never been joined:
 *
 *   search_events       demand      what people looked for, and what they
 *                                   looked for and did NOT find
 *   quote_requests      intent      who asked for a price
 *   orders              revenue     who actually bought, and for how much
 *   customers           accounts    who has an account
 *   marketing_contacts  reach       whether Okelcor can even talk to that
 *                                   market yet
 *
 * The whole thing rests on CountryNormaliser: those five tables store country
 * three different ways, and joined raw, Germany becomes four markets with a
 * quarter of the numbers each. Values that cannot be resolved are returned in
 * `unrecognised` rather than dropped — a market missing from this report
 * because somebody typed "Deutschland " must be visible as a data problem,
 * not absent.
 *
 * Deliberately NOT a single 0-100 score. A score invites an argument about
 * weightings and hides why a market ranks where it does. Each market instead
 * gets a named `signal` describing the state it is in, and every state maps
 * to a different action — "nobody can buy what they searched for" and "nobody
 * has ever heard of us here" are both weak markets and want opposite work.
 */
class MarketIntelligenceService
{
    /**
     * Thresholds, named and in one place rather than scattered as literals.
     * These are judgement calls, and someone should be able to argue with
     * them without reading the classifier.
     */
    private const MIN_SEARCHES_FOR_DEMAND   = 10;   // below this, one curious visitor
    private const MIN_QUOTES_FOR_INTENT     = 2;    // one inquiry is an anecdote
    private const HIGH_UNMET_RATE           = 0.35; // a third of searches finding nothing
    private const LOW_REACH_CONTACTS        = 5;    // effectively no list for that market

    public function report(Carbon $from, Carbon $to): array
    {
        $searchAvailable = Schema::hasTable('search_events');

        $demand     = $searchAvailable ? $this->demandByCountry($from, $to) : [];
        $quotes     = $this->quotesByCountry($from, $to);
        $orders     = $this->ordersByCountry($from, $to);
        $customers  = $this->customersByCountry();
        $reach      = $this->reachByCountry();
        $reference  = $this->referenceByCountry();

        $unrecognised = $this->collectUnrecognised($from, $to);

        $codes = collect([$demand, $quotes, $orders, $customers, $reach])
            ->flatMap(fn (array $set) => array_keys($set))
            ->unique()
            ->values();

        $markets = $codes
            ->map(fn (string $code) => $this->buildRow(
                $code,
                $demand[$code]    ?? null,
                $quotes[$code]    ?? null,
                $orders[$code]    ?? null,
                $customers[$code] ?? 0,
                $reach[$code]     ?? null,
                $reference[$code] ?? [],
                $searchAvailable,
            ))
            ->sortBy([
                ['priority', 'asc'],            // signal rank first
                ['sort_value', 'desc'],         // then size within that signal
            ])
            ->values()
            ->all();

        // Countries we hold outside data for but have never seen a visitor
        // from. Listed separately and explicitly NOT scored: a zero here
        // means "not measured", and putting it in the ranked table would
        // read as "no demand".
        $observed  = $codes->all();
        $unmeasured = collect(array_keys($reference))
            ->reject(fn (string $code) => in_array($code, $observed, true))
            ->map(fn (string $code) => [
                'country_code' => $code,
                'country'      => CountryNormaliser::name($code),
                'reference'    => $reference[$code],
            ])
            ->values()
            ->all();

        return [
            'available' => true,
            'period'    => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'markets'   => $markets,
            'totals'    => $this->totals($markets),
            'unmeasured'   => $unmeasured,
            'unrecognised' => $unrecognised,
            'signals'      => $this->signalLegend(),
            'meta' => [
                'search_recording' => $searchAvailable,
                'not_covered' => array_values(array_filter([
                    $searchAvailable ? null : 'Search demand is not being recorded yet (the search_events table is missing), so every demand figure below is absent rather than zero.',
                    'Revenue is reported per currency. No exchange rate is applied — a historical order converted at today\'s rate would not be the money that was actually received.',
                    'Markets Okelcor has never been visited from cannot appear here unless outside data has been imported for them. See "unmeasured".',
                    'Page views, click paths and time on page never reach this API and are not part of any figure here.',
                ])),
            ],
        ];
    }

    // ── Row construction ─────────────────────────────────────────────────────

    private function buildRow(
        string $code,
        ?array $demand,
        ?array $quotes,
        ?array $orders,
        int $customers,
        ?array $reach,
        array $reference,
        bool $searchAvailable,
    ): array {
        $searches      = (int) ($demand['searches'] ?? 0);
        $unmet         = (int) ($demand['unmet'] ?? 0);
        $visitors      = (int) ($demand['visitors'] ?? 0);
        $quoteCount    = (int) ($quotes['total'] ?? 0);
        $quotesWon     = (int) ($quotes['converted'] ?? 0);
        $orderCount    = (int) ($orders['count'] ?? 0);
        $contacts      = (int) ($reach['contacts'] ?? 0);

        $unmetRate = $searches > 0 ? round($unmet / $searches, 4) : null;

        $signal = $this->classify(
            searches: $searches,
            unmetRate: $unmetRate,
            quotes: $quoteCount,
            orders: $orderCount,
            contacts: $contacts,
            searchAvailable: $searchAvailable,
        );

        return [
            'country_code' => $code,
            'country'      => CountryNormaliser::name($code),

            'demand' => $searchAvailable ? [
                'searches'        => $searches,
                'visitors'        => $visitors,
                'unmet_searches'  => $unmet,
                'unmet_rate'      => $unmetRate,
                'top_unmet_terms' => $demand['top_unmet'] ?? [],
            ] : null,

            'pipeline' => [
                'quotes'           => $quoteCount,
                'quotes_converted' => $quotesWon,
            ],

            'commercial' => [
                'orders'            => $orderCount,
                'revenue_by_currency' => $orders['revenue'] ?? [],
                'customers'         => $customers,
            ],

            'reach' => [
                'contacts'     => $contacts,
                'market_slugs' => $reach['slugs'] ?? [],
            ],

            'rates' => [
                // Deliberately null rather than 0 when the denominator is
                // zero: "0% converted" and "nobody asked" are different, and
                // a 0% on an empty market makes it look like a failure.
                'quote_to_order'  => $quoteCount > 0 ? round($orderCount / $quoteCount, 4) : null,
                'quote_win_rate'  => $quoteCount > 0 ? round($quotesWon / $quoteCount, 4) : null,
            ],

            'signal'             => $signal['key'],
            'signal_label'       => $signal['label'],
            'recommended_action' => $signal['action'],
            'priority'           => $signal['priority'],

            'reference' => $reference,

            // Used only for ordering within a signal group. Orders outrank
            // quotes outrank searches, so the biggest real market in each
            // state comes first.
            'sort_value' => $orderCount * 10000 + $quoteCount * 100 + $searches,
        ];
    }

    /**
     * Which state is this market in?
     *
     * Ordered most-actionable first. The first matching rule wins, so the
     * sequence is the policy — read it top to bottom.
     */
    private function classify(
        int $searches,
        ?float $unmetRate,
        int $quotes,
        int $orders,
        int $contacts,
        bool $searchAvailable,
    ): array {
        $hasDemand = $searches >= self::MIN_SEARCHES_FOR_DEMAND;
        $hasIntent = $quotes >= self::MIN_QUOTES_FOR_INTENT;

        if ($orders > 0 && ($hasDemand || $hasIntent)) {
            return $this->signal('proven', 1);
        }

        if ($orders > 0) {
            return $this->signal('buying_quietly', 2);
        }

        // Searched for repeatedly and found nothing — a stock/catalogue gap,
        // not a marketing one. Checked before the generic "not converting"
        // because the fix is completely different.
        if ($hasDemand && $unmetRate !== null && $unmetRate >= self::HIGH_UNMET_RATE) {
            return $this->signal('demand_not_served', 3);
        }

        // Demand and/or inquiries, nobody has bought. Something between
        // interest and checkout is stopping them.
        if ($hasIntent || $hasDemand) {
            if ($contacts < self::LOW_REACH_CONTACTS) {
                return $this->signal('interest_no_reach', 4);
            }

            return $this->signal('demand_not_converting', 5);
        }

        // A list exists and produced nothing measurable.
        if ($contacts >= self::LOW_REACH_CONTACTS) {
            return $this->signal($searchAvailable ? 'reach_no_interest' : 'reach_unmeasured', 6);
        }

        return $this->signal('emerging', 7);
    }

    private function signal(string $key, int $priority): array
    {
        $legend = $this->signalLegend();

        return [
            'key'      => $key,
            'label'    => $legend[$key]['label'],
            'action'   => $legend[$key]['action'],
            'priority' => $priority,
        ];
    }

    /**
     * Every state, with the action it implies. Returned in the payload so the
     * UI never has to hardcode this and the two cannot drift apart.
     */
    private function signalLegend(): array
    {
        return [
            'proven' => [
                'label'  => 'Proven market',
                'action' => 'Demand, inquiries and orders all present. Defend it and look for share, not discovery.',
            ],
            'buying_quietly' => [
                'label'  => 'Buying without visible demand',
                'action' => 'Orders exist but little search or inquiry activity — likely repeat or offline business. Worth asking how these customers found Okelcor; that channel may be repeatable.',
            ],
            'demand_not_served' => [
                'label'  => 'Demand Okelcor cannot fill',
                'action' => 'People search here and the catalogue returns nothing. This is a stock or listing gap, not a marketing one — check the unmet terms before spending on campaigns.',
            ],
            'interest_no_reach' => [
                'label'  => 'Interest, no list',
                'action' => 'Real interest and effectively no contacts to talk to. This is the clearest "penetrate" signal on the report: build a contact list for this market first.',
            ],
            'demand_not_converting' => [
                'label'  => 'Demand that stalls',
                'action' => 'Interest and a list exist but nothing is bought. The blocker is between interest and checkout — price, delivery cost, language, or payment method. Worth a conversation, not more traffic.',
            ],
            'reach_no_interest' => [
                'label'  => 'List, no interest',
                'action' => 'Contacts exist and produce no measurable demand. Either the list is wrong for this product or the messaging is not landing. Re-check the list before buying more of it.',
            ],
            'reach_unmeasured' => [
                'label'  => 'List, demand not measured',
                'action' => 'Contacts exist but search recording is not live, so silence here means nothing yet. Revisit once demand is being recorded.',
            ],
            'emerging' => [
                'label'  => 'Too early to say',
                'action' => 'Some signal, below the threshold where it means anything. Watch, do not act.',
            ],
        ];
    }

    // ── Sources ──────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{searches:int, unmet:int, visitors:int, top_unmet:array}>
     */
    private function demandByCountry(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('search_events')
            ->selectRaw('country, COUNT(*) as searches, SUM(CASE WHEN has_results = 0 THEN 1 ELSE 0 END) as unmet, COUNT(DISTINCT visitor_hash) as visitors')
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('country')
            ->groupBy('country')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $code = CountryNormaliser::normalise($row->country);
            if ($code === null) {
                continue;
            }

            $out[$code]['searches'] = ($out[$code]['searches'] ?? 0) + (int) $row->searches;
            $out[$code]['unmet']    = ($out[$code]['unmet'] ?? 0) + (int) $row->unmet;
            $out[$code]['visitors'] = ($out[$code]['visitors'] ?? 0) + (int) $row->visitors;
        }

        foreach ($this->topUnmetTermsByCountry($from, $to) as $code => $terms) {
            if (isset($out[$code])) {
                $out[$code]['top_unmet'] = $terms;
            }
        }

        return $out;
    }

    /**
     * The words people typed here that returned nothing, more than once.
     * A single miss is a typo; a repeated one is a product or a synonym the
     * catalogue does not know.
     */
    private function topUnmetTermsByCountry(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('search_events')
            ->selectRaw('country, term, COUNT(*) as hits')
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('country')
            ->whereNotNull('term')
            ->where('has_results', false)
            ->groupBy('country', 'term')
            ->havingRaw('COUNT(*) >= 2')
            ->orderByDesc('hits')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $code = CountryNormaliser::normalise($row->country);
            if ($code === null || count($out[$code] ?? []) >= 5) {
                continue;
            }
            $out[$code][] = ['term' => $row->term, 'searches' => (int) $row->hits];
        }

        return $out;
    }

    /** @return array<string, array{total:int, converted:int}> */
    private function quotesByCountry(Carbon $from, Carbon $to): array
    {
        $hasQualification = Schema::hasColumn('quote_requests', 'qualification_status');

        $query = DB::table('quote_requests')
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('country');

        $rows = $hasQualification
            ? $query->selectRaw("country, COUNT(*) as total, SUM(CASE WHEN qualification_status = 'converted' THEN 1 ELSE 0 END) as converted")
                ->groupBy('country')->get()
            : $query->selectRaw('country, COUNT(*) as total, 0 as converted')
                ->groupBy('country')->get();

        return $this->foldByCountry($rows, fn ($carry, $row) => [
            'total'     => ($carry['total'] ?? 0) + (int) $row->total,
            'converted' => ($carry['converted'] ?? 0) + (int) $row->converted,
        ]);
    }

    /**
     * Revenue is kept per currency and never converted.
     *
     * Applying today's rate to an order placed three months ago produces a
     * number that is not the money Okelcor received, and a market ranked on
     * it would move when the euro moves. The UI shows the currencies side by
     * side; a business that wants one figure should pick the rate itself.
     *
     * @return array<string, array{count:int, revenue:array<string,float>}>
     */
    private function ordersByCountry(Carbon $from, Carbon $to): array
    {
        $hasCurrency = Schema::hasColumn('orders', 'currency');

        $rows = DB::table('orders')
            ->selectRaw(
                $hasCurrency
                    ? "country, COALESCE(currency, 'EUR') as currency, COUNT(*) as cnt, SUM(total) as revenue"
                    : "country, 'EUR' as currency, COUNT(*) as cnt, SUM(total) as revenue"
            )
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('country')
            ->when(
                Schema::hasColumn('orders', 'status'),
                fn ($q) => $q->where(fn ($w) => $w->whereNull('status')->orWhere('status', '!=', 'cancelled')),
            )
            ->groupBy('country', 'currency')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $code = CountryNormaliser::normalise($row->country);
            if ($code === null) {
                continue;
            }

            $currency = strtoupper((string) $row->currency);
            $out[$code]['count'] = ($out[$code]['count'] ?? 0) + (int) $row->cnt;
            $out[$code]['revenue'][$currency] =
                round(($out[$code]['revenue'][$currency] ?? 0) + (float) $row->revenue, 2);
        }

        return $out;
    }

    /** @return array<string, int> */
    private function customersByCountry(): array
    {
        $rows = DB::table('customers')
            ->selectRaw('country, COUNT(*) as total')
            ->whereNotNull('country')
            ->groupBy('country')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $code = CountryNormaliser::normalise($row->country);
            if ($code !== null) {
                $out[$code] = ($out[$code] ?? 0) + (int) $row->total;
            }
        }

        return $out;
    }

    /**
     * How many people Okelcor can actually email in this market.
     *
     * Counted from the contact's own country, not its market slug — a slug
     * can be a region ("asia"), and a per-country report cannot honestly
     * place those. The slugs are returned alongside so the UI can deep-link
     * into the campaign builder, which filters by them.
     *
     * @return array<string, array{contacts:int, slugs:array<int,string>}>
     */
    private function reachByCountry(): array
    {
        if (! Schema::hasTable('marketing_contacts')) {
            return [];
        }

        $rows = DB::table('marketing_contacts')
            ->selectRaw('country, COUNT(*) as total')
            ->whereNotNull('country')
            ->when(
                Schema::hasColumn('marketing_contacts', 'status'),
                fn ($q) => $q->where(fn ($w) => $w->whereNull('status')->orWhere('status', '!=', 'unsubscribed')),
            )
            ->groupBy('country')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $code = CountryNormaliser::normalise($row->country);
            if ($code !== null) {
                $out[$code]['contacts'] = ($out[$code]['contacts'] ?? 0) + (int) $row->total;
            }
        }

        if (Schema::hasTable('marketing_contact_markets')) {
            $slugRows = DB::table('marketing_contact_markets')
                ->join('marketing_contacts', 'marketing_contacts.id', '=', 'marketing_contact_markets.contact_id')
                ->selectRaw('marketing_contacts.country as country, marketing_contact_markets.market as market')
                ->whereNotNull('marketing_contacts.country')
                ->distinct()
                ->get();

            foreach ($slugRows as $row) {
                $code = CountryNormaliser::normalise($row->country);
                if ($code === null) {
                    continue;
                }
                $slugs = $out[$code]['slugs'] ?? [];
                if (! in_array($row->market, $slugs, true)) {
                    $slugs[] = $row->market;
                }
                $out[$code]['slugs'] = $slugs;
            }
        }

        return $out;
    }

    /** @return array<string, array<int, array>> */
    private function referenceByCountry(): array
    {
        if (! Schema::hasTable('market_reference_stats')) {
            return [];
        }

        return MarketReferenceStat::orderBy('metric')->get()
            ->groupBy('country_code')
            ->map(fn ($stats) => $stats->map(fn (MarketReferenceStat $s) => [
                'metric' => $s->metric,
                'value'  => (float) $s->value,
                'unit'   => $s->unit,
                'period' => $s->period,
                'source' => $s->source,
            ])->values()->all())
            ->all();
    }

    /**
     * Country values that could not be resolved to a code.
     *
     * Returned rather than swallowed: every one of these is business that is
     * missing from the table above, and the only way anyone finds out is if
     * the report says so.
     */
    private function collectUnrecognised(Carbon $from, Carbon $to): array
    {
        $found = [];

        $sources = [
            'orders'             => ['table' => 'orders',             'dated' => true],
            'quote_requests'     => ['table' => 'quote_requests',     'dated' => true],
            'customers'          => ['table' => 'customers',          'dated' => false],
            'marketing_contacts' => ['table' => 'marketing_contacts', 'dated' => false],
        ];

        foreach ($sources as $label => $config) {
            if (! Schema::hasTable($config['table'])) {
                continue;
            }

            $rows = DB::table($config['table'])
                ->selectRaw('country, COUNT(*) as total')
                ->whereNotNull('country')
                ->where('country', '!=', '')
                ->when($config['dated'], fn ($q) => $q->whereBetween('created_at', [$from, $to]))
                ->groupBy('country')
                ->get();

            foreach ($rows as $row) {
                if (CountryNormaliser::normalise($row->country) !== null) {
                    continue;
                }
                $key = $label . '|' . $row->country;
                $found[$key] = [
                    'source' => $label,
                    'value'  => $row->country,
                    'rows'   => (int) $row->total,
                ];
            }
        }

        return array_values($found);
    }

    private function totals(array $markets): array
    {
        $bySignal = [];
        foreach ($markets as $market) {
            $bySignal[$market['signal']] = ($bySignal[$market['signal']] ?? 0) + 1;
        }

        return [
            'markets'   => count($markets),
            'by_signal' => $bySignal,
        ];
    }

    /**
     * Fold rows whose country is free text into ISO-keyed buckets.
     *
     * @param  callable(array, object): array  $merge
     */
    private function foldByCountry($rows, callable $merge): array
    {
        $out = [];
        foreach ($rows as $row) {
            $code = CountryNormaliser::normalise($row->country);
            if ($code === null) {
                continue;
            }
            $out[$code] = $merge($out[$code] ?? [], $row);
        }

        return $out;
    }
}
