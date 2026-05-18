<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\EbaySellingService;
use Illuminate\Console\Command;

class EbayDebugProduct extends Command
{
    protected $signature = 'ebay:debug-product
                            {product_id : Database ID of the product to diagnose}
                            {--offer    : Test offer create/update (POST /offer) without publishing}
                            {--publish  : Test offer create then publish the listing}';

    protected $description = 'Step-by-step eBay listing diagnostic — prints every HTTP call, payload, and eBay response';

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

        $withOffer   = (bool) $this->option('offer');
        $withPublish = (bool) $this->option('publish');

        $this->sep('=');
        $this->info("eBay DEEP DIAGNOSTIC — product #{$id}  SKU: {$product->sku}");
        if ($withOffer)   $this->warn('  --offer   : will POST/PUT offer (leaves draft on eBay)');
        if ($withPublish) $this->warn('  --publish : will publish listing on eBay');
        $this->sep('=');

        $report = $this->ebay->diagnoseProduct($product, $withPublish, $withOffer);

        // ─────────────────────────────────────────────────────────────────────
        // A. Product validation
        // ─────────────────────────────────────────────────────────────────────
        $this->section('A. Product validation');
        $val = $report['steps']['validation'] ?? [];
        $this->statusLine('validation', $val['status'] ?? 'fail');

        if (($val['status'] ?? '') === 'pass') {
            $this->table(['Field', 'Value'], [
                ['SKU',           $val['sku']   ?? $report['product']['sku'] ?? 'N/A'],
                ['title',         $val['title'] ?? 'N/A'],
                ['price',         $report['product']['price'] ?? 'N/A'],
                ['stock',         $report['product']['stock'] ?? 'N/A'],
                ['image count',   $report['product']['image_count'] ?? 0],
                ['condition',     'NEW'],
                ['marketplace_id', $report['config']['marketplace_id']],
                ['category_id',   $report['config']['category_id']    ?? 'NOT SET'],
                ['fulfillment_id', $report['config']['fulfillment_policy_id'] ?? 'NOT SET'],
                ['payment_id',    $report['config']['payment_policy_id']      ?? 'NOT SET'],
                ['return_id',     $report['config']['return_policy_id']       ?? 'NOT SET'],
                ['seller_zip',        $report['config']['seller_postal_code']    ?? 'NOT SET'],
                ['seller_loc',        $report['config']['seller_location']],
                ['location_key',      $report['config']['merchant_location_key']  ?? 'OKELCOR-MAIN'],
                ['environment',       $report['config']['environment']],
            ]);

            if (! empty($report['product']['all_image_urls'])) {
                $this->line('  Image URLs:');
                foreach ($report['product']['all_image_urls'] as $i => $url) {
                    $this->line("    [{$i}] {$url}");
                }
            }

            if (! empty($val['aspects'])) {
                $this->line('  Aspects sent to eBay:');
                foreach ($val['aspects'] as $k => $v) {
                    $this->line("    {$k}: " . implode(', ', (array) $v));
                }
            }
        } else {
            $this->error('  ERROR: ' . ($val['error'] ?? 'unknown'));
            $this->printFinalResult($report);
            return self::FAILURE;
        }

        // ─────────────────────────────────────────────────────────────────────
        // B. Token
        // ─────────────────────────────────────────────────────────────────────
        $this->section('B. Token check');
        $tok = $report['steps']['token'] ?? [];
        $this->statusLine('token', $tok['status'] ?? 'fail');

        if (($tok['status'] ?? '') === 'pass') {
            $this->line("  Source           : {$tok['source']}");
            $this->line("  Marketplace      : {$tok['marketplace_id']}");
            $this->line("  Content-Language : " . ($tok['content_language'] ?? 'not set') . "  ← must match marketplace locale");
            $this->line('  Token value      : [REDACTED]');
        } else {
            $this->error('  ERROR: ' . ($tok['error'] ?? 'unknown'));
            $this->printFinalResult($report);
            return self::FAILURE;
        }

        // ─────────────────────────────────────────────────────────────────────
        // B2. Merchant location
        // ─────────────────────────────────────────────────────────────────────
        $this->section('B2. Merchant location (provides Item.Country for EBAY_DE)');
        $loc = $report['steps']['merchant_location'] ?? null;

        if ($loc === null) {
            $this->warn('  (merchant location step missing — redeploy and re-run)');
        } else {
            $this->statusLine('merchant_location', $loc['status']);
            $this->line("  Key      : {$loc['key']}");
            $this->line("  Endpoint : {$loc['endpoint']}");
            $this->line("  HTTP     : {$loc['http_status']}");
            $this->line("  Action   : {$loc['action']}");
            if (isset($loc['country'])) {
                $this->line("  Country  : {$loc['country']}");
            }
            $this->printRawPreview($loc['raw_preview'] ?? '');
            $this->printEbayErrors($loc['ebay_errors'] ?? []);

            if ($loc['status'] === 'fail') {
                $this->printFinalResult($report);
                return self::FAILURE;
            }
        }

