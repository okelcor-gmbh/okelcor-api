<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderFinanceRecord;
use App\Services\OrderProfitabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Profitability across orders: the list finance works through, the export
 * they sign off on, and the month-by-month dashboard since January.
 *
 * All figures come from OrderProfitabilityService — the same arithmetic the
 * order page shows, so the list and the page cannot disagree about whether an
 * order made money.
 */
class AdminFinanceProfitabilityController extends Controller
{
    public function __construct(private readonly OrderProfitabilityService $service)
    {
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/finance/profitability — finance.view
    // -------------------------------------------------------------------------
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from'        => ['nullable', 'date'],
            'to'          => ['nullable', 'date'],
            'channel'     => ['nullable', Rule::in(Order::CHANNELS)],
            'verified'    => ['nullable', 'in:yes,no'],
            'has_revenue' => ['nullable', 'in:yes,no'],
            'q'           => ['nullable', 'string', 'max:100'],
            'per_page'    => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if (! OrderFinanceRecord::available()) {
            // The list must be openable between the code deploying and the
            // migration running — an empty page that says why beats a 500.
            return response()->json([
                'data'    => [],
                'meta'    => ['profitability_available' => false],
                'message' => 'Profitability tracking is not available yet — the database migration has not run.',
            ]);
        }

        $perPage   = min((int) ($filters['per_page'] ?? 25), 100);
        $paginated = $this->service->listQuery($filters)->paginate($perPage);

        return response()->json([
            'data' => collect($paginated->items())->map(fn ($order) => $this->service->listRow($order))->values(),
            'meta' => [
                'current_page'             => $paginated->currentPage(),
                'per_page'                 => $paginated->perPage(),
                'total'                    => $paginated->total(),
                'last_page'                => $paginated->lastPage(),
                'profitability_available'  => true,
                'channels'                 => Order::CHANNELS,
                'definitions'              => OrderProfitabilityService::DEFINITIONS,
            ],
            'message' => 'success',
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/finance/profitability/export — finance.view + orders.export
    //
    // One line per order reference: what it made, what it cost, and whether
    // finance signed it off. Goes to people who will open it in Excel, hence
    // the UTF-8 BOM — without one Excel reads UTF-8 as Latin-1 and turns every
    // € and accented customer name into mojibake.
    // -------------------------------------------------------------------------
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $filters = $request->validate([
            'from'        => ['nullable', 'date'],
            'to'          => ['nullable', 'date'],
            'channel'     => ['nullable', Rule::in(Order::CHANNELS)],
            'verified'    => ['nullable', 'in:yes,no'],
            'has_revenue' => ['nullable', 'in:yes,no'],
        ]);

        if (! OrderFinanceRecord::available()) {
            return response()->json([
                'message' => 'Profitability tracking is not available yet — the database migration has not run.',
            ], 503);
        }

        // Defaults to the year so far — the export finance described is the
        // one they reconcile and sign, and that document runs from January.
        $from = $filters['from'] ?? now()->startOfYear()->toDateString();
        $to   = $filters['to'] ?? now()->toDateString();

        $rows = $this->service->exportRows($this->service->listQuery(
            ['from' => $from, 'to' => $to] + $filters
        ));

        $filename = 'okelcor-profitability-' . $from . '_to_' . $to . '.csv';

        return response()->streamDownload(function () use ($rows, $from, $to) {
            $out = fopen('php://output', 'w');

            // Excel reads a UTF-8 CSV as Latin-1 unless it starts with a BOM.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ['Okelcor — Order profitability']);
            fputcsv($out, ['Period', $from . ' to ' . $to]);
            fputcsv($out, ['Generated', now()->toDateTimeString()]);
            fputcsv($out, []);

            fputcsv($out, [
                'Order ref', 'Order date', 'Channel', 'Customer', 'Status',
                'Order total', 'Revenue invoice', 'Revenue amount',
                'Supplier costs', 'Fees', 'Costs total', 'Profit', 'Margin %',
                'Currency', 'Verified', 'Verified by', 'Verified at',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, array_values($row));
            }

            fputcsv($out, []);
            fputcsv($out, ['Note', 'Profit is the recorded revenue invoice minus supplier costs and fees, '
                . 'in the revenue currency; costs in other currencies are excluded, never converted. '
                . 'A blank profit means no revenue invoice has been recorded for that order yet.']);

            fclose($out);
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/finance/profitability/dashboard — finance.view
    // -------------------------------------------------------------------------
    public function dashboard(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => ['nullable', 'integer', 'min:2020', 'max:2100'],
        ]);

        if (! OrderFinanceRecord::available()) {
            return response()->json([
                'data'    => null,
                'meta'    => ['profitability_available' => false],
                'message' => 'Profitability tracking is not available yet — the database migration has not run.',
            ]);
        }

        return response()->json([
            'data'    => $this->service->dashboard(isset($data['year']) ? (int) $data['year'] : null),
            'meta'    => ['profitability_available' => true],
            'message' => 'success',
        ]);
    }
}
