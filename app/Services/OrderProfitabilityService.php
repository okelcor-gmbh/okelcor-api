<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderCostLine;
use App\Models\OrderFinanceRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Profit per order: the finalized revenue invoice minus the supplier invoices
 * and channel fees recorded against it.
 *
 * The figure is computed here and nowhere else. The order page, the finance
 * list, the CSV export and the dashboard all call the same arithmetic —
 * two places that compute are two places that can disagree about a number
 * the business is reading, and this one decides whether an order made money.
 *
 * Currencies are matched, never converted. A cost in a currency other than
 * the revenue invoice's is named separately and left out of the profit,
 * because converting at today's rate would make a historic order's margin
 * change every time the page is opened — the same rule the operations board
 * follows.
 */
class OrderProfitabilityService
{
    /**
     * The same "confirmed business" set the operations board and report use.
     * Profitability is shown for any order, but this is what the list and the
     * dashboard count by default — an order that was cancelled did not make
     * or lose the money its lines describe.
     */
    public const CONFIRMED_STATUSES = ['confirmed', 'processing', 'shipped', 'delivered'];

    public const DEFINITIONS = [
        'revenue'        => 'The finalized revenue invoice recorded by finance — the one the customer agreed to. Not the order total: the order total is what was ordered, this is what was invoiced.',
        'supplier_costs' => 'Sum of cost lines of kind supplier_invoice, in the revenue currency. Other currencies are named separately, never converted.',
        'fees'           => 'Sum of cost lines of kind fee (Stripe, eBay, bank, shipping, other), in the revenue currency.',
        'profit'         => 'Revenue amount minus supplier costs minus fees. Null until a revenue invoice is recorded — an order with costs and no revenue figure has an unknown profit, not a negative one.',
        'margin_percent' => 'Profit as a percentage of revenue. Null when revenue is zero or absent.',
        'verified'       => 'Finance has signed the calculation off. Withdrawn automatically if any figure moves afterwards.',
        'buckets'        => 'Orders fall into the month they were RAISED (created_at), matching the operations report, so the two never disagree about which month an order belongs to.',
        'eur_only'       => 'Dashboard sums cover EUR-denominated figures only; orders with a non-EUR revenue invoice are counted but their money is reported in non_eur_orders rather than converted.',
    ];

    // -------------------------------------------------------------------------
    // Per-order
    // -------------------------------------------------------------------------

    /**
     * The full profitability block for one order. Relations are loaded here if
     * the caller has not already.
     *
     * @return array<string, mixed>
     */
    public function forOrder(Order $order): array
    {
        $order->loadMissing(['financeRecord', 'costLines']);

        $record = $order->financeRecord;
        $lines  = $order->costLines;

        $currency = $record?->revenue_currency ?? ($order->currency ?? 'EUR');

        $costs = $this->costTotals($lines, $currency);

        $revenueAmount = $record?->revenue_amount !== null ? (float) $record->revenue_amount : null;

        $profit = $revenueAmount === null ? null : round($revenueAmount - $costs['total'], 2);

        return [
            'revenue' => $record === null || ! $record->hasRevenueInvoice() ? null : [
                'invoice_number'     => $record->revenue_invoice_number,
                'amount'             => $revenueAmount,
                'currency'           => $record->revenue_currency,
                'issued_on'          => $record->revenue_issued_on?->toDateString(),
                'finalized_at'       => $record->revenue_finalized_at?->toIso8601String(),
                'customer_agreed_at' => $record->customer_agreed_at?->toIso8601String(),
                'has_file'           => $record->hasFile(),
                'file_name'          => $record->revenue_original_filename,
                'uploaded_at'        => $record->revenue_uploaded_at?->toIso8601String(),
                'set_by'             => $record->setBy?->name,
                // What was invoiced against what was ordered — the variance
                // finance reconciles, shown only when the currencies agree.
                'variance_from_order_total' => $record->revenue_currency === ($order->currency ?? 'EUR')
                    ? round($revenueAmount - (float) $order->total, 2)
                    : null,
            ],
            'costs' => [
                'supplier_total'   => $costs['supplier'],
                'fees_total'       => $costs['fees'],
                'total'            => $costs['total'],
                'currency'         => $currency,
                'by_category'      => $costs['by_category'],
                'lines_count'      => $lines->count(),
                // Named, not converted, and excluded from the totals above.
                'other_currencies' => $costs['other_currencies'],
            ],
            'profit' => [
                'amount'         => $profit,
                'margin_percent' => ($profit !== null && $revenueAmount > 0)
                    ? round(($profit / $revenueAmount) * 100, 1)
                    : null,
                'currency'       => $currency,
                'mixed_currency' => $costs['other_currencies'] !== [],
            ],
            'verification' => [
                'verified'    => (bool) $record?->isVerified(),
                'verified_at' => $record?->verified_at?->toIso8601String(),
                'verified_by' => $record?->verifiedBy?->name,
                'note'        => $record?->verified_note,
            ],
            'lines' => $lines->map(fn (OrderCostLine $line) => $this->formatLine($line))->values()->all(),
        ];
    }

