<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\EbaySellingService;
use Illuminate\Console\Command;

class EbayDebugProduct extends Command
{
    protected $signature = 'ebay:debug-product
                            {product_id : Database ID of the product to diagnose}
                            {--publish  : Also attempt to create and publish the eBay offer}';

    protected $description = 'Step-by-step eBay listing diagnostic for a single product';

    public function __construct(private EbaySellingService $ebay)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $id      = (int) $this->argument('product_id');
        $product = Product::with('images')->find($id);

        if (! $product) {
            $this->error("Product #{$id} not found.");
            return self::FAILURE;
        }

        $withPublish = (bool) $this->option('publish');

        $this->line('');
        $this->info("=== eBay diagnostic for product #{$id} (SKU: {$product->sku}) ===");
        if ($withPublish) {
            $this->warn('  --publish flag set: will attempt to create and publish the offer.');
        }
        $this->line('');

        $report = $this->ebay->diagnoseProduct($product, $withPublish);

        // ── Product summary ──────────────────────────────────────────────────
        $this->info('--- Product ---');
        $this->table(['Field', 'Value'], [
            ['id',            $report['product']['id']],
            ['sku',           $report['product']['sku'] ?? 'N/A'],
            ['name',          mb_substr((string) ($report['product']['name'] ?? ''), 0, 60)],
            ['brand',         $report['product']['brand'] ?? 'N/A'],
            ['price',         $report['product']['price'] ?? 'N/A'],
            ['stock',         $report['product']['stock'] ?? 'N/A'],
            ['primary_image', $report['product']['image_url'] ?? 'none'],
        ]);

        // ── Config summary ───────────────────────────────────────────────────
        $this->info('--- eBay Config ---');
        $cfg = $report['config'];
        $this->table(['Key', 'Value'], [
            ['environment',           $cfg['environment']],
            ['marketplace_id',        $cfg['marketplace_id']],
            ['category_id',           $cfg['category_id']],
            ['fulfillment_policy_id', $cfg['fulfillment_policy_id'] ?? 'NOT SET'],
            ['payment_policy_id',     $cfg['payment_policy_id']     ?? 'NOT SET'],
            ['return_policy_id',      $cfg['return_policy_id']      ?? 'NOT SET'],
        ]);

        // ── Step results ─────────────────────────────────────────────────────
        $this->info('--- Diagnostic Steps ---');
        $stepRows = [];

        foreach ($report['steps'] as $name => $step) {
            $icon   = $step['status'] === 'pass' ? '✓' : '✗';
            $detail = '';

            if ($step['status'] === 'fail') {
                $detail = isset($step['error']) ? mb_substr($step['error'], 0, 120) : 'failed';
            } elseif ($name === 'token') {
                $detail = 'source: ' . ($step['source'] ?? 'unknown');
            } elseif ($name === 'inventory_put') {
                $detail = "HTTP {$step['http_status']} | title: " . mb_substr($step['request_body']['title'] ?? '', 0, 40)
                    . " | qty: {$step['request_body']['quantity']} | images: {$step['request_body']['image_count']}";
            } elseif ($name === 'inventory_get') {
                $detail = $step['sku_found'] ? 'SKU indexed on eBay' : "HTTP {$step['http_status']} — not yet indexed";
            } elseif ($name === 'offer_check') {
                $count  = $step['existing_count'];
                $ids    = implode(', ', $step['existing_ids'] ?? []);
                $detail = $count > 0 ? "existing offer(s): {$ids}" : 'no existing offer — will create new';
            } elseif ($name === 'publish') {
                $detail = $step['status'] === 'pass'
                    ? "offer_id={$step['offer_id']} listing_id={$step['listing_id']}"
                    : mb_substr($step['error'] ?? '', 0, 120);
            }

            $stepRows[] = ["{$icon} {$name}", $detail];
        }

        $this->table(['Step', 'Detail'], $stepRows);

        // ── Offer body preview (when inventory_put passed) ───────────────────
        if (isset($report['steps']['offer_check']['offer_body_will_send'])) {
            $this->info('--- Offer body preview ---');
            $offerBody = $report['steps']['offer_check']['offer_body_will_send'];
            $policies  = $offerBody['policies'];
            $this->table(['Field', 'Value'], [
                ['sku',                   $offerBody['sku']],
                ['marketplaceId',         $offerBody['marketplaceId']],
                ['format',                $offerBody['format']],
                ['categoryId',            $offerBody['categoryId']],
                ['price',                 $offerBody['price']['value'] . ' ' . $offerBody['price']['currency']],
                ['quantity',              $offerBody['quantity']],
                ['fulfillmentPolicyId',   $policies['fulfillmentPolicyId'] ?? 'NOT SET'],
                ['paymentPolicyId',       $policies['paymentPolicyId']     ?? 'NOT SET'],
                ['returnPolicyId',        $policies['returnPolicyId']      ?? 'NOT SET'],
            ]);
        }

        // ── eBay errors detail (failures only) ───────────────────────────────
        foreach ($report['steps'] as $name => $step) {
            if ($step['status'] === 'fail' && ! empty($step['ebay_errors'])) {
                $this->line('');
                $this->warn("eBay errors at step [{$name}]:");
                foreach ($step['ebay_errors'] as $err) {
                    if (isset($err['errorId'])) {
                        $this->line("  errorId {$err['errorId']}: {$err['message']}");
                    } elseif (isset($err['raw'])) {
                        $this->line("  raw: {$err['raw']}");
                    }
                }
            }
        }

        // ── Final result ─────────────────────────────────────────────────────
        $this->line('');
        $result = $report['result'] ?? 'unknown';

        if ($result === 'published') {
            $this->info("Result: PUBLISHED successfully.");
        } elseif ($result === 'ready_to_publish') {
            $this->info("Result: ready_to_publish — all pre-flight checks passed. Re-run with --publish to create the listing.");
        } else {
            $this->error("Result: {$result}");
            if (! empty($report['error'])) {
                $this->line('');
                $this->error('Error: ' . $report['error']);
            }
        }

        $this->line('');

        return in_array($result, ['published', 'ready_to_publish']) ? self::SUCCESS : self::FAILURE;
    }
}
