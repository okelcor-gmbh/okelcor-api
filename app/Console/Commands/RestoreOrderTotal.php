<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\OrderLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Writes an explicit subtotal/total back onto one order.
 *
 * Exists because the first version of `orders:repair-totals` was too willing:
 * it treated any order whose subtotal disagreed with its line items as broken,
 * when Wix-imported orders legitimately store the gross VAT-inclusive figure
 * against net line items. It rewrote order 10112 from 371.88 to 312.50 before
 * crashing on an unrelated ENUM, and that row needed putting back.
 *
 * Deliberately dumb: it takes the exact figures and applies them. There is no
 * detection here and no sweep — every use names one order and both numbers,
 * shows the change, and asks before writing. Use it to undo a bad automated
 * correction, not to adjust an order in the ordinary course of business (that
 * is what the admin panel and the revision workflow are for).
 */
class RestoreOrderTotal extends Command
{
    protected $signature = 'orders:restore-total
                            {ref       : Order reference (e.g. 10112)}
                            {subtotal  : Subtotal to write, e.g. 371.88}
                            {total     : Total to write, e.g. 371.88}
                            {--reason= : Why (recorded in the order log)}
                            {--force   : Skip the confirmation prompt}';

    protected $description = 'Write an explicit subtotal/total back onto one order — used to undo a bad automated correction.';

    public function handle(): int
    {
        $ref         = (string) $this->argument('ref');
        $newSubtotal = round((float) $this->argument('subtotal'), 2);
        $newTotal    = round((float) $this->argument('total'), 2);
        $reason      = (string) ($this->option('reason') ?: '');

        if ($reason === '') {
            $this->error('--reason is required — this writes a figure no rule can re-derive.');

            return self::FAILURE;
        }

        $order = Order::with('items')->where('ref', $ref)->first();

        if (! $order) {
            $this->error("Order '{$ref}' not found.");

            return self::FAILURE;
        }

        $oldSubtotal = round((float) $order->subtotal, 2);
        $oldTotal    = round((float) $order->total, 2);
        $itemsTotal  = round((float) $order->items->sum('line_total'), 2);

        $this->line('');
        $this->table(['Field', 'Now', 'After'], [
            ['Ref',      $order->ref, ''],
            ['Source',   $order->source ?? 'website', ''],
            ['Items sum', number_format($itemsTotal, 2), '(unchanged — items are not touched)'],
            ['Subtotal', number_format($oldSubtotal, 2), number_format($newSubtotal, 2)],
            ['Total',    number_format($oldTotal, 2),    number_format($newTotal, 2)],
        ]);

        if ($oldSubtotal === $newSubtotal && $oldTotal === $newTotal) {
            $this->info('Already at those figures — nothing to write.');

            return self::SUCCESS;
        }

        if ($order->isFinancialsLocked()) {
            $this->line('');
            $this->warn('This order has locked financials — a commercial document has been issued. Writing new');
            $this->warn('figures here does NOT supersede it.');
        }

        if (! $this->option('force') && ! $this->confirm('Write these figures?', false)) {
            $this->info('Nothing written.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($order, $newSubtotal, $newTotal, $oldSubtotal, $oldTotal, $reason) {
            $order->update(['subtotal' => $newSubtotal, 'total' => $newTotal]);

            OrderLog::create([
                'order_id'         => $order->id,
                'order_ref'        => $order->ref,
                'admin_user_id'    => null,
                'admin_user_email' => 'console:orders:restore-total',
                'action'           => 'totals_restored',
                'old_value'        => 'total ' . number_format($oldTotal, 2),
                'new_value'        => 'total ' . number_format($newTotal, 2),
                'notes'            => 'Subtotal ' . number_format($oldSubtotal, 2) . ' → ' . number_format($newSubtotal, 2)
                    . ', total ' . number_format($oldTotal, 2) . ' → ' . number_format($newTotal, 2)
                    . '. Reason: ' . $reason,
                'ip_address'       => null,
            ]);
        });

        $this->line('');
        $this->info("Written. {$order->ref}: total " . number_format($oldTotal, 2) . ' → ' . number_format($newTotal, 2));

        return self::SUCCESS;
    }
}