    /**
     * The compact block the order detail page embeds, so order tracking knows
     * whether the finalized invoice exists without a second request. Null when
     * the tables have not been migrated yet — the order page must never fail
     * because a reporting feature arrived before its migration.
     *
     * @return array<string, mixed>|null
     */
    public function summaryForOrder(Order $order): ?array
    {
        if (! OrderFinanceRecord::available()) {
            return null;
        }

        $order->loadMissing(['financeRecord', 'costLines']);

        $record = $order->financeRecord;
        $full   = $this->forOrder($order);

        return [
            'has_revenue_invoice'    => $record !== null && $record->hasRevenueInvoice(),
            'revenue_invoice_number' => $record?->revenue_invoice_number,
            'revenue_amount'         => $full['revenue']['amount'] ?? null,
            'customer_agreed'        => $record?->customer_agreed_at !== null,
            'costs_total'            => $full['costs']['total'],
            'cost_lines'             => $full['costs']['lines_count'],
            'profit'                 => $full['profit']['amount'],
            'margin_percent'         => $full['profit']['margin_percent'],
            'currency'               => $full['profit']['currency'],
            'verified'               => $full['verification']['verified'],
        ];
    }

    // -------------------------------------------------------------------------
    // The list and its export
    // -------------------------------------------------------------------------

