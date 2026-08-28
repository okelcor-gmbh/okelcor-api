<?php

namespace App\Services;

use App\Models\FinanceInvoice;
use App\Models\Order;
use App\Models\OrderCost;
use Illuminate\Support\Facades\Schema;

/**
 * What an order actually earned.
 *
 * One formula, used by the list, the detail view, the CSV export and the
 * monthly summary so they can never disagree:
 *
 *   profit = finalized revenue invoice − supplier invoices − fee lines
 *
 * Revenue is the finalized (customer-agreed) revenue invoice from the
 * register, NOT the order total — the total is what we asked for, the invoice
 * is what was agreed. Until that invoice exists the profit is deliberately
 * null rather than a guess, and the row says why (`awaiting_revenue_invoice`).
 *
 * Sums are arithmetic across whatever currencies the rows carry — the
 * register stores currency as a label with no FX anywhere in this system, and
 * inventing a conversion rate here would put a made-up number in a profit
 * report. In practice everything is EUR.
 */
class OrderProfitabilityService
{
    /**
     * Deploy-order safety, same pattern as the sign-off service: the order
     * detail path must keep working between this code shipping and the
     * migrations running. Memoised — Schema introspection is a real query.
     */
    private static ?bool $ready = null;

    public function available(): bool
    {
        return self::$ready ??= Schema::hasTable('order_costs')
            && Schema::hasColumn('finance_invoices', 'role');
    }

    /** Test seam — the harness builds the tables after the container boots. */
    public static function forgetCheck(): void
    {
        self::$ready = null;
    }

    /**
     * The money totals for a page of orders in three grouped queries, instead
     * of three queries per row.
     *
     * @param  list<string>  $refs
     * @return array<string, array<string, mixed>>  keyed by order ref
     */
    public function totalsForRefs(array $refs): array
    {
        $totals = array_fill_keys($refs, [
            'revenue'                => null,
            'revenue_invoice_number' => null,
            'supplier_costs'         => 0.0,
            'supplier_invoice_count' => 0,
            'fees'                   => 0.0,
            'fee_count'              => 0,
        ]);

        if ($refs === [] || ! $this->available()) {
            return $totals;
        }

        // Finalize enforces one live revenue invoice per order; summed anyway
        // so that if history ever disagrees, the report shows the data rather
        // than hiding rows.
        foreach (FinanceInvoice::query()->finalizedRevenue()->whereIn('order_ref', $refs)
            ->get(['order_ref', 'amount', 'external_number']) as $invoice) {
            $totals[$invoice->order_ref]['revenue'] =
                ($totals[$invoice->order_ref]['revenue'] ?? 0.0) + (float) $invoice->amount;
            $totals[$invoice->order_ref]['revenue_invoice_number'] ??= $invoice->external_number;
        }

        foreach (FinanceInvoice::query()->where('role', FinanceInvoice::ROLE_SUPPLIER)
            ->whereIn('order_ref', $refs)
            ->selectRaw('order_ref, COALESCE(SUM(amount), 0) as total, COUNT(*) as entries')
            ->groupBy('order_ref')->get() as $row) {
            $totals[$row->order_ref]['supplier_costs']         = (float) $row->total;
            $totals[$row->order_ref]['supplier_invoice_count'] = (int) $row->entries;
        }

        foreach (OrderCost::query()->whereIn('order_ref', $refs)
            ->selectRaw('order_ref, COALESCE(SUM(amount), 0) as total, COUNT(*) as entries')
            ->groupBy('order_ref')->get() as $row) {
            $totals[$row->order_ref]['fees']      = (float) $row->total;
            $totals[$row->order_ref]['fee_count'] = (int) $row->entries;
        }

        return $totals;
    }

    /**
     * The computed profit block for one order, given its totals entry.
     *
     * @param  array<string, mixed>  $totals  one entry from totalsForRefs()
     * @return array<string, mixed>
     */
    public function profitBlock(array $totals): array
    {
        $revenue    = $totals['revenue'];
        $totalCosts = round($totals['supplier_costs'] + $totals['fees'], 2);
        $profit     = $revenue === null ? null : round($revenue - $totalCosts, 2);

        return [
            'revenue'                => $revenue === null ? null : round($revenue, 2),
            'revenue_invoice_number' => $totals['revenue_invoice_number'],
            'supplier_costs'         => round($totals['supplier_costs'], 2),
            'supplier_invoice_count' => $totals['supplier_invoice_count'],
            'fees'                   => round($totals['fees'], 2),
            'fee_count'              => $totals['fee_count'],
            'total_costs'            => $totalCosts,
            'profit'                 => $profit,
            'margin_percent'         => ($profit === null || abs($revenue) < 0.005)
                ? null
                : round($profit / $revenue * 100, 1),
            // Why profit may be null — the state finance acts on: the order is
            // done but nobody has finalized its revenue invoice yet.
            'profitability_status'   => $revenue === null ? 'awaiting_revenue_invoice' : 'complete',
        ];
    }

    /** Convenience for a single order (detail view). */
    public function forOrder(Order $order): array
    {
        return $this->profitBlock($this->totalsForRefs([$order->ref])[$order->ref]);
    }
}
