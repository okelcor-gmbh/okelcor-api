<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Console\Command;

/**
 * Finds (and optionally corrects) eBay orders affected by a pricing import
 * bug: EbayOrderSyncService::importOrder() treated eBay's `lineItemCost`
 * (documented by eBay as unit price x quantity — i.e. the LINE TOTAL) as a
 * per-unit price, then multiplied by quantity again. Only line items with
 * quantity > 1 were affected — quantity 1 lines happen to compute the same
 * either way.
 *
 * Detection: for each eBay order, compare SUM(order_items.line_total) to
 * order.subtotal (which came straight from eBay's own pricingSummary, so is
 * unaffected by the bug) — a mismatch beyond rounding, on an order with a
 * quantity > 1 line item, is the bug's signature.
 *
 * Correction (only applied to flagged quantity > 1 items):
 *   correct unit_price = old unit_price / quantity   (recovers true per-unit)
 *   correct line_total = old unit_price               (that WAS the true line total)
 *
 * Read-only by default — pass --apply to actually write corrections.
 */
class AuditEbayLineItemPricing extends Command
{
    protected $signature = 'ebay:audit-line-item-pricing {--apply : Write the corrections (default is report-only)}';

    protected $description = 'Find/fix eBay order line items where lineItemCost was wrongly treated as a per-unit price';

    private const TOLERANCE = 0.05;

    public function handle(): int
    {
        $apply = $this->option('apply');

        $orders = Order::where('source', 'ebay')->with('items')->get();
        $flagged = 0;
        $corrected = 0;

        foreach ($orders as $order) {
            $sumLineTotal = $order->items->sum('line_total');
            $mismatch     = abs($sumLineTotal - (float) $order->subtotal) > self::TOLERANCE;

            $affectedItems = $order->items->filter(fn (OrderItem $i) => $i->quantity > 1);

            if (! $mismatch || $affectedItems->isEmpty()) {
                continue;
            }

            $flagged++;

            $this->line("Order {$order->ref} (eBay {$order->ebay_order_id}): subtotal={$order->subtotal}, sum(line_total)={$sumLineTotal}");

            foreach ($affectedItems as $item) {
                $correctUnitPrice = round((float) $item->unit_price / $item->quantity, 2);
                $correctLineTotal = round((float) $item->unit_price, 2);

                $this->line(sprintf(
                    '  item #%d "%s" qty=%d: unit_price %.2f -> %.2f, line_total %.2f -> %.2f',
                    $item->id, $item->name, $item->quantity,
                    $item->unit_price, $correctUnitPrice,
                    $item->line_total, $correctLineTotal
                ));

                if ($apply) {
                    $item->update([
                        'unit_price' => $correctUnitPrice,
                        'line_total' => $correctLineTotal,
                    ]);
                    $corrected++;
                }
            }
        }

        if ($flagged === 0) {
            $this->info('No affected eBay orders found.');
            return self::SUCCESS;
        }

        $this->info($apply
            ? "Corrected {$corrected} line item(s) across {$flagged} order(s)."
            : "{$flagged} order(s) flagged (dry run — re-run with --apply to write corrections).");

        return self::SUCCESS;
    }
}
