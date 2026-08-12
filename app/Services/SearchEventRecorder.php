<?php

namespace App\Services;

use App\Models\SearchEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Records what was searched for in the public catalogue.
 *
 * Sits on the read path of the busiest public endpoint, so the rules are:
 * never slow the response down materially, never fail it, and never write a row
 * that misrepresents what happened.
 *
 * WHAT IS NOT RECORDED, deliberately: IP addresses, user agents, referrers,
 * page views, click paths, time on page. The first three are personal data this
 * feature has no need for; the last three are a frontend analytics product's
 * job and cannot be seen from here anyway. This records one thing — a query
 * against the catalogue and how many products came back.
 */
class SearchEventRecorder
{
    /**
     * A visitor id supplied by the frontend, when it has consent to keep one.
     * Optional by design — see visitorHash().
     */
    private const VISITOR_HEADER = 'X-Okelcor-Visitor';

    /** Guards the table against a runaway client without dropping real traffic. */
    private const MAX_TERM_LENGTH = 150;

    private static ?bool $tableExists = null;

    /**
     * @param  array<string, mixed>  $filters  the filters actually applied
     */
    public function record(Request $request, array $filters, int $resultsCount): void
    {
        // Deploy-order safety: the code ships before the migration is applied,
        // and a catalogue search must not 500 because a reporting table does
        // not exist yet.
        if (! $this->tableExists()) {
            return;
        }

        // Page 2 of a search is the same search. Counting it again would make
        // any result the user scrolled through look more popular than one they
        // found immediately — the opposite of the truth.
        if ((int) $request->input('page', 1) > 1) {
            return;
        }

        $term = $this->normaliseTerm($request->input('q') ?? $request->input('search'));

        // A request with neither a term nor a filter is the catalogue's empty
        // state, which returns nothing and means nothing.
        if ($term === null && $filters === []) {
            return;
        }

        try {
            SearchEvent::create([
                'term'          => $term,
                'raw_term'      => $this->clip($request->input('q') ?? $request->input('search'), 190),
                'filters'       => $filters,
                'brand'         => $this->clip($filters['brand'] ?? null, 100),
                'category'      => $this->clip($filters['type'] ?? null, 20),
                'season'        => $this->clip($filters['season'] ?? null, 30),
                'width'         => $this->clip($filters['width'] ?? null, 10),
                'height'        => $this->clip($filters['height'] ?? null, 10),
                'rim'           => $this->clip($filters['rim'] ?? null, 10),
                'results_count' => max(0, $resultsCount),
                'has_results'   => $resultsCount > 0,
                'customer_id'   => $this->customerId($request),
                'visitor_hash'  => $this->visitorHash($request),
                'country'       => $this->country($request),
                'locale'        => $this->clip($request->input('locale'), 5),
                'created_at'    => now(),
            ]);
        } catch (\Throwable $e) {
            // A failed write must not fail the customer's search. But it is
            // logged loudly and asserted against the real schema by a test —
            // this codebase has three separate instances of a try/catch around
            // an audit write turning a schema mismatch into years of silence,
            // and this is the same shape.
            Log::warning('[search_events] could not record a search', [
                'error' => $e->getMessage(),
                'term'  => $term,
            ]);
        }
    }

    /**
     * Lowercased, whitespace-collapsed, length-capped — so "225/45R17",
     * " 225/45r17 " and "225/45R17" are one row in a report rather than three.
     */
    public function normaliseTerm(mixed $term): ?string
    {
        if (! is_string($term)) {
            return null;
        }

        $term = preg_replace('/\s+/u', ' ', trim($term)) ?? '';

        if ($term === '') {
            return null;
        }

        return mb_strtolower(mb_substr($term, 0, self::MAX_TERM_LENGTH));
    }

    /**
     * A per-day, salted, one-way digest.
     *
     * Two properties matter and both are deliberate. It cannot be reversed to
     * an identity, and because the day is part of the input it cannot be used
     * to follow the same person across days — so "unique visitors today" is
     * answerable and "everything this person has ever searched" is not.
     *
     * The frontend may send its own opaque id, which is more accurate because
     * every request from the Next.js proxy otherwise carries the proxy's
     * address. It is optional precisely because storing one in a browser is a
     * consent question in the EU, and unique-visitor counts are not worth
     * making that decision on the customer's behalf. Without it, counts still
     * work — they are just coarser.
     */
    private function visitorHash(Request $request): ?string
    {
        $supplied = $request->header(self::VISITOR_HEADER);

        $seed = is_string($supplied) && $supplied !== ''
            ? 'v:' . mb_substr($supplied, 0, 100)
            : 'r:' . $request->ip() . '|' . mb_substr((string) $request->userAgent(), 0, 200);

        if (trim($seed, 'rv:|') === '') {
            return null;
        }

        return hash_hmac('sha256', $seed . '|' . now()->toDateString(), (string) config('app.key'));
    }

    private function customerId(Request $request): ?int
    {
        $customer = $request->user();

        return $customer instanceof \App\Models\Customer ? $customer->id : null;
    }

    /** CDN geo headers, same ones LocaleResolver already trusts. */
    private function country(Request $request): ?string
    {
        foreach (['CF-IPCountry', 'X-Vercel-IP-Country'] as $header) {
            $value = $request->header($header);

            // Cloudflare sends XX for anonymised traffic — a country code that
            // is not a country.
            if (is_string($value) && strlen($value) === 2 && strtoupper($value) !== 'XX') {
                return strtoupper($value);
            }
        }

        return null;
    }

    private function clip(mixed $value, int $length): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $length);
    }

    private function tableExists(): bool
    {
        // Resolved once per process: Schema::hasTable is a real query, and this
        // runs on the catalogue's hot path.
        return self::$tableExists ??= Schema::hasTable('search_events');
    }

    /** Test seam — the static cache would otherwise outlive a migration. */
    public static function forgetTableCache(): void
    {
        self::$tableExists = null;
    }
}
