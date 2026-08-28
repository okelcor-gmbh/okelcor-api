<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FinanceLiquidityWeekEntry;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The liquidity board as a rolling four-week window.
 *
 * "We are currently in week 35 — show 35 to 38, and when a week ends it drops
 * off." The window is computed from today on every request, which is the
 * whole mechanism: nothing closes a week, it simply stops being asked for.
 * Rows stay in the table as history.
 *
 * Weeks are ISO weeks (Monday-start), labeled with the ISO week number
 * finance already uses.
 */
class AdminFinanceLiquidityWeekController extends Controller
{
    // -------------------------------------------------------------------------
    // GET /api/v1/admin/finance-liquidity/weeks — finance.view
    // -------------------------------------------------------------------------
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'weeks' => ['nullable', 'integer', 'min:1', 'max:8'],
            // A past date deliberately allowed — it is how finance looks back
            // at a closed week's history when they need to.
            'from'  => ['nullable', 'date'],
        ]);

        $count = (int) $request->input('weeks', 4);
        $start = CarbonImmutable::parse($request->input('from', 'today'))->startOfWeek();
        $end   = $start->addWeeks($count - 1);

        $entries = FinanceLiquidityWeekEntry::query()
            ->with('recordedBy:id,name')
            ->whereDate('week_start', '>=', $start->toDateString())
            ->whereDate('week_start', '<=', $end->toDateString())
            ->orderBy('week_start')->orderBy('line')->orderBy('id')
            ->get()
            ->groupBy(fn (FinanceLiquidityWeekEntry $e) => $e->week_start->toDateString());

        $weeks = [];

        for ($i = 0; $i < $count; $i++) {
            $monday = $start->addWeeks($i);
            $weeks[] = $this->formatWeek($monday, $entries->get($monday->toDateString(), collect()));
        }

        return response()->json([
            'data' => $weeks,
            'meta' => [
                'current_week' => (int) CarbonImmutable::now()->isoWeek,
                'lines'        => FinanceLiquidityWeekEntry::LINES,
                'weeks_shown'  => $count,
            ],
            'message' => 'success',
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/admin/finance-liquidity/weeks/entries — finance.manage
    // -------------------------------------------------------------------------
    public function storeEntry(Request $request): JsonResponse
    {
        $data = $request->validate([
            'week_start'  => ['required', 'date'],
            'line'        => ['required', Rule::in(array_keys(FinanceLiquidityWeekEntry::LINES))],
            'description' => ['nullable', 'string', 'max:255'],
            'reference'   => ['nullable', 'string', 'max:100'],
            'amount'      => ['required', 'numeric', 'min:-99999999', 'max:99999999'],
        ]);

        $monday = CarbonImmutable::parse($data['week_start'])->startOfWeek();

        if ($refusal = $this->refuseClosedWeek($monday)) {
            return $refusal;
        }

        $entry = FinanceLiquidityWeekEntry::create([
            'week_start'  => $monday->toDateString(),
            'line'        => $data['line'],
            'description' => $data['description'] ?? null,
            'reference'   => $data['reference'] ?? null,
            'amount'      => $data['amount'],
            'recorded_by' => $request->user()->id,
        ]);

        return response()->json([
            'data'    => $this->formatEntry($entry->fresh('recordedBy')),
            'message' => FinanceLiquidityWeekEntry::LINES[$data['line']]
                . ' entry added to week ' . $monday->isoWeek . '.',
        ], 201);
    }

    // -------------------------------------------------------------------------
    // PATCH /api/v1/admin/finance-liquidity/weeks/entries/{id} — finance.manage
    // -------------------------------------------------------------------------
    public function updateEntry(Request $request, int $id): JsonResponse
    {
        $entry = FinanceLiquidityWeekEntry::findOrFail($id);

        if ($refusal = $this->refuseClosedWeek(CarbonImmutable::parse($entry->week_start->toDateString())->startOfWeek())) {
            return $refusal;
        }

        $data = $request->validate([
            'line'        => ['sometimes', Rule::in(array_keys(FinanceLiquidityWeekEntry::LINES))],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'reference'   => ['sometimes', 'nullable', 'string', 'max:100'],
            'amount'      => ['sometimes', 'numeric', 'min:-99999999', 'max:99999999'],
            'week_start'  => ['sometimes', 'date'],
        ]);

        if (isset($data['week_start'])) {
            $monday = CarbonImmutable::parse($data['week_start'])->startOfWeek();

            if ($refusal = $this->refuseClosedWeek($monday)) {
                return $refusal;
            }

            $data['week_start'] = $monday->toDateString();
        }

        $entry->update($data);

        return response()->json([
            'data'    => $this->formatEntry($entry->fresh('recordedBy')),
            'message' => 'Liquidity entry updated.',
        ]);
    }

    // -------------------------------------------------------------------------
    // DELETE /api/v1/admin/finance-liquidity/weeks/entries/{id} — finance.manage
    // -------------------------------------------------------------------------
    public function destroyEntry(int $id): JsonResponse
    {
        $entry = FinanceLiquidityWeekEntry::findOrFail($id);

        if ($refusal = $this->refuseClosedWeek(CarbonImmutable::parse($entry->week_start->toDateString())->startOfWeek())) {
            return $refusal;
        }

        $entry->delete();

        return response()->json(['message' => 'Liquidity entry removed.']);
    }

    // -------------------------------------------------------------------------
    // PUT /api/v1/admin/finance-liquidity/weeks/bank-balance — finance.manage
    //
    // "Bank balance should be updated for each week" — one call that sets the
    // week's balance whether or not a row exists yet, so updating it is one
    // action, not check-then-create.
    // -------------------------------------------------------------------------
    public function setBankBalance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'week_start' => ['required', 'date'],
            'amount'     => ['required', 'numeric', 'min:-99999999', 'max:99999999'],
            'reference'  => ['nullable', 'string', 'max:100'],
        ]);

        $monday = CarbonImmutable::parse($data['week_start'])->startOfWeek();

        if ($refusal = $this->refuseClosedWeek($monday)) {
            return $refusal;
        }

        $entry = FinanceLiquidityWeekEntry::query()
            ->whereDate('week_start', $monday->toDateString())
            ->where('line', 'bank_balance')
            ->orderBy('id')
            ->first();

        if ($entry === null) {
            $entry = FinanceLiquidityWeekEntry::create([
                'week_start'  => $monday->toDateString(),
                'line'        => 'bank_balance',
                'description' => 'Bank balance',
                'reference'   => $data['reference'] ?? null,
                'amount'      => $data['amount'],
                'recorded_by' => $request->user()->id,
            ]);
        } else {
            $entry->update(array_filter([
                'amount'      => $data['amount'],
                'reference'   => $data['reference'] ?? null,
                'recorded_by' => $request->user()->id,
            ], fn ($v) => $v !== null));
        }

        return response()->json([
            'data'    => $this->formatEntry($entry->fresh('recordedBy')),
            'message' => 'Bank balance set for week ' . $monday->isoWeek . '.',
        ]);
    }

    // -------------------------------------------------------------------------

    /**
     * Writes land only in the window — the current week or later. A week that
     * has ended is closed; the note's word for what happens to it is
     * "removed", and a closed period that can still be edited is not closed.
     */
    private function refuseClosedWeek(CarbonImmutable $monday): ?JsonResponse
    {
        if ($monday->addDays(7)->greaterThan(CarbonImmutable::now()->startOfWeek())) {
            return null;
        }

        return response()->json([
            'message' => 'Week ' . $monday->isoWeek . ' (' . $monday->toDateString()
                . ') has already closed. Closed weeks are history and are no longer edited.',
            'code'    => 'week_closed',
        ], 409);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, FinanceLiquidityWeekEntry>  $entries
     * @return array<string, mixed>
     */
    private function formatWeek(CarbonImmutable $monday, $entries): array
    {
        $lineTotals = [];

        foreach (array_keys(FinanceLiquidityWeekEntry::LINES) as $line) {
            $ofLine = $entries->filter(fn ($e) => $e->line === $line);

            $lineTotals[$line] = [
                'line'    => $line,
                'label'   => FinanceLiquidityWeekEntry::LINES[$line],
                'total'   => round((float) $ofLine->sum('amount'), 2),
                'entries' => $ofLine->map(fn ($e) => $this->formatEntry($e))->values(),
            ];
        }

        $bank = $lineTotals['bank_balance']['total'];
        $in   = $lineTotals['revenue_payment']['total'];
        $out  = round(array_sum(array_map(
            fn (string $line) => $lineTotals[$line]['total'],
            FinanceLiquidityWeekEntry::OUTFLOW_LINES
        )), 2);

        $now = CarbonImmutable::now();

        return [
            'week'       => (int) $monday->isoWeek,
            'year'       => (int) $monday->isoWeekYear,
            'label'      => 'Week ' . $monday->isoWeek,
            'starts_on'  => $monday->toDateString(),
            'ends_on'    => $monday->addDays(6)->toDateString(),
            'is_current' => $now->between($monday->startOfDay(), $monday->addDays(6)->endOfDay()),
            'lines'      => array_values($lineTotals),
            // Cost lines are entered as positive magnitudes, so the projection
            // is balance + expected in − expected out.
            'bank_balance'      => $bank,
            'expected_in'       => $in,
            'expected_out'      => $out,
            'projected_closing' => round($bank + $in - $out, 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatEntry(FinanceLiquidityWeekEntry $e): array
    {
        return [
            'id'          => $e->id,
            'week_start'  => $e->week_start->toDateString(),
            'week'        => (int) CarbonImmutable::parse($e->week_start->toDateString())->isoWeek,
            'line'        => $e->line,
            'line_label'  => FinanceLiquidityWeekEntry::LINES[$e->line] ?? $e->line,
            'description' => $e->description,
            'reference'   => $e->reference,
            'amount'      => (float) $e->amount,
            'recorded_by' => $e->recordedBy?->name,
            'updated_at'  => $e->updated_at?->toIso8601String(),
        ];
    }
}
