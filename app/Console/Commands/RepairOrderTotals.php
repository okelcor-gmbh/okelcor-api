<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Surveys orders whose stored subtotal disagrees with their line items, sorts
 * them by cause, and repairs only the one cause that is unambiguous.
 *
 * A disagreement is NOT by itself a fault. Two legitimate reasons exist:
 *
 *   - Wix-imported orders store the gross (VAT-inclusive) figure the customer
 *     actually paid in `total`/`subtotal`, while the imported line items carry
 *     the net Wix prices. On the German 19% rate that is a stored subtotal of
 *     exactly items x 1.19. The total is correct; only the subtotal column is
 *     carrying gross. Rewriting these from the items would cut real customer
 *     orders by 19%.
 *   - Orders whose items sum to MORE than the recorded total. Cause unknown,
 *     ratios inconsistent — not something to guess at.
 *
 * So this fixes exactly one signature: an `admin_manual` order recorded as a
 * lump sum (subtotal == total, no non-line extras) whose stored figure is
 * exactly twice its items. That is the double-count fixed in
 * Order::recalculateTotalsFromItems — the order was created without items, so
 * the hand-typed total was stored as a placeholder subtotal, and the first
 * line added was charged on top of it.
 *
 * Everything else is reported and left alone. Reports by default; writes only
 * with --fix.
 */
class RepairOrderTotals extends Command
{
    protected $signature = 'orders:repair-totals
                            {--ref=            : Limit the survey to one order reference}
                            {--fix             : Repair the double-counted orders (report only without it)}
                            {--include-locked  : Also repair orders whose financials are locked — see the warning it prints}';

    protected $description = 'Survey orders whose total disagrees with their line items, and repair the double-counted ones.';

    /** Stored subtotal is exactly twice the items — the Add Item double count. */
    private const DOUBLE_COUNT = 'double_count';

    /** Stored figure is items x 1.19 — Wix gross vs net line items. Correct as recorded. */
    private const VAT_INCLUSIVE = 'vat_inclusive';

    /** Items sum to more than the order says it is worth. Cause unknown. */
    private const ITEMS_EXCEED = 'items_exceed_total';

    /** Stored figure is higher than the items for no reason we can attribute. */
    private const UNEXPLAINED = 'unexplained';

    public function handle(): int
    {
        $fix           = (bool) $this->option('fix');
        $includeLocked = (bool) $this->option('include-locked');
        $ref           = $this->option('ref');

        $query = Order::query()->whereHas('items');

        if ($ref) {
            $query->where('ref', $ref);
        }

        $found = [];

        $query->orderBy('id')->chunk(200, function ($orders) use (&$found) {
            $sums = OrderItem::whereIn('order_id', $orders->pluck('id'))
                ->selectRaw('order_id, SUM(line_total) AS items_total')
                ->groupBy('order_id')
                ->pluck('items_total', 'order_id');

            foreach ($orders as $order) {
                $itemsTotal = round((float) ($sums[$order->id] ?? 0), 2);
                $subtotal   = round((float) $order->subtotal, 2);

                // One cent of tolerance: decimal(10,2) round-tripping through
                // float is not a bookkeeping error.
                if (abs($itemsTotal - $subtotal) < 0.01) {
                    continue;
                }

                $extras = round((float) $order->total - $subtotal, 2);

                $found[] = [
                    'order'       => $order,
                    'items_total' => $itemsTotal,
                    'subtotal'    => $subtotal,
                    'total'       => round((float) $order->total, 2),
                    'extras'      => $extras,
                    'ratio'       => $itemsTotal > 0 ? round($subtotal / $itemsTotal, 4) : 0.0,
                    'class'       => $this->classify($order, $itemsTotal, $subtotal, $extras),
                ];
            }
        });

        if (! $found) {
            $this->info($ref
                ? "Order '{$ref}' either does not exist, has no items, or its subtotal already matches them."
                : 'No orders found whose subtotal disagrees with their line items.');

            return self::SUCCESS;
        }

        $this->report($found);

        $repairable = array_values(array_filter($found, fn ($f) => $f['class'] === self::DOUBLE_COUNT));

        if (! $repairable) {
            $this->line('');
            $this->info('Nothing here matches the double-count signature. Nothing to repair.');

            return self::SUCCESS;
        }

        if (! $fix) {
            $this->line('');
            $this->info('Survey only — nothing written. Re-run with --fix to repair the '
                . count($repairable) . ' double-counted order(s). Everything else is left alone.');

            return self::SUCCESS;
        }

        return $this->repair($repairable, $includeLocked);
    }

    /**
     * Why this order's subtotal disagrees with its items. Only DOUBLE_COUNT is
     * ever acted on — the rest exist so the survey says what it is looking at
     * rather than lumping everything under "broken".
     */
    private function classify(Order $order, float $itemsTotal, float $subtotal, float $extras): string
    {
        if ($itemsTotal > $subtotal) {
            return self::ITEMS_EXCEED;
        }

        // Gross-vs-net from the Wix import. Checked before the double count so
        // an order can never be repaired on a coincidence of ratios.
        foreach ([1.19, 1.16, 1.07] as $vatRate) {
            if (abs($subtotal - round($itemsTotal * $vatRate, 2)) <= 0.02) {
                return self::VAT_INCLUSIVE;
            }
        }

        // The Add Item double count: an order recorded as a lump sum with no
        // items, then itemised. `admin_manual` is the only source that writes
        // a hand-typed total with no lines (AdminOrderController::store), and
        // extras must be zero because store() writes subtotal == total.
        if ($order->source === 'admin_manual'
            && abs($extras) < 0.01
            && abs($subtotal - round($itemsTotal * 2, 2)) < 0.01) {
            return self::DOUBLE_COUNT;
        }

        return self::UNEXPLAINED;
    }

