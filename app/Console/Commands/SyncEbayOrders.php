<?php

namespace App\Console\Commands;

use App\Models\EbayToken;
use App\Services\EbayOrderSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncEbayOrders extends Command
{
    protected $signature   = 'ebay:sync-orders {--days=30 : Number of days back to sync}';
    protected $description = 'Sync recent eBay orders from the Sell Fulfillment API';

    public function handle(EbayOrderSyncService $syncService): int
    {
        if (! $this->isEbayConnected()) {
            $this->line('eBay not connected — skipping order sync.');
            return self::SUCCESS;
        }

        $days = (int) $this->option('days');
        $this->line("Syncing eBay orders (last {$days} days)...");

        try {
            $stats = $syncService->syncRecent($days);
        } catch (\Throwable $e) {
            Log::error('ebay:sync-orders command failed', ['error' => $e->getMessage()]);
            $this->error("Sync failed: {$e->getMessage()}");
            return self::FAILURE;
        }

        $this->line(sprintf(
            'Done — imported: %d, updated: %d, skipped: %d, failed: %d',
            $stats['imported'],
            $stats['updated'],
            $stats['skipped'],
            $stats['failed'],
        ));

        if (! empty($stats['errors'])) {
            $this->warn('Errors: ' . implode(', ', $stats['errors']));
        }

        return self::SUCCESS;
    }

    private function isEbayConnected(): bool
    {
        try {
            return EbayToken::query()
                ->whereNotNull('access_token')
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }
}
