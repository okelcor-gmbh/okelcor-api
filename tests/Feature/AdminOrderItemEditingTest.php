<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Order-manager ask: a manually-created order's line items (price/quantity)
 * were wrong and there was no way to correct them. Deliberately excludes
 * eBay-sourced orders (authoritative from eBay, overwritten on next sync).
 *
 * Run with:
 *   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=okelcor_cms_test \
 *   DB_USERNAME=root DB_PASSWORD= php artisan test --filter=AdminOrderItemEditing
 */
class AdminOrderItemEditingTest extends TestCase
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

    private function admin(string $role = 'order_manager'): AdminUser
    {
        return AdminUser::create([
            'name' => 'Ops ' . uniqid(), 'email' => 'ops' . uniqid() . '@okelcor.test',
            'role' => $role, 'password' => Hash::make('secret-pass-123'),
            'is_active' => true, 'two_factor_confirmed_at' => now(),
        ]);
    }

    private function order(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'ref' => 'OKL-' . strtoupper(uniqid()), 'source' => 'website',
            'customer_name' => 'Acme Buyer', 'customer_email' => 'buyer' . uniqid() . '@acme-tyres.com',
            'address' => '1 Test St', 'city' => 'Berlin', 'postal_code' => '10115', 'country' => 'DE',
            'payment_method' => 'bank_transfer', 'subtotal' => 800.00, 'delivery_cost' => 0.00, 'total' => 800.00,
            'status' => 'confirmed', 'payment_status' => 'paid', 'mode' => 'manual',
        ], $overrides));
    }

    private function item(Order $order, array $overrides = []): OrderItem
    {
        return OrderItem::create(array_merge([
            'order_id' => $order->id, 'sku' => 'TYRE-1', 'brand' => 'Continental',
            'name' => '205/55 R16', 'size' => '205/55 R16', 'unit_price' => 80, 'quantity' => 10, 'line_total' => 800,
        ], $overrides));
    }

    // ── Direct edit — unlocked, non-eBay order ────────────────────────────

    public function test_admin_can_correct_item_price_and_totals_recalculate(): void
    {
        $order = $this->order();
        $item  = $this->item($order);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/v1/admin/orders/{$order->id}/items/{$item->id}", [
                'unit_price' => 75,
                'reason'     => 'Wrong price was quoted — corrected to actual agreed rate.',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.unit_price', 75.0);
        $response->assertJsonPath('data.line_total', 750.0);

        $order->refresh();
        $this->assertSame(750.0, (float) $order->subtotal);
        $this->assertSame(750.0, (float) $order->total);

        $this->assertDatabaseHas('order_logs', ['order_id' => $order->id, 'action' => 'item_corrected']);
    }

    public function test_admin_can_add_and_remove_items(): void
    {
        $order = $this->order();
        $item  = $this->item($order);
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/items", [
                'name' => 'Extra tyre', 'unit_price' => 50, 'quantity' => 2,
                'reason' => 'Client also ordered 2 extra units, missed at entry.',
            ])
            ->assertCreated();

        $this->assertSame(900.0, (float) $order->fresh()->total); // 800 + 100

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/admin/orders/{$order->id}/items/{$item->id}", [
                'reason' => 'Duplicate line entered by mistake.',
            ])
            ->assertOk();

        $this->assertSame(100.0, (float) $order->fresh()->total); // 900 - 800
    }

    public function test_cannot_delete_the_only_item(): void
    {
        $order = $this->order();
        $item  = $this->item($order);

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/v1/admin/orders/{$order->id}/items/{$item->id}", ['reason' => 'test'])
            ->assertStatus(422)
            ->assertJson(['code' => 'cannot_delete_last_item']);
    }

    // ── eBay orders — never directly editable ─────────────────────────────

    public function test_ebay_order_items_cannot_be_edited(): void
    {
        $order = $this->order(['source' => 'ebay']);
        $item  = $this->item($order);

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/v1/admin/orders/{$order->id}/items/{$item->id}", [
                'unit_price' => 1, 'reason' => 'test',
            ])
            ->assertStatus(403)
            ->assertJson(['code' => 'ebay_order_not_editable']);
    }

    // ── Locked orders — direct edit blocked, revision workflow works ──────

    public function test_locked_order_rejects_direct_edit(): void
    {
        $order = $this->order(['financials_locked_at' => now()]);
        $item  = $this->item($order);

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/v1/admin/orders/{$order->id}/items/{$item->id}", [
                'unit_price' => 1, 'reason' => 'test',
            ])
            ->assertStatus(423)
            ->assertJson(['code' => 'financials_locked']);
    }

    public function test_locked_order_item_correction_via_revision_request_and_approval(): void
    {
        $order = $this->order(['financials_locked_at' => now()]);
        $item  = $this->item($order);

        $requester = $this->admin('order_manager');
        $approver  = $this->admin('admin');

        $this->actingAs($requester, 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/financials/revision-request", [
                'reason'  => 'Client disputes the quoted unit price — confirmed correct figure with them.',
                'changes' => ['items' => [['id' => $item->id, 'unit_price' => 70]]],
            ])
            ->assertOk();

        $this->assertTrue($order->fresh()->financials_revision_required);

        $response = $this->actingAs($approver, 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/financials/approve-revision");

        $response->assertOk();

        $order->refresh();
        $this->assertFalse($order->financials_revision_required);
        $this->assertSame(700.0, (float) $order->total); // 10 * 70
        $this->assertSame(70.0, (float) $item->fresh()->unit_price);
    }

    public function test_revision_request_rejects_item_changes_for_ebay_order(): void
    {
        $order = $this->order(['source' => 'ebay', 'financials_locked_at' => now()]);
        $item  = $this->item($order);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/financials/revision-request", [
                'reason'  => 'test reason long enough',
                'changes' => ['items' => [['id' => $item->id, 'unit_price' => 1]]],
            ])
            ->assertStatus(403)
            ->assertJson(['code' => 'ebay_order_not_editable']);
    }

    public function test_role_without_orders_update_permission_is_forbidden(): void
    {
        $order = $this->order();
        $item  = $this->item($order);

        // 'editor' — valid admin_users.role ENUM value, lacks orders.update.
        $this->actingAs($this->admin('editor'), 'sanctum')
            ->patchJson("/api/v1/admin/orders/{$order->id}/items/{$item->id}", [
                'unit_price' => 1, 'reason' => 'test',
            ])
            ->assertForbidden();
    }
}