        // ─────────────────────────────────────────────────────────────────────
        // C. Inventory lookup BEFORE PUT
        // ─────────────────────────────────────────────────────────────────────
        $this->section('C. Inventory GET (before PUT) — what eBay currently has');
        $pre = $report['steps']['inventory_get_before'] ?? [];
        $this->line("  Endpoint    : GET {$pre['endpoint']}");
        $this->line("  HTTP status : {$pre['http_status']}");
        $this->line('  SKU exists  : ' . ($pre['sku_exists'] ? 'YES — eBay already has this inventory item' : 'NO — item does not exist yet on eBay'));
        $this->printRawPreview($pre['raw_preview'] ?? '');
        $this->printEbayErrors($pre['ebay_errors'] ?? []);

        // ─────────────────────────────────────────────────────────────────────
        // D. Inventory PUT
        // ─────────────────────────────────────────────────────────────────────
        $this->section('D. Inventory PUT');
        $put = $report['steps']['inventory_put'] ?? [];
        $this->statusLine('inventory_put', $put['status'] ?? 'fail');
        $this->line("  Endpoint    : PUT {$put['endpoint']}");
        $this->line("  HTTP status : {$put['http_status']}");

        if (! empty($put['request_payload'])) {
            $rp = $put['request_payload'];
            $this->line('  Request payload:');
            $this->line("    condition   : {$rp['condition']}");
            $this->line("    quantity    : {$rp['quantity']}");
            $this->line("    title       : {$rp['title']}");
            $this->line("    image_count : {$rp['image_count']}");
            foreach ((array) ($rp['image_urls'] ?? []) as $i => $url) {
                $this->line("    image[{$i}]   : {$url}");
            }
            $this->line('    aspects:');
            foreach ((array) ($rp['aspects'] ?? []) as $k => $v) {
                $this->line("      {$k}: " . implode(', ', (array) $v));
            }
        }

        $this->printRawPreview($put['raw_preview'] ?? '');
        $this->printEbayErrors($put['ebay_errors'] ?? []);

        if (($put['status'] ?? '') === 'fail') {
            $this->printFinalResult($report);
            return self::FAILURE;
        }

        // ─────────────────────────────────────────────────────────────────────
        // E. Inventory GET AFTER PUT
        // ─────────────────────────────────────────────────────────────────────
        $this->section('E. Inventory GET (after PUT) — verify eBay indexed the item');
        $post = $report['steps']['inventory_get_after'] ?? [];
        $this->statusLine('inventory_get_after', $post['status'] ?? 'fail');
        $this->line("  Endpoint    : GET {$post['endpoint']}");
        $this->line("  HTTP status : {$post['http_status']}");
        $this->line('  SKU found   : ' . ($post['sku_found'] ? 'YES — eBay has indexed the item' : 'NO — item not yet indexed (error 25751 territory)'));
        $this->printRawPreview($post['raw_preview'] ?? '');
        $this->printEbayErrors($post['ebay_errors'] ?? []);

        if (($post['status'] ?? '') === 'fail') {
            $this->printFinalResult($report);
            return self::FAILURE;
        }

        // ─────────────────────────────────────────────────────────────────────
        // F. Offer GET (existing offers)
        // ─────────────────────────────────────────────────────────────────────
        $this->section('F. Offer lookup (GET existing offers for this SKU)');
        $oc = $report['steps']['offer_check'] ?? [];
        $this->line("  Endpoint      : GET {$oc['endpoint']}");
        $this->line("  HTTP status   : {$oc['http_status']}");
        $this->line("  Offer count   : {$oc['existing_count']}");

        if (! empty($oc['existing_ids'])) {
            $this->line('  Offer IDs     : ' . implode(', ', $oc['existing_ids']));
        } else {
            $this->line('  Offer IDs     : none — new offer will be created');
        }

        $this->printRawPreview($oc['raw_preview'] ?? '');

        if (! empty($oc['offer_payload_will_send'])) {
            $op = $oc['offer_payload_will_send'];
            $this->line('  Offer payload that WILL be sent:');
            $this->table(['Field', 'Value'], [
                ['sku',                 $op['sku']],
                ['marketplaceId',       $op['marketplaceId']],
                ['format',              $op['format']],
                ['categoryId',          $op['categoryId']],
                ['price',               ($op['price']['value'] ?? '') . ' ' . ($op['price']['currency'] ?? '')],
                ['quantity',            $op['quantity']],
                ['fulfillmentPolicyId', $op['fulfillmentPolicyId'] ?? 'NOT SET'],
                ['paymentPolicyId',     $op['paymentPolicyId']     ?? 'NOT SET'],
                ['returnPolicyId',      $op['returnPolicyId']      ?? 'NOT SET'],
            ]);
        }

