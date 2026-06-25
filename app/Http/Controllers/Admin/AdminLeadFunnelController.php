<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\QuoteRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Lead → quote → order funnel analytics.
 *
 * Built on the always-present `qualification_status` pipeline (new → qualified →
 * proposal_sent → converted), so it works before *and* after the pending CRM-7
 * migrations land. Proposal-stage and attribution (lead_metadata UTM) data are
 * enrichments that are included only when those columns exist.
 *
 * Aggregation is done in PHP over a single, column-scoped fetch — DB-agnostic
 * (MySQL prod / SQLite tests) and fine for B2B lead volumes. Revisit with SQL
 * GROUP BY if lead counts ever reach the tens of thousands.
 */
class AdminLeadFunnelController extends Controller
{
    /** qualification_status values that represent a lead that reached "qualified" or further. */
    private const QUALIFIED_PLUS = ['qualified', 'proposal_sent', 'customer_invited', 'converted'];

    /** ...that reached "proposal sent" or further. */
    private const PROPOSAL_PLUS = ['proposal_sent', 'customer_invited', 'converted'];

    /** Excluded from the "real lead" funnel (junk). */
    private const JUNK = ['spam'];

    // GET /api/v1/admin/quote-requests/funnel?from=YYYY-MM-DD&to=YYYY-MM-DD
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to'   => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $to   = $request->date('to') ?: now();
        $from = $request->date('from') ?: (clone $to)->subDays(90);
        $from = $from->startOfDay();
        $to   = $to->endOfDay();

        $hasMetadata = Schema::hasColumn('quote_requests', 'lead_metadata');
        $hasOrderId  = Schema::hasColumn('quote_requests', 'order_id');

        $columns = ['lead_source', 'lead_customer_type', 'qualification_status', 'created_at'];
        if ($hasMetadata) {
            $columns[] = 'lead_metadata';
        }
        if ($hasOrderId) {
            $columns[] = 'order_id';
        }

        $rows = QuoteRequest::query()
            ->whereBetween('created_at', [$from, $to])
            ->get($columns);

        // ── Overall funnel ────────────────────────────────────────────────────
        $real      = $rows->reject(fn ($r) => in_array($r->qualification_status, self::JUNK, true));
        $total     = $real->count();
        $qualified = $real->whereIn('qualification_status', self::QUALIFIED_PLUS)->count();
        $proposals = $real->whereIn('qualification_status', self::PROPOSAL_PLUS)->count();
        $converted = $real->where('qualification_status', 'converted')->count();
        $spam      = $rows->whereIn('qualification_status', self::JUNK)->count();

        $funnel = [
            ['stage' => 'leads',         'count' => $total,     'rate_from_previous' => null,                              'rate_from_top' => 100.0],
            ['stage' => 'qualified',     'count' => $qualified, 'rate_from_previous' => $this->rate($qualified, $total),   'rate_from_top' => $this->rate($qualified, $total)],
            ['stage' => 'proposal_sent', 'count' => $proposals, 'rate_from_previous' => $this->rate($proposals, $qualified), 'rate_from_top' => $this->rate($proposals, $total)],
            ['stage' => 'converted',     'count' => $converted, 'rate_from_previous' => $this->rate($converted, $proposals), 'rate_from_top' => $this->rate($converted, $total)],
        ];

        // ── By lead source ────────────────────────────────────────────────────
        $bySource = $real->groupBy(fn ($r) => $r->lead_source ?: 'unknown')
            ->map(function ($group, $source) {
                $leads = $group->count();
                $conv  = $group->where('qualification_status', 'converted')->count();

                return [
                    'source'          => $source,
                    'leads'           => $leads,
                    'qualified'       => $group->whereIn('qualification_status', self::QUALIFIED_PLUS)->count(),
                    'converted'       => $conv,
                    'conversion_rate' => $this->rate($conv, $leads),
                ];
            })
            ->sortByDesc('leads')
            ->values();

        // ── By customer type ──────────────────────────────────────────────────
        $byCustomerType = $real->groupBy(fn ($r) => $r->lead_customer_type ?: 'unknown')
            ->map(function ($group, $type) {
                $leads = $group->count();
                $conv  = $group->where('qualification_status', 'converted')->count();

                return [
                    'customer_type'   => $type,
                    'leads'           => $leads,
                    'converted'       => $conv,
                    'conversion_rate' => $this->rate($conv, $leads),
                ];
            })
            ->sortByDesc('leads')
            ->values();

        // ── Monthly trend ─────────────────────────────────────────────────────
        $byMonth = $real->groupBy(fn ($r) => $r->created_at->format('Y-m'))
            ->map(function ($group, $month) {
                $conv = $group->where('qualification_status', 'converted')->count();

                return [
                    'month'           => $month,
                    'leads'           => $group->count(),
                    'converted'       => $conv,
                    'conversion_rate' => $this->rate($conv, $group->count()),
                ];
            })
            ->sortKeys()
            ->values();

        $payload = [
            'range'            => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals'          => [
                'total_leads'              => $total,
                'spam'                     => $spam,
                'qualified'                => $qualified,
                'proposals_sent'           => $proposals,
                'converted'                => $converted,
                'qualification_rate'       => $this->rate($qualified, $total),
                'proposal_acceptance_rate' => $this->rate($converted, $proposals),
                'overall_conversion_rate'  => $this->rate($converted, $total),
            ],
            'funnel'          => $funnel,
            'by_source'       => $bySource,
            'by_customer_type' => $byCustomerType,
            'by_month'        => $byMonth,
        ];

        // ── Attribution (only when lead_metadata exists) ──────────────────────
        if ($hasMetadata) {
            $payload['by_attribution'] = [
                'utm_source'   => $this->topAttribution($real, 'utm_source'),
                'utm_campaign' => $this->topAttribution($real, 'utm_campaign'),
                'utm_medium'   => $this->topAttribution($real, 'utm_medium'),
            ];
        }

        return response()->json(['data' => $payload, 'message' => 'success']);
    }

    /** Percentage of $part within $whole, one decimal, divide-by-zero safe. */
    private function rate(int $part, int $whole): float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : 0.0;
    }

    /**
     * Top values of a UTM key from lead_metadata, with conversions, sorted by lead count.
     */
    private function topAttribution($rows, string $key): array
    {
        return $rows
            ->groupBy(function ($r) use ($key) {
                $meta = is_array($r->lead_metadata) ? $r->lead_metadata : [];

                return $meta[$key] ?? ($meta['utm'][$key] ?? 'none');
            })
            ->map(function ($group, $value) {
                return [
                    'value'     => (string) $value,
                    'leads'     => $group->count(),
                    'converted' => $group->where('qualification_status', 'converted')->count(),
                ];
            })
            ->sortByDesc('leads')
            ->values()
            ->take(10)
            ->all();
    }
}
