<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LiquidityWeek;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The liquidity ladder: the current ISO week and the three ahead of it, each
 * carrying the bank balance and expected movements finance maintains.
 *
 * The window ROLLS BY READING, not by writing: it always starts at today's
 * ISO week, so the moment a week ends it falls out of the view and the next
 * one enters — no job, no cleanup, nothing to forget to run. A finished
 * week's row survives untouched and is readable under /history.
 */
class AdminLiquidityController extends Controller
{
    /** Current week plus three — the four weeks finance asked to see. */
    private const WINDOW = 4;

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/finance/liquidity — finance.view
    // -------------------------------------------------------------------------
    public function index(Request $request): JsonResponse
    {
        if (! LiquidityWeek::available()) {
            return response()->json([
                'data'    => ['weeks' => []],
                'meta'    => ['liquidity_available' => false],
                'message' => 'Liquidity planning is not available yet — the database migration has not run.',
            ]);
        }

        $start = CarbonImmutable::today()->startOfWeek();

        $keys = [];
        for ($i = 0; $i < self::WINDOW; $i++) {
            $keys[] = LiquidityWeek::keyFor($start->addWeeks($i));
        }

        $rows = LiquidityWeek::with('updatedBy:id,name')
            ->whereIn('week_key', $keys)
            ->get()
            ->keyBy('week_key');

        $weeks   = [];
        $running = null;

        foreach ($keys as $i => $key) {
            $monday = $start->addWeeks($i);
            $row    = $rows->get($key);

            $balance = $row?->bank_balance !== null ? (float) $row->bank_balance : null;
            $in      = $row?->expected_in !== null ? (float) $row->expected_in : null;
            $out     = $row?->expected_out !== null ? (float) $row->expected_out : null;

            // Each week opens on its own entered balance where finance has
            // typed one, otherwise on the previous week's projected close —
            // that chain is what makes four rows a ladder instead of four
            // unrelated numbers.
            $opening = $balance ?? $running;

            $closing = ($opening === null && $in === null && $out === null)
                ? null
                : round(($opening ?? 0) + ($in ?? 0) - ($out ?? 0), 2);

            $running = $closing;

            $weeks[] = [
                'week_key'          => $key,
                'label'             => 'Week ' . $monday->format('W, o'),
                'iso_year'          => (int) $monday->format('o'),
                'iso_week'          => (int) $monday->format('W'),
                'starts_on'         => $monday->toDateString(),
                'ends_on'           => $monday->endOfWeek()->toDateString(),
                'is_current'        => $i === 0,
                'recorded'          => $row !== null,
                'bank_balance'      => $balance,
                'expected_in'       => $in,
                'expected_out'      => $out,
                'projected_closing' => $closing,
                'notes'             => $row?->notes,
                'updated_by'        => $row?->updatedBy?->name,
                'updated_at'        => $row?->updated_at?->toIso8601String(),
            ];
        }

        return response()->json([
            'data' => ['weeks' => $weeks],
            'meta' => [
                'liquidity_available' => true,
                'current_week'        => $keys[0],
                'window'              => self::WINDOW,
                'note'                => 'The window always starts at the current ISO week — a week that has '
                    . 'ended drops out by the calendar moving, and its data is kept under /history. '
                    . 'projected_closing chains: each week opens on its entered bank balance, or on the '
                    . 'previous week\'s projected close where none is entered.',
            ],
            'message' => 'success',
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/finance/liquidity/history — finance.view
    //
    // The weeks that have rolled out of the window, newest first. Only weeks
    // someone actually recorded — an untouched past week has nothing to show.
    // -------------------------------------------------------------------------
    public function history(Request $request): JsonResponse
    {
        $data = $request->validate([
            'weeks' => ['nullable', 'integer', 'min:1', 'max:104'],
        ]);

        if (! LiquidityWeek::available()) {
            return response()->json([
                'data'    => ['weeks' => []],
                'meta'    => ['liquidity_available' => false],
                'message' => 'Liquidity planning is not available yet — the database migration has not run.',
            ]);
        }

        $currentKey = LiquidityWeek::keyFor(CarbonImmutable::today());

        // week_key is zero-padded ('2026-W09'), so lexicographic order IS
        // chronological order — across year boundaries too.
        $rows = LiquidityWeek::with('updatedBy:id,name')
            ->where('week_key', '<', $currentKey)
            ->orderByDesc('week_key')
            ->limit((int) ($data['weeks'] ?? 12))
            ->get();

        return response()->json([
            'data' => [
                'weeks' => $rows->map(fn (LiquidityWeek $row) => [
                    'week_key'     => $row->week_key,
                    'label'        => 'Week ' . str_pad((string) $row->iso_week, 2, '0', STR_PAD_LEFT) . ', ' . $row->iso_year,
                    'starts_on'    => $row->starts_on?->toDateString(),
                    'ends_on'      => $row->ends_on?->toDateString(),
                    'bank_balance' => $row->bank_balance !== null ? (float) $row->bank_balance : null,
                    'expected_in'  => $row->expected_in !== null ? (float) $row->expected_in : null,
                    'expected_out' => $row->expected_out !== null ? (float) $row->expected_out : null,
                    'notes'        => $row->notes,
                    'updated_by'   => $row->updatedBy?->name,
                    'updated_at'   => $row->updated_at?->toIso8601String(),
                ])->values(),
            ],
            'meta' => [
                'liquidity_available' => true,
                'current_week'        => $currentKey,
            ],
            'message' => 'success',
        ]);
    }

    // -------------------------------------------------------------------------
    // PUT /api/v1/admin/finance/liquidity/{weekKey} — finance.manage
    //
    // Upserts one week. A past week can still be corrected — finance
    // reconciles after the fact — but the response says plainly that the week
    // is closed, so the UI can warn rather than the API refusing history its
    // corrections.
    // -------------------------------------------------------------------------
    public function upsert(Request $request, string $weekKey): JsonResponse
    {
        if (! LiquidityWeek::available()) {
            return response()->json([
                'message' => 'Liquidity planning is not available yet — the database migration has not run.',
            ], 503);
        }

        $parsed = LiquidityWeek::parseKey($weekKey);

        if ($parsed === null) {
            return response()->json([
                'message' => "'{$weekKey}' is not a week. Use the ISO format the planner shows, e.g. 2026-W35.",
                'errors'  => ['week_key' => ['Expected YYYY-Wnn naming a real ISO week.']],
            ], 422);
        }

        // A year either side of today is enough for planning and for
        // correcting history; anything further out is far more likely a typo
        // than a plan.
        $today = CarbonImmutable::today();

        if ($parsed['start']->lt($today->subYear()) || $parsed['start']->gt($today->addYear())) {
            return response()->json([
                'message' => "{$weekKey} is more than a year away — check the week number.",
                'errors'  => ['week_key' => ['Weeks are accepted up to a year either side of today.']],
            ], 422);
        }

        $data = $request->validate([
            // A bank balance can be negative; expected movements cannot.
            'bank_balance' => ['nullable', 'numeric', 'min:-999999999999', 'max:999999999999'],
            'expected_in'  => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'expected_out' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'notes'        => ['nullable', 'string', 'max:1000'],
        ]);

        $row = LiquidityWeek::updateOrCreate(
            ['week_key' => $parsed['key']],
            $data + [
                'iso_year'   => $parsed['year'],
                'iso_week'   => $parsed['week'],
                'starts_on'  => $parsed['start']->toDateString(),
                'ends_on'    => $parsed['end']->toDateString(),
                'updated_by' => $request->user()?->id,
            ],
        );

        $isClosed = $parsed['end']->lt($today->startOfWeek());

        return response()->json([
            'data' => [
                'week_key'     => $row->week_key,
                'starts_on'    => $row->starts_on?->toDateString(),
                'ends_on'      => $row->ends_on?->toDateString(),
                'bank_balance' => $row->bank_balance !== null ? (float) $row->bank_balance : null,
                'expected_in'  => $row->expected_in !== null ? (float) $row->expected_in : null,
                'expected_out' => $row->expected_out !== null ? (float) $row->expected_out : null,
                'notes'        => $row->notes,
                'is_closed'    => $isClosed,
            ],
            'message' => $isClosed
                ? 'Saved — note this week has already ended, so it shows under history, not the planner.'
                : 'Week saved.',
        ]);
    }
}
