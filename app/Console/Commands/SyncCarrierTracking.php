<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\CarrierTrackingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the carrier-tracking timeline (order_shipment_events) fresh for
 * in-transit orders so the customer/admin views never need to call a
 * carrier API live on page load. Runs hourly.
 *
 * Covers orders using a third-party carrier (GLS / DHL / ocean freight) with
 * a tracking/container number set.
 */
class SyncCarrierTracking extends Command
{
    protected $signature = 'tracking:sync-carriers {--dry-run : Report counts without syncing}';

    protected $description = 'Sync GLS / DHL / ocean-freight tracking events for shipped orders';

    public function handle(CarrierTrackingService $carrierTracking): int
    {
        $orders = Order::where('status', 'shipped')
            ->where(function ($q) {
                $q->whereNotNull('tracking_number')->orWhereNotNull('container_number');
            })
            ->whereNotNull('carrier')
            ->get();

        if ($this->option('dry-run')) {
            $this->info("[dry-run] {$orders->count()} shipped order(s) with a carrier assigned would be synced.");
            return self::SUCCESS;
        }

        $synced = 0;
        $failed = 0;

        foreach ($orders as $order) {
            $result = $carrierTracking->trackAndSync($order);

            if (isset($result['error'])) {
                $failed++;
                Log::info('Carrier tracking sync skipped/failed', [
                    'order_ref' => $order->ref,
                    'carrier'   => $order->carrier,
                    'error'     => $result['error'],
                ]);
                continue;
            }

            $synced++;
        }

        $this->info("Carrier tracking synced: {$synced} order(s), {$failed} skipped/failed (of {$orders->count()}).");

        return self::SUCCESS;
    }
}