    /**
     * One row per order for the finance list — the reference, what it made,
     * and whether finance has signed it off.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listQuery(array $filters): Builder
    {
        $query = Order::query()
            ->with(['financeRecord.verifiedBy', 'costLines'])
            ->channel($filters['channel'] ?? null)
            // The same two exclusions as the operations board: cancelled
            // orders are not business, and Stripe test checkouts are not real.
            ->whereIn('status', self::CONFIRMED_STATUSES)
            ->where(fn ($q) => $q->whereNull('payment_session_id')
                ->orWhere('payment_session_id', 'not like', 'cs_test_%'))
            ->orderByDesc('created_at');

        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        if (! empty($filters['q'])) {
            $q = $filters['q'];
            $query->where(fn ($sub) => $sub->where('ref', 'like', "%{$q}%")
                ->orWhere('customer_name', 'like', "%{$q}%")
                ->orWhere('customer_email', 'like', "%{$q}%"));
        }

        if (! empty($filters['verified'])) {
            $filters['verified'] === 'yes'
                ? $query->whereHas('financeRecord', fn ($sub) => $sub->whereNotNull('verified_at'))
                : $query->whereDoesntHave('financeRecord', fn ($sub) => $sub->whereNotNull('verified_at'));
        }

        if (! empty($filters['has_revenue'])) {
            $filters['has_revenue'] === 'yes'
                ? $query->whereHas('financeRecord', fn ($sub) => $sub->whereNotNull('revenue_amount'))
                : $query->whereDoesntHave('financeRecord', fn ($sub) => $sub->whereNotNull('revenue_amount'));
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    public function listRow(Order $order): array
    {
        $full   = $this->forOrder($order);
        $record = $order->financeRecord;

        return [
            'order_id'               => $order->id,
            'order_ref'              => $order->ref,
            'order_date'             => $order->created_at?->toDateString(),
            'channel'                => $order->channel(),
            'customer_name'          => $order->customer_name,
            'status'                 => $order->status,
            'order_total'            => (float) $order->total,
            'order_currency'         => $order->currency ?? 'EUR',
            'revenue_invoice_number' => $record?->revenue_invoice_number,
            'revenue_amount'         => $full['revenue']['amount'] ?? null,
            'revenue_has_file'       => (bool) $record?->hasFile(),
            'supplier_costs'         => $full['costs']['supplier_total'],
            'fees'                   => $full['costs']['fees_total'],
            'costs_total'            => $full['costs']['total'],
            'profit'                 => $full['profit']['amount'],
            'margin_percent'         => $full['profit']['margin_percent'],
            'currency'               => $full['profit']['currency'],
            'mixed_currency'         => $full['profit']['mixed_currency'],
            'verified'               => $full['verification']['verified'],
            'verified_by'            => $full['verification']['verified_by'],
            'verified_at'            => $full['verification']['verified_at'],
        ];
    }

    /**
     * The list as spreadsheet rows — same computation, flattened for CSV.
     *
     * @return array<int, array<string, mixed>>
     */
    public function exportRows(Builder $query): array
    {
        $out = [];

        $query->clone()->chunk(200, function ($orders) use (&$out) {
            foreach ($orders as $order) {
                $row = $this->listRow($order);

                $out[] = [
                    'order_ref'       => $row['order_ref'],
                    'order_date'      => $row['order_date'],
                    'channel'         => $row['channel'] === Order::CHANNEL_EBAY ? 'ebay' : 'website',
                    'customer'        => $row['customer_name'],
                    'status'          => $row['status'],
                    'order_total'     => $row['order_total'],
                    'revenue_invoice' => $row['revenue_invoice_number'],
                    'revenue_amount'  => $row['revenue_amount'],
                    'supplier_costs'  => $row['supplier_costs'],
                    'fees'            => $row['fees'],
                    'costs_total'     => $row['costs_total'],
                    'profit'          => $row['profit'],
                    'margin_percent'  => $row['margin_percent'],
                    'currency'        => $row['currency'],
                    'verified'        => $row['verified'] ? 'yes' : 'no',
                    'verified_by'     => $row['verified_by'],
                    'verified_at'     => $row['verified_at'],
                ];
            }
        });

        return $out;
    }

    // -------------------------------------------------------------------------
    // Dashboard
    // -------------------------------------------------------------------------

