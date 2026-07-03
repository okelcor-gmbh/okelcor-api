<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\EbayOrderSyncService;
use App\Services\EbaySellingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * eBay's lineItemCost is documented (developer.ebay.com) as unit price x
 * quantity — i.e. the TOTAL for that line, not a per-unit price. The import
 * code used to treat it as per-unit and multiply by quantity again, doubling
 * (or worse) the displayed price for any line with quantity > 1. Confirmed
 * against a real order: 2 items at a true 75.14 EUR each (150.28 EUR line
 * total) were showing as 150.28 EUR "each" with the same line total.
 *
 * Calls the private importOrder() directly via reflection — it's a pure data
 * transformation + DB write with no outbound eBay HTTP calls of its own, so
 * this avoids needing to mock the whole OAuth/fetch chain just to test price
 * math.
 *
 * Run with:
 *   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=okelcor_cms_test \
 *   DB_USERNAME=root DB_PASSWORD= php artisan test --filter=EbayOrderPricing
 */
class EbayOrderPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $connection = getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? 'sqlite');
        if ($connection !== 'mysql') {
            $this->markTestSkipped('These tests require a MySQL connection (legacy migrations are MySQL-only).');
        }

        parent::setUp();
    }

    private function importOrder(array $eb): void
    {
        $service = app(EbayOrderSyncService::class);
        $method  = new ReflectionMethod($service, 'importOrder');
        $method->setAccessible(true);
        $method->invoke($service, $eb);
    }

    public function test_multi_quantity_line_item_price_is_divided_not_duplicated(): void
    {
        $this->importOrder([
            'orderId'               => 'EBAY-TEST-1',
            'orderPaymentStatus'    => 'PAID',
            'orderFulfillmentStatus' => 'NOT_STARTED',
            'creationDate'          => now()->toIso8601String(),
            'lastModifiedDate'      => now()->toIso8601String(),
            'buyer'                 => [
                'username' => 'buyer1',
                'buyerRegistrationAddress' => [
                    'fullName' => 'Test Buyer',
                    'contactAddress' => ['email' => 'buyer@example.com', 'city' => 'Berlin', 'countryCode' => 'DE'],
                ],
            ],
            'fulfillmentStartInstructions' => [[
                'shippingStep' => [
                    'shipTo' => [
                        'fullName' => 'Test Buyer',
                        'contactAddress' => [
                            'addressLine1' => '1 Test St', 'city' => 'Berlin',
                            'postalCode'   => '10115', 'countryCode' => 'DE',
                        ],
                    ],
                ],
            ]],
            'lineItems' => [[
                'sku'          => 'TYRE-1',
                'title'        => 'Pirelli 225/45R17',
                'quantity'     => 2,
                'lineItemCost' => ['value' => '150.28', 'currency' => 'EUR'],
            ]],
            'pricingSummary' => [
                'total'        => ['value' => '150.28', 'currency' => 'EUR'],
                'deliveryCost' => ['value' => '0.00', 'currency' => 'EUR'],
            ],
        ]);

        $order = Order::where('ebay_order_id', 'EBAY-TEST-1')->with('items')->firstOrFail();
        $item  = $order->items->first();

        $this->assertSame(75.14, (float) $item->unit_price);
        $this->assertSame(150.28, (float) $item->line_total);
        $this->assertSame(2, $item->quantity);
        $this->assertSame(150.28, (float) $order->total);
    }

    public function test_single_quantity_line_item_unaffected(): void
    {
        $this->importOrder([
            'orderId'               => 'EBAY-TEST-2',
            'orderPaymentStatus'    => 'PAID',
            'orderFulfillmentStatus' => 'NOT_STARTED',
            'buyer'                 => [
                'username' => 'buyer2',
                'buyerRegistrationAddress' => [
                    'fullName' => 'Test Buyer Two',
                    'contactAddress' => ['email' => 'buyer2@example.com', 'city' => 'Berlin', 'countryCode' => 'DE'],
                ],
            ],
            'fulfillmentStartInstructions' => [[
                'shippingStep' => [
                    'shipTo' => [
                        'fullName' => 'Test Buyer Two',
                        'contactAddress' => [
                            'addressLine1' => '2 Test St', 'city' => 'Berlin',
                            'postalCode'   => '10115', 'countryCode' => 'DE',
                        ],
                    ],
                ],
            ]],
            'lineItems' => [[
                'sku'          => 'TYRE-2',
                'title'        => 'Continental 205/55R16',
                'quantity'     => 1,
                'lineItemCost' => ['value' => '89.99', 'currency' => 'EUR'],
            ]],
            'pricingSummary' => [
                'total'        => ['value' => '89.99', 'currency' => 'EUR'],
                'deliveryCost' => ['value' => '0.00', 'currency' => 'EUR'],
            ],
        ]);

        $item = Order::where('ebay_order_id', 'EBAY-TEST-2')->firstOrFail()->items->first();

        $this->assertSame(89.99, (float) $item->unit_price);
        $this->assertSame(89.99, (float) $item->line_total);
    }

    // ── Backfill command for orders imported before the fix ─────────────────

    private function buggyOrder(): Order
    {
        $order = Order::create([
            'ref'            => 'OKL-' . strtoupper(uniqid()),
            'source'         => 'ebay',
            'ebay_order_id'  => 'EBAY-BUGGY-' . uniqid(),
            'customer_name'  => 'Buggy Buyer',
            'customer_email' => 'buggy' . uniqid() . '@example.com',
            'address'        => '1 Test St',
            'city'           => 'Berlin',
            'postal_code'    => '10115',
            'country'        => 'DE',
            'payment_method' => 'ebay',
            'subtotal'       => 150.28,
            'delivery_cost'  => 0,
            'total'          => 150.28,
            'status'         => 'confirmed',
            'payment_status' => 'paid',
            'mode'           => 'manual',
        ]);

        $order->items()->create([
            'sku'        => 'TYRE-BUGGY',
            'brand'      => '',
            'name'       => 'Buggy tyre',
            'size'       => '',
            'unit_price' => 150.28, // bug: this was the true LINE total, not per-unit
            'quantity'   => 2,
            'line_total' => 300.56, // bug: 150.28 x 2 (double-applied)
        ]);

        return $order;
    }

    public function test_audit_command_flags_without_writing_by_default(): void
    {
        $order = $this->buggyOrder();

        $this->artisan('ebay:audit-line-item-pricing')->assertSuccessful();

        $item = $order->items()->first();
        $this->assertSame(150.28, (float) $item->unit_price, 'Dry run must not modify data.');
        $this->assertSame(300.56, (float) $item->line_total);
    }

    public function test_audit_command_apply_corrects_the_item(): void
    {
        $order = $this->buggyOrder();

        $this->artisan('ebay:audit-line-item-pricing --apply')->assertSuccessful();

        $item = $order->items()->first();
        $this->assertSame(75.14, (float) $item->unit_price);
        $this->assertSame(150.28, (float) $item->line_total);
    }

    public function test_audit_command_ignores_orders_that_already_match(): void
    {
        $healthy = Order::create([
            'ref'            => 'OKL-' . strtoupper(uniqid()),
            'source'         => 'ebay',
            'ebay_order_id'  => 'EBAY-HEALTHY-' . uniqid(),
            'customer_name'  => 'Healthy Buyer',
            'customer_email' => 'healthy' . uniqid() . '@example.com',
            'address'        => '1 Test St',
            'city'           => 'Berlin',
            'postal_code'    => '10115',
            'country'        => 'DE',
            'payment_method' => 'ebay',
            'subtotal'       => 150.28,
            'delivery_cost'  => 0,
            'total'          => 150.28,
            'status'         => 'confirmed',
            'payment_status' => 'paid',
            'mode'           => 'manual',
        ]);

        $healthy->items()->create([
            'sku' => 'TYRE-OK', 'brand' => '', 'name' => 'Healthy tyre', 'size' => '',
            'unit_price' => 75.14, 'quantity' => 2, 'line_total' => 150.28,
        ]);

        $this->artisan('ebay:audit-line-item-pricing --apply')->assertSuccessful();

        $item = $healthy->items()->first();
        $this->assertSame(75.14, (float) $item->unit_price, 'Already-correct items must not be touched.');
        $this->assertSame(150.28, (float) $item->line_total);
    }
}
