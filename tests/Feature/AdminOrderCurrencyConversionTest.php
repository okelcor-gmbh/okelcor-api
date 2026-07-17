<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Currency change on PATCH /admin/orders/{id}/status is a genuine
 * conversion at today's exchange rate (not a display relabel) — see
 * AdminOrderController::convertOrderCurrency.
 *
 * Run with:
 *   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=okelcor_cms_test \
 *   DB_USERNAME=root DB_PASSWORD= php artisan test --filter=AdminOrderCurrencyConversion
 */
class AdminOrderCurrencyConversionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $connection = getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? 'sqlite');
        if ($connection !== 'mysql') {
            $this->markTestSkipped('These tests require a MySQL connection (legacy migrations are MySQL-only).');
        }

        parent::setUp();

        Http::fake([
            'api.frankfurter.app/*' => Http::response(['date' => '2026-07-17', 'rates' => ['USD' => 1.10]], 200),
        ]);
    }

    private function admin(string $role = 'order_manager'): AdminUser
    {
        return AdminUser::create([
            'name'                    => 'Ops ' . uniqid(),
            'email'                   => 'ops' . uniqid() . '@okelcor.test',
            'role'                    => $role,
            'password'                => Hash::make('secret-pass-123'),
            'is_active'               => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function order(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'ref'            => 'OKL-' . strtoupper(uniqid()),
            'customer_name'  => 'Acme Buyer',
            'customer_email' => 'buyer@acme-tyres.com',
            'subtotal'       => 1000.00,
            'delivery_cost'  => 50.00,
            'total'          => 1050.00,
            'status'         => 'confirmed',
            'payment_status' => 'paid',
            'mode'           => 'manual',
        ], $overrides));
    }

    public function test_converts_order_totals_and_line_items_to_new_currency(): void
    {
        $order = $this->order();
        OrderItem::create([
            'order_id' => $order->id, 'sku' => 'TYRE-1', 'brand' => 'Continental',
            'name' => '205/55 R16', 'size' => '205/55R16', 'unit_price' => 100, 'quantity' => 10, 'line_total' => 1000,
        ]);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/v1/admin/orders/{$order->id}/status", [
                'status'   => $order->status,
                'currency' => 'USD',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.currency', 'USD');

        $order->refresh();
        $this->assertSame('USD', $order->currency);
        $this->assertEquals(1155.00, (float) $order->total);
        $this->assertEquals(1100.00, (float) $order->subtotal);

        $item = OrderItem::where('order_id', $order->id)->first();
        $this->assertEquals(110.00, (float) $item->unit_price);
        $this->assertEquals(1100.00, (float) $item->line_total);

        $this->assertDatabaseHas('order_logs', [
            'order_id' => $order->id,
            'action'   => 'currency_converted',
            'old_value' => 'EUR',
            'new_value' => 'USD',
        ]);
    }

    public function test_no_conversion_when_currency_unchanged(): void
    {
        $order = $this->order(['currency' => 'EUR']);

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/v1/admin/orders/{$order->id}/status", [
                'status'   => $order->status,
                'currency' => 'EUR',
            ])->assertOk();

        $order->refresh();
        $this->assertEquals(1050.00, (float) $order->total);
        $this->assertDatabaseMissing('order_logs', ['order_id' => $order->id, 'action' => 'currency_converted']);
    }

    public function test_blocks_conversion_when_financials_locked(): void
    {
        $order = $this->order(['financials_locked_at' => now()]);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/v1/admin/orders/{$order->id}/status", [
                'status'   => $order->status,
                'currency' => 'USD',
            ]);

        $response->assertStatus(423);
        $response->assertJsonPath('code', 'financials_locked');

        $order->refresh();
        $this->assertSame('EUR', $order->currency ?? 'EUR');
        $this->assertEquals(1050.00, (float) $order->total);
    }

    public function test_blocks_conversion_on_ebay_orders(): void
    {
        $order = $this->order(['source' => 'ebay']);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/v1/admin/orders/{$order->id}/status", [
                'status'   => $order->status,
                'currency' => 'USD',
            ]);

        $response->assertStatus(403);
        $response->assertJsonPath('code', 'ebay_order_not_editable');
    }

    public function test_rejects_whole_update_when_exchange_rate_lookup_fails(): void
    {
        Http::fake(['api.frankfurter.app/*' => Http::response('Server Error', 500)]);

        $order = $this->order(['status' => 'confirmed']);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/v1/admin/orders/{$order->id}/status", [
                'status'   => 'processing',
                'currency' => 'USD',
            ]);

        $response->assertStatus(502);

        $order->refresh();
        $this->assertSame('confirmed', $order->status);
        $this->assertEquals(1050.00, (float) $order->total);
    }
}
