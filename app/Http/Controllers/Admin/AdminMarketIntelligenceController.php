<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\MarketIntelligenceService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Market intelligence — which market to go after next.
 *
 * Sibling of the behaviour report, aimed at a different reader. Behaviour
 * answers "what should we fix"; this answers "where should we sell". Both sit
 * behind `analytics.view`, which already includes the `marketing` role.
 */
class AdminMarketIntelligenceController extends Controller
{
    private const MAX_RANGE_DAYS = 400;

    // GET /admin/analytics/markets?from=&to=
    public function index(Request $request, MarketIntelligenceService $markets): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return response()->json([
            'data'    => $markets->report($from, $to),
            'message' => 'success',
        ]);
    }

    /**
     * GET /admin/analytics/markets/export
     *
     * The "market database" the marketing team asked for: one row per market,
     * openable in Excel, with the signal and the recommended action carried
     * through so the spreadsheet still says why a market ranks where it does.
     * A CSV of bare numbers would lose exactly the part that makes it useful.
     */
    public function export(Request $request, MarketIntelligenceService $markets): StreamedResponse
    {
        [$from, $to] = $this->range($request);
        $report = $markets->report($from, $to);

        $filename = "okelcor-markets-{$from->toDateString()}-to-{$to->toDateString()}.csv";

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');

            // Excel opens UTF-8 CSV as Latin-1 without this, which mangles
            // every accented country name in the file.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'Country', 'ISO', 'Signal', 'Recommended action',
                'Searches', 'Unique visitors', 'Unmet searches', 'Unmet rate %',
                'Top unmet terms',
                'Quotes', 'Quotes converted', 'Quote→order %',
                'Orders', 'Revenue', 'Customers',
                'Marketing contacts', 'Campaign markets',
            ]);

            foreach ($report['markets'] as $m) {
                fputcsv($out, [
                    $m['country'],
                    $m['country_code'],
                    $m['signal_label'],
                    $m['recommended_action'],
                    $m['demand']['searches'] ?? '',
                    $m['demand']['visitors'] ?? '',
                    $m['demand']['unmet_searches'] ?? '',
                    isset($m['demand']['unmet_rate']) && $m['demand']['unmet_rate'] !== null
                        ? round($m['demand']['unmet_rate'] * 100, 1) : '',
                    collect($m['demand']['top_unmet_terms'] ?? [])
                        ->map(fn ($t) => "{$t['term']} ({$t['searches']})")->implode('; '),
                    $m['pipeline']['quotes'],
                    $m['pipeline']['quotes_converted'],
                    $m['rates']['quote_to_order'] !== null
                        ? round($m['rates']['quote_to_order'] * 100, 1) : '',
                    $m['commercial']['orders'],
                    collect($m['commercial']['revenue_by_currency'])
                        ->map(fn ($v, $c) => number_format($v, 2) . ' ' . $c)->implode('; '),
                    $m['commercial']['customers'],
                    $m['reach']['contacts'],
                    implode('; ', $m['reach']['market_slugs'] ?? []),
                ]);
            }

            // Everything the table above could not say, carried into the file
            // rather than left behind in the UI — a spreadsheet gets forwarded,
            // and its caveats have to travel with it.
            if ($report['unmeasured'] !== []) {
                fputcsv($out, []);
                fputcsv($out, ['NOT MEASURED — outside data exists, no visitors from these markets yet']);
                fputcsv($out, ['Country', 'ISO', 'Reference data']);
                foreach ($report['unmeasured'] as $u) {
                    fputcsv($out, [
                        $u['country'],
                        $u['country_code'],
                        collect($u['reference'])
                            ->map(fn ($r) => "{$r['metric']}: {$r['value']} {$r['unit']} ({$r['period']})")
                            ->implode('; '),
                    ]);
                }
            }

            if ($report['unrecognised'] !== []) {
                fputcsv($out, []);
                fputcsv($out, ['UNRECOGNISED COUNTRY VALUES — these rows are missing from the table above']);
                fputcsv($out, ['Source', 'Value as stored', 'Rows']);
                foreach ($report['unrecognised'] as $u) {
                    fputcsv($out, [$u['source'], $u['value'], $u['rows']]);
                }
            }

            fputcsv($out, []);
            foreach ($report['meta']['not_covered'] as $caveat) {
                fputcsv($out, ['NOTE', $caveat]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Default window is 90 days: long enough for a quote to become an order,
     * which a 30-day window would cut in half and make every market look
     * worse at converting than it is.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(Request $request): array
    {
        $to = $request->filled('to')
            ? Carbon::parse($request->query('to'))->endOfDay()
            : Carbon::now()->endOfDay();

        $from = $request->filled('from')
            ? Carbon::parse($request->query('from'))->startOfDay()
            : (clone $to)->subDays(90)->startOfDay();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            $from = (clone $to)->subDays(self::MAX_RANGE_DAYS)->startOfDay();
        }

        return [$from, $to];
    }
}
