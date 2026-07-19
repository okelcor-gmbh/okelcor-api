<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\AdminUser;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Financial revision request/approve/reject previously wrote an OrderLog
 * entry but never notified anyone — nothing prompted an approver to act,
 * and the requester never learned the outcome. This locks in the fix.
 *
 * Run with:
 *   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=okelcor_cms_test \
 *   DB_USERNAME=root DB_PASSWORD= php artisan test --filter=AdminOrderFinancialRevisionNotification
 */
class AdminOrderFinancialRevisionNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $connection = getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? 'sqlite');
        if ($connection !== 'mysql') {
            $this->markTestSkipped('These tests require a MySQL connection (legacy migrations are MySQL-only).');
        }

        parent::setUp();

        Http::fake(['exp.host/*' => Http::response(['data' => []], 200)]);
    }

    private function admin(string $role): AdminUser
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

    private function lockedOrder(): Order
    {
        $order = Order::create([
            'ref' => 'OKL-' . strtoupper(uniqid()), 'customer_name' => 'Acme Buyer', 'customer_email' => 'buyer@acme-tyres.com',
            'subtotal' => 1000, 'delivery_cost' => 0, 'total' => 1000, 'status' => 'confirmed', 'payment_status' => 'paid',
            'mode' => 'manual', 'financials_locked_at' => now(),
        ]);
        OrderItem::create(['order_id' => $order->id, 'sku' => 'TYRE-1', 'brand' => 'Continental', 'name' => '205/55 R16', 'size' => '205/55R16', 'unit_price' => 100, 'quantity' => 10, 'line_total' => 1000]);

        return $order;
    }

    public function test_requesting_a_revision_notifies_approvers(): void
    {
        $approver      = $this->admin('admin');
        $orderManager  = $this->admin('order_manager');
        $order         = $this->lockedOrder();

        $this->actingAs($orderManager, 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/financials/revision-request", [
                'reason'  => 'Price correction needed',
                'changes' => ['delivery_fee' => 25],
            ])->assertOk();

        $this->assertDatabaseHas('admin_notifications', [
            'admin_user_id' => $approver->id,
            'type'          => 'financial_revision_requested',
        ]);
    }

    public function test_approving_a_revision_notifies_the_original_requester(): void
    {
        $approver     = $this->admin('admin');
        $orderManager = $this->admin('order_manager');
        $order        = $this->lockedOrder();

        $this->actingAs($orderManager, 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/financials/revision-request", [
                'reason'  => 'Price correction needed',
                'changes' => ['delivery_fee' => 25],
            ])->assertOk();

        $this->actingAs($approver, 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/financials/approve-revision")
            ->assertOk();

        $this->assertDatabaseHas('admin_notifications', [
            'admin_user_id' => $orderManager->id,
            'type'          => 'financial_revision_approved',
        ]);
    }

    public function test_rejecting_a_revision_notifies_the_original_requester(): void
    {
        $approver     = $this->admin('admin');
        $orderManager = $this->admin('order_manager');
        $order        = $this->lockedOrder();

        $this->actingAs($orderManager, 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/financials/revision-request", [
                'reason'  => 'Price correction needed',
                'changes' => ['delivery_fee' => 25],
            ])->assertOk();

        $this->actingAs($approver, 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/financials/reject-revision", ['reason' => 'Not justified'])
            ->assertOk();

        $notification = AdminNotification::where('admin_user_id', $orderManager->id)
            ->where('type', 'financial_revision_rejected')
            ->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString('Not justified', $notification->body);
    }
}
