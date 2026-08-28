<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FinanceInvoice;
use App\Models\Order;
use App\Models\OrderCost;
use App\Models\OrderSignoff;
use App\Services\OrderProfitabilityService;
use App\Services\OrderSignoffService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Profitability per order, and the roll-ups finance asked for.
 *
 * One reference per order; against it the finalized (customer-agreed) revenue
 * invoice, the supplier invoices, and the fee lines — and the profit those
 * imply, visible the moment the pieces are in. The export is the same list as
 * CSV with the sign-off columns, and the summary is the January-onwards
 * monthly dashboard.
 */
class AdminOrderProfitabilityController extends Controller
{
    public function __construct(
        private readonly OrderProfitabilityService $profitability,
        private readonly OrderSignoffService $signoffs,
    ) {
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/finance/profitability — finance.view
    // -------------------------------------------------------------------------
    public function index(Request $request): JsonResponse
    {
        $request->validate($this->listFilterRules());

        $query = $this->filteredOrders($request);

        $perPage   = min((int) $request->input('per_page', 25), 100);
        $paginated = $query->paginate($perPage);

        $orders = collect($paginated->items());
        $rows   = $this->rows($orders);

        return response()->json([
            'data' => $rows,
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
                'cost_types'   => OrderCost::TYPES,
                'available'    => $this->profitability->available(),
            ],
            'message' => 'success',
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/finance/profitability/summary — finance.view
    //
    // The dashboard: January onwards, month by month, for the requested year.
    // -------------------------------------------------------------------------
    public function summary(Request $request): JsonResponse
    {
        $request->validate([
            'year' => ['nullable', 'integer', 'min:2020', 'max:2100'],
        ]);

        $year = (int) $request->input('year', now()->year);

        $orders = $this->baseOrders()
            ->whereYear('created_at', $year)
            ->get(['id', 'ref', 'created_at', 'total', 'currency']);

        $totalsByRef = $this->profitability->totalsForRefs($orders->pluck('ref')->all());

        // A past year shows all twelve months; the current year stops at the
        // month we are in — empty future months on a dashboard read as zeros,
        // not as "not yet".
        $lastMonth = $year === (int) now()->year ? (int) now()->month : 12;

        $months = [];

        for ($m = 1; $m <= $lastMonth; $m++) {
            $inMonth = $orders->filter(fn (Order $o) => (int) $o->created_at?->month === $m);

            $revenue = $supplier = $fees = $orderTotal = 0.0;
            $missingRevenue = 0;

            foreach ($inMonth as $order) {
                $t = $totalsByRef[$order->ref];

                $orderTotal += (float) $order->total;
                $supplier   += $t['supplier_costs'];
                $fees       += $t['fees'];

                $t['revenue'] === null ? $missingRevenue++ : $revenue += $t['revenue'];
            }

            $profit = round($revenue - $supplier - $fees, 2);

            $months[] = [
                'month'          => $m,
                'label'          => date('F', mktime(0, 0, 0, $m, 1)),
                'orders'         => $inMonth->count(),
                'order_total'    => round($orderTotal, 2),
                'revenue'        => round($revenue, 2),
                'supplier_costs' => round($supplier, 2),
                'fees'           => round($fees, 2),
                'profit'         => $profit,
                'margin_percent' => $revenue < 0.005 ? null : round($profit / $revenue * 100, 1),
                // Orders whose costs are counted above but whose agreed
                // revenue is still missing — the work list behind a low month.
                'orders_missing_revenue_invoice' => $missingRevenue,
            ];
        }

        $sum = fn (string $key) => round(array_sum(array_column($months, $key)), 2);

        $totalRevenue = $sum('revenue');
        $totalProfit  = $sum('profit');

        return response()->json([
            'data' => [
                'year'   => $year,
                'months' => $months,
                'totals' => [
                    'orders'         => array_sum(array_column($months, 'orders')),
                    'order_total'    => $sum('order_total'),
                    'revenue'        => $totalRevenue,
                    'supplier_costs' => $sum('supplier_costs'),
                    'fees'           => $sum('fees'),
                    'profit'         => $totalProfit,
                    'margin_percent' => $totalRevenue < 0.005 ? null : round($totalProfit / $totalRevenue * 100, 1),
                    'orders_missing_revenue_invoice' => array_sum(array_column($months, 'orders_missing_revenue_invoice')),
                ],
            ],
            'meta'    => ['available' => $this->profitability->available()],
            'message' => 'success',
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/finance/profitability/export — finance.view
    //
    // The same list, as the CSV finance files: one reference per order, the
    // money on both sides, and who verified it.
    // -------------------------------------------------------------------------
    public function export(Request $request): StreamedResponse
    {
        $request->validate($this->listFilterRules());

        // Bounded rather than unbounded: five thousand order rows is beyond
        // any real filing run, and an accidental no-filter export must not
        // stream the whole table forever.
        $orders = $this->filteredOrders($request)->limit(5000)->get();
        $rows   = $this->rows($orders);

        $filename = 'okelcor-order-profitability-' . now()->toDateString() . '.csv';

        return response()->streamDownload(function () use ($rows, $request) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM — finance opens these in Excel

            fputcsv($out, ['Okelcor order profitability']);
            fputcsv($out, ['Exported', now()->format('Y-m-d H:i')]);

            if ($request->filled('from') || $request->filled('to')) {
                fputcsv($out, ['Period', ($request->input('from') ?: '…') . ' to ' . ($request->input('to') ?: '…')]);
            }

            fputcsv($out, []);
            fputcsv($out, [
                'Order ref', 'Date', 'Customer', 'Channel', 'Order status', 'Payment status',
                'Currency', 'Order total', 'Revenue invoice', 'Revenue (agreed)',
                'Supplier costs', 'Fees & charges', 'Total costs', 'Profit', 'Margin %',
                'Ops signed by', 'Ops signed on', 'Finance signed by', 'Finance signed on', 'Verified',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['ref'],
                    $row['date'],
                    $row['customer_name'],
                    $row['channel'],
                    $row['status'],
                    $row['payment_status'],
                    $row['currency'],
                    $row['order_total'],
                    $row['revenue_invoice_number'] ?? '',
                    $row['revenue'] ?? '',
                    $row['supplier_costs'],
                    $row['fees'],
                    $row['total_costs'],
                    $row['profit'] ?? '',
                    $row['margin_percent'] ?? '',
                    $row['signoff']['ops_signed_by'] ?? '',
                    $row['signoff']['ops_signed_at'] ? substr($row['signoff']['ops_signed_at'], 0, 10) : '',
                    $row['signoff']['finance_signed_by'] ?? '',
                    $row['signoff']['finance_signed_at'] ? substr($row['signoff']['finance_signed_at'], 0, 10) : '',
                    $row['verified'] ? 'Yes' : 'No',
                ]);
            }

            fputcsv($out, []);
            fputcsv($out, ['Note', 'Revenue is the finalized, customer-agreed revenue invoice — blank means '
                . 'no invoice has been finalized for that order yet. Verified means both the operations and '
                . 'finance signatures stand.']);

            fclose($out);
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/finance/profitability/{ref} — finance.view
    //
    // Everything hanging off one order reference.
    // -------------------------------------------------------------------------
    public function show(Request $request, string $ref): JsonResponse
    {
        $order = Order::where('ref', $ref)->firstOrFail();

        $invoices = $this->profitability->available()
            ? $order->financeInvoices()->with(['recordedBy:id,name', 'finalizedBy:id,name'])->get()
            : collect();

        $costs = $this->profitability->available()
            ? $order->costs()->with('recordedBy:id,name')->get()
            : collect();

        $revenueInvoice = $invoices->first(fn (FinanceInvoice $f) => $f->role === FinanceInvoice::ROLE_REVENUE
            && $f->isFinalized());

        // A drafted revenue invoice (uploaded, not yet agreed) is worth
        // showing distinctly — it is the thing awaiting finalization.
        $draftRevenue = $invoices->filter(fn (FinanceInvoice $f) => $f->role === FinanceInvoice::ROLE_REVENUE
            && ! $f->isFinalized());

        $data = [
            'order' => [
                'ref'            => $order->ref,
                'date'           => $order->created_at?->toDateString(),
                'customer_name'  => $order->customer_name,
                'customer_email' => $order->customer_email,
                'channel'        => $order->channel(),
                'status'         => $order->status,
                'payment_status' => $order->payment_status,
                'payment_stage'  => $order->payment_stage,
                'currency'       => $order->currency ?: 'EUR',
                'subtotal'       => (float) $order->subtotal,
                'delivery_cost'  => (float) $order->delivery_cost,
                'total'          => (float) $order->total,
            ],
            'revenue_invoice'        => $revenueInvoice ? $this->formatInvoice($revenueInvoice) : null,
            'draft_revenue_invoices' => $draftRevenue->map(fn ($f) => $this->formatInvoice($f))->values(),
            'supplier_invoices'      => $invoices
                ->filter(fn (FinanceInvoice $f) => $f->role === FinanceInvoice::ROLE_SUPPLIER)
                ->map(fn ($f) => $this->formatInvoice($f))->values(),
            'register_entries'       => $invoices
                ->filter(fn (FinanceInvoice $f) => ($f->role ?? FinanceInvoice::ROLE_REGISTER) === FinanceInvoice::ROLE_REGISTER)
                ->map(fn ($f) => $this->formatInvoice($f))->values(),
            'costs' => [
                'lines'   => $costs->map(fn (OrderCost $c) => [
                    'id'          => $c->id,
                    'type'        => $c->type,
                    'type_label'  => OrderCost::TYPES[$c->type] ?? $c->type,
                    'label'       => $c->label,
                    'amount'      => (float) $c->amount,
                    'currency'    => $c->currency,
                    'recorded_by' => $c->recordedBy?->name,
                    'recorded_at' => $c->created_at?->toIso8601String(),
                ])->values(),
                'total' => round((float) $costs->sum('amount'), 2),
            ],
            'profitability' => $this->profitability->forOrder($order),
            'signoff'       => $this->signoffs->state($order, $request->user()),
        ];

        // eBay orders carry a predictable fee; until someone records the real
        // one, offer the configured estimate so the panel can prefill it.
        if ($order->channel() === Order::CHANNEL_EBAY && ! $costs->contains(fn ($c) => $c->type === 'ebay_fee')) {
            $percent = (float) config('services.ebay_sell.fee_percent', 0);
            $fixed   = (float) config('services.ebay_sell.fee_fixed', 0);

            if ($percent > 0 || $fixed > 0) {
                $data['suggested_ebay_fee'] = round((float) $order->total * $percent / 100 + $fixed, 2);
            }
        }

        return response()->json([
            'data'    => $data,
            'meta'    => ['cost_types' => OrderCost::TYPES, 'available' => $this->profitability->available()],
            'message' => 'success',
        ]);
    }

    // -------------------------------------------------------------------------

    /**
     * @return array<string, array<int, mixed>>
     */
    private function listFilterRules(): array
    {
        return [
            'from'          => ['nullable', 'date'],
            'to'            => ['nullable', 'date'],
            'channel'       => ['nullable', Rule::in(Order::CHANNELS)],
            'status'        => ['nullable', 'string', 'max:30'],
            'q'             => ['nullable', 'string', 'max:100'],
            'verified'      => ['nullable', 'in:yes,no'],
            'profitability' => ['nullable', 'in:complete,awaiting'],
            'include_cancelled' => ['nullable', 'in:yes,no'],
        ];
    }

    /**
     * Orders that belong in a money report at all: cancelled ones out unless
     * asked for, Stripe test sessions always out (same exclusion as the
     * dashboard, and for the same reason).
     */
    private function baseOrders(): Builder
    {
        return Order::query()
            ->where(function ($q) {
                $q->whereNull('payment_session_id')
                    ->orWhere('payment_session_id', 'not like', 'cs_test_%');
            });
    }

    private function filteredOrders(Request $request): Builder
    {
        $query = $this->baseOrders()->orderByDesc('created_at')->orderByDesc('id');

        if ($request->input('include_cancelled') !== 'yes') {
            $query->where('status', '!=', 'cancelled');
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->input('to'));
        }

        if ($request->filled('channel')) {
            $query->channel($request->input('channel'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('q')) {
            $q = $request->input('q');
            $query->where(fn ($sub) => $sub->where('ref', 'like', "%{$q}%")
                ->orWhere('customer_name', 'like', "%{$q}%")
                ->orWhere('customer_email', 'like', "%{$q}%"));
        }

        if ($request->filled('profitability') && $this->profitability->available()) {
            // whereNotNull matters: one NULL in a NOT IN subquery and SQL
            // returns no rows at all.
            $finalized = FinanceInvoice::query()->finalizedRevenue()->whereNotNull('order_ref')->select('order_ref');

            $request->input('profitability') === 'complete'
                ? $query->whereIn('ref', $finalized)
                : $query->whereNotIn('ref', $finalized);
        }

        if ($request->filled('verified') && app(OrderSignoffService::class)->recordingAvailable()) {
            $opsSigned     = OrderSignoff::query()->live()->where('slot', OrderSignoff::SLOT_OPS)->select('order_ref');
            $financeSigned = OrderSignoff::query()->live()->where('slot', OrderSignoff::SLOT_FINANCE)->select('order_ref');

            $request->input('verified') === 'yes'
                ? $query->whereIn('ref', $opsSigned)->whereIn('ref', $financeSigned)
                : $query->where(fn ($sub) => $sub->whereNotIn('ref', $opsSigned)->orWhereNotIn('ref', $financeSigned));
        }

        return $query;
    }

    /**
     * List rows for a set of orders — grouped queries, not queries per row.
     *
     * @param  \Illuminate\Support\Collection<int, Order>  $orders
     * @return array<int, array<string, mixed>>
     */
    private function rows($orders): array
    {
        $refs   = $orders->pluck('ref')->all();
        $totals = $this->profitability->totalsForRefs($refs);

        $signatures = $this->signoffs->recordingAvailable() && $refs !== []
            ? OrderSignoff::query()->live()->whereIn('order_ref', $refs)->get()->groupBy('order_ref')
            : collect();

        return $orders->map(function (Order $order) use ($totals, $signatures) {
            $live    = $signatures->get($order->ref, collect());
            $ops     = $live->firstWhere('slot', OrderSignoff::SLOT_OPS);
            $finance = $live->firstWhere('slot', OrderSignoff::SLOT_FINANCE);

            return array_merge([
                'ref'            => $order->ref,
                'date'           => $order->created_at?->toDateString(),
                'customer_name'  => $order->customer_name,
                'channel'        => $order->channel(),
                'status'         => $order->status,
                'payment_status' => $order->payment_status,
                'currency'       => $order->currency ?: 'EUR',
                'order_total'    => (float) $order->total,
            ], $this->profitability->profitBlock($totals[$order->ref]), [
                'signoff' => [
                    'ops_signed_by'      => $ops?->admin_name,
                    'ops_signed_at'      => $ops?->signed_at?->toIso8601String(),
                    'finance_signed_by'  => $finance?->admin_name,
                    'finance_signed_at'  => $finance?->signed_at?->toIso8601String(),
                ],
                // "Verified" in finance's sense: both signatures stand.
                'verified' => $ops !== null && $finance !== null,
            ]);
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatInvoice(FinanceInvoice $f): array
    {
        return [
            'id'              => $f->id,
            'system'          => $f->system,
            'external_number' => $f->external_number,
            'invoice_number'  => $f->invoice_number,
            'role'            => $f->role ?? FinanceInvoice::ROLE_REGISTER,
            'supplier_name'   => $f->supplier_name,
            'amount'          => $f->amount === null ? null : (float) $f->amount,
            'currency'        => $f->currency,
            'issued_on'       => $f->issued_on?->toDateString(),
            'finalized'       => $f->isFinalized(),
            'finalized_at'    => $f->finalized_at?->toIso8601String(),
            'finalized_by'    => $f->finalizedBy?->name,
            'recorded_by'     => $f->recordedBy?->name,
            'notes'           => $f->notes,
            'has_file'        => $f->hasFile(),
            'file_name'       => $f->original_filename,
            // Served through the existing authenticated register download.
            'download_path'   => $f->hasFile() ? "/api/v1/admin/finance-invoices/{$f->id}/download" : null,
        ];
    }
}