    /**
     * Month-by-month summary from January of the given year to its end (or to
     * the current month for the running year). Gap-free — an empty month is a
     * zero, not a missing point.
     *
     * @return array<string, mixed>
     */
    public function dashboard(?int $year = null): array
    {
        $year ??= (int) CarbonImmutable::today()->format('Y');

        $start = CarbonImmutable::create($year, 1, 1)->startOfDay();
        $end   = $year === (int) CarbonImmutable::today()->format('Y')
            ? CarbonImmutable::today()->endOfDay()
            : $start->endOfYear();

        $orders = $this->listQuery(['from' => $start->toDateString(), 'to' => $end->toDateString()])
            ->get();

        $months = [];

        $cursor = $start;
        while ($cursor->lte($end)) {
            $months[$cursor->format('Y-m')] = $this->emptyMonth($cursor);
            $cursor = $cursor->addMonth();
        }

        foreach ($orders as $order) {
            $key = $order->created_at?->format('Y-m');

            if ($key === null || ! isset($months[$key])) {
                continue;
            }

            $month = &$months[$key];
            $full  = $this->forOrder($order);

            $month['orders']++;

            if (($order->currency ?? 'EUR') === 'EUR') {
                $month['order_total_eur'] = round($month['order_total_eur'] + (float) $order->total, 2);
            }

            $revenue = $full['revenue'];

            if ($revenue !== null && $revenue['amount'] !== null) {
                $month['orders_with_revenue']++;

                if ($revenue['currency'] === 'EUR') {
                    $month['revenue_eur']        = round($month['revenue_eur'] + $revenue['amount'], 2);
                    $month['supplier_costs_eur'] = round($month['supplier_costs_eur'] + $full['costs']['supplier_total'], 2);
                    $month['fees_eur']           = round($month['fees_eur'] + $full['costs']['fees_total'], 2);
                    $month['profit_eur']         = round($month['profit_eur'] + $full['profit']['amount'], 2);
                } else {
                    $month['non_eur_orders']++;
                }
            }

            if ($full['verification']['verified']) {
                $month['verified']++;
            }

            unset($month);
        }

        $months = array_values($months);

        foreach ($months as &$month) {
            $month['costs_eur'] = round($month['supplier_costs_eur'] + $month['fees_eur'], 2);
            $month['margin_percent'] = $month['revenue_eur'] > 0
                ? round(($month['profit_eur'] / $month['revenue_eur']) * 100, 1)
                : null;
        }
        unset($month);

        $totals = [
            'orders'              => array_sum(array_column($months, 'orders')),
            'orders_with_revenue' => array_sum(array_column($months, 'orders_with_revenue')),
            'order_total_eur'     => round(array_sum(array_column($months, 'order_total_eur')), 2),
            'revenue_eur'         => round(array_sum(array_column($months, 'revenue_eur')), 2),
            'supplier_costs_eur'  => round(array_sum(array_column($months, 'supplier_costs_eur')), 2),
            'fees_eur'            => round(array_sum(array_column($months, 'fees_eur')), 2),
            'costs_eur'           => round(array_sum(array_column($months, 'costs_eur')), 2),
            'profit_eur'          => round(array_sum(array_column($months, 'profit_eur')), 2),
            'verified'            => array_sum(array_column($months, 'verified')),
            'non_eur_orders'      => array_sum(array_column($months, 'non_eur_orders')),
        ];

        $totals['margin_percent'] = $totals['revenue_eur'] > 0
            ? round(($totals['profit_eur'] / $totals['revenue_eur']) * 100, 1)
            : null;

        return [
            'year'        => $year,
            'period'      => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
            'months'      => $months,
            'totals'      => $totals,
            'definitions' => self::DEFINITIONS,
        ];
    }

    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function formatLine(OrderCostLine $line): array
    {
        return [
            'id'          => $line->id,
            'kind'        => $line->kind,
            'category'    => $line->category,
            'supplier'    => $line->supplier,
            'reference'   => $line->reference,
            'amount'      => (float) $line->amount,
            'currency'    => $line->currency,
            'incurred_on' => $line->incurred_on?->toDateString(),
            'notes'       => $line->notes,
            'has_file'    => $line->hasFile(),
            'file_name'   => $line->original_filename,
            'uploaded_at' => $line->uploaded_at?->toIso8601String(),
            'entered_by'  => $line->enteredBy?->name,
            'created_at'  => $line->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, OrderCostLine>  $lines
     * @return array{supplier: float, fees: float, total: float, by_category: array<string, float>, other_currencies: array<string, float>}
     */
    private function costTotals($lines, string $currency): array
    {
        $supplier = 0.0;
        $fees     = 0.0;
        $byCategory = [];
        $other      = [];

        foreach ($lines as $line) {
            $amount = (float) $line->amount;

            if ($line->currency !== $currency) {
                $other[$line->currency] = round(($other[$line->currency] ?? 0) + $amount, 2);

                continue;
            }

            if ($line->kind === OrderCostLine::KIND_SUPPLIER_INVOICE) {
                $supplier += $amount;
            } else {
                $fees += $amount;
                $category = $line->category ?? 'other';
                $byCategory[$category] = round(($byCategory[$category] ?? 0) + $amount, 2);
            }
        }

        return [
            'supplier'         => round($supplier, 2),
            'fees'             => round($fees, 2),
            'total'            => round($supplier + $fees, 2),
            'by_category'      => $byCategory,
            'other_currencies' => $other,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyMonth(CarbonImmutable $cursor): array
    {
        return [
            'key'                 => $cursor->format('Y-m'),
            'label'               => $cursor->format('M Y'),
            'orders'              => 0,
            'orders_with_revenue' => 0,
            'order_total_eur'     => 0.0,
            'revenue_eur'         => 0.0,
            'supplier_costs_eur'  => 0.0,
            'fees_eur'            => 0.0,
            'profit_eur'          => 0.0,
            'verified'            => 0,
            'non_eur_orders'      => 0,
        ];
    }
}