        // ─────────────────────────────────────────────────────────────────────
        // G. Offer create/update test (--offer only)
        // ─────────────────────────────────────────────────────────────────────
        if ($withOffer || isset($report['steps']['offer_create_test'])) {
            $this->section('G. Offer create/update test (--offer)');
            $ot = $report['steps']['offer_create_test'] ?? null;

            if ($ot === null) {
                $this->warn('  --offer not passed — skipped.');
            } else {
                $this->statusLine('offer_create_test', $ot['status'] ?? 'fail');
                $this->line("  Action      : {$ot['action']}");
                $this->line("  Endpoint    : {$ot['endpoint']}");
                $this->line("  HTTP status : {$ot['http_status']}");

                if (($ot['status'] ?? '') === 'pass') {
                    $this->line("  Offer ID    : " . ($ot['offer_id'] ?? 'n/a (update — ID unchanged)'));
                }

                // Always print the full raw eBay body for the offer step — this is the critical one
                $this->line('  Raw eBay response body:');
                $rawBody = $ot['raw_body'] ?? '';
                if (empty($rawBody)) {
                    $this->line('    (empty — success with no body)');
                } else {
                    foreach (str_split($rawBody, 120) as $chunk) {
                        $this->line("    {$chunk}");
                    }
                }

                $this->printEbayErrors($ot['ebay_errors'] ?? [], true);
            }
        } else {
            $this->line('');
            $this->line('  [G — Offer create test skipped. Re-run with --offer to test POST /offer without publishing.]');
        }

        // ─────────────────────────────────────────────────────────────────────
        // H. Publish (--publish only)
        // ─────────────────────────────────────────────────────────────────────
        if ($withPublish && isset($report['steps']['publish'])) {
            $this->section('H. Publish');
            $pub = $report['steps']['publish'];
            $this->statusLine('publish', $pub['status']);

            if ($pub['status'] === 'pass') {
                $this->info("  offer_id   : {$pub['offer_id']}");
                $this->info("  listing_id : {$pub['listing_id']}");
            } else {
                $this->error('  ERROR: ' . ($pub['error'] ?? 'unknown'));
                $this->printEbayErrors($pub['ebay_errors'] ?? [], true);
            }
        }

        $this->line('');
        $this->printFinalResult($report);

        $result = $report['result'] ?? 'unknown';
        return in_array($result, ['published', 'ready_to_publish']) ? self::SUCCESS : self::FAILURE;
    }

    // ─── Output helpers ──────────────────────────────────────────────────────

    private function section(string $title): void
    {
        $this->line('');
        $this->info("── {$title}");
        $this->line(str_repeat('─', 70));
    }

    private function sep(string $char = '─'): void
    {
        $this->line(str_repeat($char, 72));
    }

    private function statusLine(string $step, string $status): void
    {
        $icon  = $status === 'pass' ? '✓ PASS' : ($status === 'info' ? '● INFO' : '✗ FAIL');
        $color = $status === 'pass' ? 'info'   : ($status === 'info' ? 'comment' : 'error');
        $this->{$color}("  [{$icon}] {$step}");
    }

    private function printRawPreview(string $raw): void
    {
        if (empty(trim($raw))) {
            $this->line('  Response    : (empty body)');
            return;
        }
        $this->line('  Response preview:');
        $preview = mb_strlen($raw) > 600 ? mb_substr($raw, 0, 600) . ' ...[truncated]' : $raw;
        foreach (explode("\n", wordwrap($preview, 110, "\n", true)) as $line) {
            $this->line("    {$line}");
        }
    }

    private function printEbayErrors(array $errors, bool $verbose = false): void
    {
        if (empty($errors)) {
            return;
        }
        $this->warn('  eBay errors:');
        foreach ($errors as $err) {
            if (isset($err['errorId'])) {
                $this->warn("    errorId  : {$err['errorId']}");
                $this->warn("    domain   : " . ($err['domain']   ?? 'n/a'));
                $this->warn("    category : " . ($err['category'] ?? 'n/a'));
                $this->warn("    message  : " . ($err['message']  ?? 'n/a'));
                if ($verbose && isset($err['longMessage'])) {
                    $this->warn("    longMsg  : {$err['longMessage']}");
                }
                $this->line('');
            } elseif (isset($err['error'])) {
                $this->warn("    error       : {$err['error']}");
                $this->warn("    description : " . ($err['description'] ?? 'n/a'));
            } elseif (isset($err['raw'])) {
                $this->warn("    raw: {$err['raw']}");
            }
        }
    }

    private function printFinalResult(array $report): void
    {
        $this->sep('=');
        $result = $report['result'] ?? 'unknown';

        if ($result === 'published') {
            $this->info("RESULT: PUBLISHED successfully on eBay.");
        } elseif ($result === 'ready_to_publish') {
            $this->info("RESULT: ready_to_publish — all pre-flight checks passed.");
            $this->line("  Next: run with --offer to test offer creation, or --publish to go live.");
        } else {
            $this->error("RESULT: {$result}");
            if (! empty($report['error'])) {
                $this->line('');
                $this->line('  Root cause:');
                foreach (str_split($report['error'], 110) as $chunk) {
                    $this->line("    {$chunk}");
                }
            }
        }
        $this->sep('=');
    }
}
