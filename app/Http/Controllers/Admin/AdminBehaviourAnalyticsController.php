<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CustomerBehaviourService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Customer behaviour — what people look for, as opposed to what they bought.
 *
 * The existing insights endpoint answers "how is the business doing". This
 * answers "what are customers asking us for and are we able to give it to
 * them", which is the question that changes the catalogue and the range.
 *
 * The payload is shaped for charting: every series is a flat array of
 * `{label, value}`-ish rows with the counts already computed, so the client
 * plots rather than aggregates.
 */
class AdminBehaviourAnalyticsController extends Controller
{
    /** Long enough to see a pattern, short enough to still be about now. */
    private const DEFAULT_DAYS = 30;
    private const MAX_DAYS     = 365;

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/analytics/behaviour?from=&to=&days= — analytics.view
    // -------------------------------------------------------------------------
    public function index(Request $request, CustomerBehaviourService $behaviour): JsonResponse
    {
        $request->validate([
            'from' => ['sometimes', 'date'],
            'to'   => ['sometimes', 'date', 'after_or_equal:from'],
            'days' => ['sometimes', 'integer', 'min:1', 'max:' . self::MAX_DAYS],
        ]);

        $to = $request->filled('to')
            ? Carbon::parse($request->input('to'))->endOfDay()
            : Carbon::now();

        if ($request->filled('from')) {
            $from = Carbon::parse($request->input('from'))->startOfDay();
        } else {
            $days = (int) $request->input('days', self::DEFAULT_DAYS);
            $from = $to->copy()->subDays($days)->startOfDay();
        }

        // A range longer than the cap is a slow query on a table that grows
        // with every search, and nobody is reading a two-year daily series.
        if ($from->diffInDays($to) > self::MAX_DAYS) {
            $from = $to->copy()->subDays(self::MAX_DAYS)->startOfDay();
        }

        return response()->json([
            'data' => $behaviour->report($from, $to),
            'meta' => [
                'generated_at' => Carbon::now()->toIso8601String(),
                // Said plainly in the payload rather than only in a document,
                // because a dashboard that silently omits a whole class of
                // behaviour invites someone to conclude it isn't happening.
                'covers'     => 'Catalogue searches and filters made against this API, plus inquiries, orders and saved fitments.',
                'not_covered' => 'Page views, scroll depth, click paths and time on page never reach this API and are not represented here.',
            ],
        ]);
    }
}
