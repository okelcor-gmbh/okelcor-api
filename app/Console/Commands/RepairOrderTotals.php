<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderLog;
use Illuminate\Console\Command;

/**
 * Finds orders whose stored subtotal no longer matches their line items and
 * corrects them.
 *
 * Written for a specific fault: adding a line item to an order that was
 * created without items double-counted the money. Such an order carries a
 * placeholder subtotal equal to the hand-typed total, and the old code added
 * the new line on top of it — a €15,000 order showed a €30,000 total. The
 * cause is fixed in Order::recalculateTotalsFromItems; this repairs the rows
 * already written.
 *
 * Reports by default. Writes only with --fix.
 */
class RepairOrderTotals extends Command
{
    protected $signature = 'orders:repair-totals
                            {--ref=            : Limit to one order reference (e.g. OKL-548XDW)}
                            {--fix             : Actually write the corrections (otherwise report only)}
                            {--include-locked  : Also fix orders whose financials are locked — see the warning below}';

    protected $description = 'Report (and optionally correct) orders whose stored total disagrees with their line items.';

    public function handle(): int
    {
        $fix           = (bool) $this->option('fix');
        $includeLocked = (bool) $this->option('include-locked');
        $ref           = $this->option('ref');

        $query = Order::query()->whereHas('items');

        if ($ref) {
            $query->where('ref', $ref);
        }

        $mismatched = [];
        $lockedSkipped = 0;

        $query->orderBy('id')->chunk(200, function ($orders) use (&$mismatched, &$lockedSkipped) {
            $sums = OrderItem::whereIn('order_id', $orders->pluck('id'))
                ->selectRaw('order_id, SUM(line_total) AS items_total')
                ->groupBy('order_id')
                ->pluck('items_total', 'order_id');

            foreach ($orders as $order) {
                $itemsTotal = round((float) ($sums[$order->id] ?? 0), 2);
                $subtotal   = round((float) $order->subtotal, 2);

                // Tolerance of one cent: decimal(10,2) round-tripping through
                // float should not be reported as a bookkeeping error.
                if (abs($itemsTotal - $subtotal) < 0.01) {
                    continue;
                }

                $extras = round((float) $order->total - $subtotal, 2);

                $mismatched[] = [
                    'order'       => $order,
                    'items_total' => $itemsTotal,
                    'subtotal'    => $subtotal,
                    'total'       => round((float) $order->total, 2),
                    'new_total'   => round($itemsTotal + $extras, 2),
                    'extras'      => $extras,
                ];
            }
        });

        if (! $mismatched) {
            $this->info($ref
                ? "Order '{$ref}' either does not exist, has no items, or its total already matches them."
                : 'No orders found whose total disagrees with their line items.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->warn(count($mismatched) . ' order(s) with a total that disagrees with the line items:');
        $this->line('');

        $rows = [];
        foreach ($mismatched as $m) {
            $order  = $m['order'];
            $locked = $order->isFinancialsLocked();

            if ($locked) {
                $lockedSkipped++;
            }

            $rows[] = [
                $order->ref . ($locked ? ' [locked]' : ''),
                $order->source ?? 'website',
                number_format($m['items_total'], 2),
                number_format($m['subtotal'], 2),
                number_format($m['total'], 2),
                number_format($m['new_total'], 2),
                number_format($m['extras'], 2),
            ];
        }

        $this->table(
            ['Ref', 'Source', 'Items sum', 'Stored subtotal', 'Stored total', 'Corrected total', 'Non-line extras'],
            $rows
        );

        if ($lockedSkipped > 0) {
            $this->line('');
            $this->warn("{$lockedSkipped} of these have locked financials — a commercial document has already been issued");
            $this->warn('carrying the wrong figure. Correcting the order does NOT supersede that document; the customer may');
            $this->warn('be holding an invoice for the old amount. Pass --include-locked only once you have decided how to');
            $this->warn('reissue, and expect to supersede the affected documents afterwards.');
        }

        if (! $fix) {
            $this->line('');
            $this->info('Report only — nothing written. Re-run with --fix to apply these corrections.');

            return self::SUCCESS;
        }

        $applied = 0;
        $skipped = 0;

        foreach ($mismatched as $m) {
            $order = $m['order'];

            if ($order->isFinancialsLocked() && ! $includeLocked) {
                $skipped++;
                continue;
            }

            $result = $order->recalculateTotalsFromItems();

            if (! $result['changed']) {
                continue;
            }

            OrderLog::create([
                'order_id'         => $order->id,
                'order_ref'        => $order->ref,
                'admin_user_id'    => null,
                'admin_user_email' => 'console:orders:repair-totals',
                'action'           => 'totals_repaired',
                'old_value'        => 'total ' . number_format($result['total_from'], 2),
                'new_value'        => 'total ' . number_format($result['total_to'], 2),
                'notes'            => 'Totals re-derived from line items. Stored subtotal '
                    . number_format($result['subtotal_from'], 2) . ' did not match the items sum '
                    . number_format($result['subtotal_to'], 2)
                    . '. Cause: adding a line item to an order created without items counted the same money twice.',
                'ip_address'       => null,
            ]);

            $applied++;
            $this->line("  corrected {$order->ref}: total "
                . number_format($result['total_from'], 2) . ' → ' . number_format($result['total_to'], 2));
        }

        $this->line('');
        $this->info("Corrected {$applied} order(s).");

        if ($skipped > 0) {
            $this->warn("Skipped {$skipped} order(s) with locked financials — pass --include-locked to correct those too.");
        }

        return self::SUCCESS;
    }
}