    private function report(array $found): void
    {
        $labels = [
            self::DOUBLE_COUNT  => 'DOUBLE COUNT — repairable',
            self::VAT_INCLUSIVE => 'VAT-inclusive — leave alone',
            self::ITEMS_EXCEED  => 'items exceed total — investigate',
            self::UNEXPLAINED   => 'unexplained — investigate',
        ];

        $this->line('');
        $this->warn(count($found) . ' order(s) whose stored subtotal disagrees with their line items.');
        $this->line('');

        $rows = [];
        foreach ($found as $f) {
            $order  = $f['order'];
            $locked = $order->isFinancialsLocked();

            $rows[] = [
                $order->ref . ($locked ? ' [locked]' : ''),
                $order->source ?? 'website',
                number_format($f['items_total'], 2),
                number_format($f['subtotal'], 2),
                number_format($f['total'], 2),
                number_format($f['ratio'], 4),
                $labels[$f['class']],
            ];
        }

        $this->table(
            ['Ref', 'Source', 'Items sum', 'Stored subtotal', 'Stored total', 'Sub/items', 'Diagnosis'],
            $rows
        );

        $counts = array_count_values(array_column($found, 'class'));

        if (($counts[self::VAT_INCLUSIVE] ?? 0) > 0) {
            $this->line('');
            $this->info(($counts[self::VAT_INCLUSIVE]) . ' order(s) are VAT-inclusive and are NOT touched.');
            $this->line('  Wix imports store the gross figure the customer actually paid in `total`, while the');
            $this->line('  imported line items carry the net prices — so subtotal = items x 1.19 is expected and');
            $this->line('  the total is correct. Rebuilding these from the items would cut real orders by 19%.');
        }

        if (($counts[self::ITEMS_EXCEED] ?? 0) > 0) {
            $this->line('');
            $this->warn(($counts[self::ITEMS_EXCEED]) . ' order(s) have items summing to MORE than the recorded total.');
            $this->line('  Not the double-count fault and not a consistent ratio, so no rule here can be trusted.');
            $this->line('  These need a person to compare each against what the customer was actually invoiced.');
        }

        if (($counts[self::UNEXPLAINED] ?? 0) > 0) {
            $this->line('');
            $this->warn(($counts[self::UNEXPLAINED]) . ' order(s) do not match any known cause. Left alone.');
        }
    }

    private function repair(array $repairable, bool $includeLocked): int
    {
        $lockedCount = count(array_filter($repairable, fn ($f) => $f['order']->isFinancialsLocked()));

        if ($lockedCount > 0 && ! $includeLocked) {
            $this->line('');
            $this->warn("{$lockedCount} of the double-counted orders have locked financials — a commercial document");
            $this->warn('has already been issued carrying the doubled figure. Repairing the order does NOT supersede');
            $this->warn('that document, so the customer may be holding an invoice for the wrong amount. Pass');
            $this->warn('--include-locked once you have decided how to reissue.');
        }

        $applied = 0;
        $skipped = 0;

        foreach ($repairable as $f) {
            $order = $f['order'];

            if ($order->isFinancialsLocked() && ! $includeLocked) {
                $skipped++;
                continue;
            }

            // Order row and its audit log in one transaction. The first run of
            // this command updated an order and then died on the log insert,
            // leaving a corrected order with no record of why.
            $result = DB::transaction(function () use ($order) {
                $result = $order->recalculateTotalsFromItems();

                if ($result['changed']) {
                    OrderLog::create([
                        'order_id'         => $order->id,
                        'order_ref'        => $order->ref,
                        'admin_user_id'    => null,
                        'admin_user_email' => 'console:orders:repair-totals',
                        'action'           => 'totals_repaired',
                        'old_value'        => 'total ' . number_format($result['total_from'], 2),
                        'new_value'        => 'total ' . number_format($result['total_to'], 2),
                        'notes'            => 'Totals re-derived from line items. Stored subtotal '
                            . number_format($result['subtotal_from'], 2) . ' was exactly twice the items sum '
                            . number_format($result['subtotal_to'], 2)
                            . '. Cause: the order was recorded as a lump sum with no line items, so the hand-typed'
                            . ' total was stored as a placeholder subtotal, and the first item added was counted on'
                            . ' top of it.',
                        'ip_address'       => null,
                    ]);
                }

                return $result;
            });

            if (! $result['changed']) {
                continue;
            }

            $applied++;
            $this->line("  repaired {$order->ref}: total "
                . number_format($result['total_from'], 2) . ' → ' . number_format($result['total_to'], 2));
        }

        $this->line('');
        $this->info("Repaired {$applied} order(s).");

        if ($skipped > 0) {
            $this->warn("Skipped {$skipped} locked order(s) — pass --include-locked to repair those too.");
        }

        return self::SUCCESS;
    }
}
