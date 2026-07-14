<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * POST /api/v1/admin/orders — manually recording a historical order for an
 * existing Okelcor customer being onboarded (prior shipment/order history
 * that predates the system, or wasn't otherwise imported).
 *
 * Run with:
 *   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=okelcor_cms_test \
 *   DB_USERNAME=root DB_PASSWORD= php artisan test --filter=AdminOrderCreation
 */
class AdminOrderCreationTest extends TestCase
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

    private function admin(string $role = 'admin'): AdminUser
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

    private function customer(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'customer_type'     => 'b2b',
            'first_name'        => 'Acme',
            'last_name'         => 'Buyer',
            'email'             => 'buyer' . uniqid() . '@acme-tyres.com',
            'password'          => Hash::make('secret-pass-123'),
            'country'           => 'DE',
            'company_name'      => 'Acme Tyres GmbH',
            'is_active'         => true,
            'onboarding_status' => 'active',
        ], $overrides));
    }

    public function test_admin_can_record_historical_order_with_items(): void
    {
        $customer = $this->customer();

        $response = $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->postJson('/api/v1/admin/orders', [
                'customer_id'    => $customer->id,
                'status'         => 'delivered',
                'payment_status' => 'paid',
                'carrier'        => 'GLS',
                'tracking_number' => '50044195855',
                'items' => [
                    ['sku' => 'TYRE-1', 'name' => '205/55 R16', 'brand' => 'Continental', 'unit_price' => 80, 'quantity' => 100],
                ],
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.total', 8000.0);
        $response->assertJsonPath('data.status', 'delivered');
        $response->assertJsonPath('data.carrier', 'GLS');

        $order = Order::where('customer_email', $customer->email)->firstOrFail();
        $this->assertSame('manual', $order->mode);
        $this->assertSame('admin_manual', $order->source);
        // A paid historical order defaults to the final payment-milestone stage
        // so document upload/visibility isn't blocked behind a stage that no
        // longer applies to something that already happened.
        $this->assertSame('balance_paid', $order->payment_stage);
    }

    public function test_admin_can_record_order_still_in_transit_without_items(): void
    {
        $customer = $this->customer();

        $response = $this->actingAs($this->admin('admin'), 'sanctum')
            ->postJson('/api/v1/admin/orders', [
                'customer_id'    => $customer->id,
                'status'         => 'shipped',
                'payment_status' => 'paid',
                'payment_stage'  => 'balance_paid',
                'total'          => 4200.50,
                'carrier'        => 'DHL',
                'tracking_number' => 'JD1234567890',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.total', 4200.5);
        $response->assertJsonPath('data.status', 'shipped');
    }

    public function test_new_order_is_immediately_visible_to_the_customer(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->postJson('/api/v1/admin/orders', [
                'customer_id'    => $customer->id,
                'status'         => 'delivered',
                'payment_status' => 'paid',
                'total'          => 500,
            ])
            ->assertCreated();

        $token = $customer->createToken('portal')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer_email', $customer->email);
    }

    public function test_requires_either_customer_id_or_name_and_email(): void
    {
        $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->postJson('/api/v1/admin/orders', [
                'status'         => 'delivered',
                'payment_status' => 'paid',
                'total'          => 100,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['customer_name', 'customer_email']);
    }

    public function test_role_without_orders_update_permission_is_forbidden(): void
    {
        // 'editor' — valid admin_users.role ENUM value, lacks orders.update.
        $this->actingAs($this->admin('editor'), 'sanctum')
            ->postJson('/api/v1/admin/orders', [
                'customer_name'  => 'Walk-in Buyer',
                'customer_email' => 'walkin@example.com',
                'status'         => 'delivered',
                'payment_status' => 'paid',
                'total'          => 100,
            ])
            ->assertForbidden();
    }
}
