<?php

namespace Tests\Feature;

use App\Http\Controllers\CustomerTrackingController;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderShipmentEvent;
use App\Services\CarrierTrackingService;
use App\Services\GlsTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Real carrier tracking (GLS / DHL / ocean freight incl. Maersk via ShipsGo) —
 * routing, event persistence/dedupe, admin permission gating, and the
 * customer-facing `mode` discriminator (gps_live vs carrier).
 *
 * Run with:
 *   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=okelcor_cms_test \
 *   DB_USERNAME=root DB_PASSWORD= php artisan test --filter=CarrierTracking
 */
class CarrierTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $connection = getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? 'sqlite');
        if ($connection !== 'mysql') {
            $this->markTestSkipped('These tests require a MySQL connection (legacy migrations are MySQL-only).');
        }

        parent::setUp();

        // Each test's GLS token-exchange fake is independent — don't let a
        // cached token from a previous test skip the exchange in this one.
        Cache::flush();
    }

    private function customer(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'customer_type'     => 'b2b',
            'first_name'        => 'Acme',
            'last_name'         => 'Buyer',
            'email'             => 'buyer' . uniqid() . '@acme-tyres.com',
            'password'          => Hash::make('secret-pass-123'),
            'phone'             => '+49 30 1234567',
            'country'           => 'DE',
            'company_name'      => 'Acme Tyres GmbH',
            'is_active'         => true,
            'onboarding_status' => 'active',
        ], $overrides));
    }

    private function order(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'ref'            => 'OKL-' . strtoupper(uniqid()),
            'customer_name'  => 'Acme Buyer',
            'customer_email' => 'buyer' . uniqid() . '@acme-tyres.com',
            'address'        => '1 Test St',
            'city'           => 'Berlin',
            'postal_code'    => '10115',
            'country'        => 'DE',
            'payment_method' => 'bank_transfer',
            'subtotal'       => 100.00,
            'delivery_cost'  => 0.00,
            'total'          => 100.00,
            'status'         => 'shipped',
            'payment_status' => 'paid',
            'mode'           => 'manual',
        ], $overrides));
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

    private function customerRequest(Order $order): Request
    {
        $req = Request::create('/', 'GET');
        $req->setUserResolver(fn () => (object) ['email' => $order->customer_email]);
        return $req;
    }

    /** Wires all GLS config keys + fakes the token-exchange + tracking calls. */
    private function configureGls(array $trackingResponse): void
    {
        config([
            'services.gls.app_id'            => 'test-app',
            'services.gls.api_key'           => 'test-key',
            'services.gls.api_secret'        => 'test-secret',
            'services.gls.token_endpoint'    => 'https://api.gls-group.net/oauth2/v2/token',
            'services.gls.tracking_endpoint' => 'https://api.gls-group.net/shipit-farm/v1/backend/rs/tracking/parceldetails',
        ]);

        Http::fake([
            'api.gls-group.net/oauth2/*'                   => Http::response(['access_token' => 'fake-token']),
            'api.gls-group.net/shipit-farm/*/tracking/*'   => Http::response($trackingResponse),
        ]);
    }

    // ── Carrier routing + normalization ──────────────────────────────────────

    public function test_gls_carrier_routes_to_gls_service_and_persists_events(): void
    {
        $this->configureGls([
            'UnitDetail' => [
                'Status'  => 'Delivered',
                'History' => [
                    ['Timestamp' => '2026-07-01T10:40:00Z', 'Description' => 'The package has arrived at the parcel center.', 'Location' => 'Bornheim, 53332'],
                    ['Timestamp' => '2026-06-29T19:18:00Z', 'Description' => 'The sender has made the package available for collection by GLS.', 'Location' => 'Bornheim, 53332'],
                ],
            ],
        ]);

        $order = $this->order(['carrier' => 'GLS Germany', 'tracking_number' => '50044195855']);

        $result = app(CarrierTrackingService::class)->trackAndSync($order);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('GLS Germany', $result['carrier']);
        $this->assertCount(2, $result['events']);
        $this->assertSame(2, OrderShipmentEvent::where('order_id', $order->id)->count());
        $this->assertNotNull($order->fresh()->tracking_status);
    }

    public function test_repeat_sync_does_not_duplicate_events(): void
    {
        $this->configureGls([
            'UnitDetail' => [
                'Status'  => 'Delivered',
                'History' => [
                    ['Timestamp' => '2026-07-01T10:40:00Z', 'Description' => 'Delivered.', 'Location' => 'Bornheim, 53332'],
                ],
            ],
        ]);

        $order = $this->order(['carrier' => 'GLS', 'tracking_number' => '50044195855']);

        app(CarrierTrackingService::class)->trackAndSync($order);
        app(CarrierTrackingService::class)->trackAndSync($order);

        $this->assertSame(1, OrderShipmentEvent::where('order_id', $order->id)->count());
    }

    public function test_dhl_carrier_routes_to_dhl_service(): void
    {
        Http::fake([
            'api-eu.dhl.com/*' => Http::response([
                'shipments' => [[
                    'status' => ['description' => 'Delivered', 'location' => ['address' => ['addressLocality' => 'Berlin']]],
                    'events' => [
                        ['timestamp' => '2026-07-01T09:00:00Z', 'description' => 'Delivered', 'location' => ['address' => ['addressLocality' => 'Berlin']]],
                    ],
                ]],
            ]),
        ]);

        $order = $this->order(['carrier' => 'DHL', 'carrier_type' => 'dhl', 'tracking_number' => '1Z999AA10123456784']);

        $result = app(CarrierTrackingService::class)->trackAndSync($order);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('DHL', $result['carrier']);
        $this->assertCount(1, $result['events']);
    }

    public function test_sea_freight_routes_to_shipsgo_by_container_number(): void
    {
        Http::fake([
            'api.shipsgo.com/*' => Http::response([
                'status'   => 'In Transit',
                'events'   => [
                    ['date' => '2026-06-20T00:00:00Z', 'description' => 'Departed origin port', 'location' => 'Shanghai'],
                ],
            ]),
        ]);

        $order = $this->order(['carrier' => 'Maersk Line', 'carrier_type' => 'sea', 'container_number' => 'MAEU1234567']);

        $result = app(CarrierTrackingService::class)->trackAndSync($order);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('Maersk Line', $result['carrier']);
        $this->assertSame('MAEU1234567', $result['tracking_number']);
        $this->assertCount(1, $result['events']);
    }

    public function test_gls_not_configured_degrades_cleanly(): void
    {
        config([
            'services.gls.app_id'         => null,
            'services.gls.api_key'        => null,
            'services.gls.api_secret'     => null,
            'services.gls.token_endpoint' => null,
        ]);

        $this->assertFalse(app(GlsTrackingService::class)->isConfigured());
        $this->assertArrayHasKey('error', app(GlsTrackingService::class)->track('123'));

        $order  = $this->order(['carrier' => 'GLS', 'tracking_number' => '50044195855']);
        $result = app(CarrierTrackingService::class)->trackAndSync($order);

        $this->assertArrayHasKey('error', $result);
    }

    public function test_no_trackable_carrier_returns_error(): void
    {
        $order  = $this->order(['carrier' => 'Local Courier', 'tracking_number' => 'ABC123']);
        $result = app(CarrierTrackingService::class)->trackAndSync($order);

        $this->assertArrayHasKey('error', $result);
    }

    // ── Admin endpoint ────────────────────────────────────────────────────────

    public function test_admin_with_permission_can_fetch_shipment_tracking(): void
    {
        $this->configureGls(['UnitDetail' => ['Status' => 'Delivered', 'History' => []]]);

        $order = $this->order(['carrier' => 'GLS', 'tracking_number' => '50044195855']);

        $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->getJson("/api/v1/admin/orders/{$order->id}/shipment-tracking")
            ->assertOk();
    }

    public function test_admin_without_permission_is_forbidden(): void
    {
        $order = $this->order(['carrier' => 'GLS', 'tracking_number' => '50044195855']);

        $this->actingAs($this->admin('support'), 'sanctum')
            ->getJson("/api/v1/admin/orders/{$order->id}/shipment-tracking")
            ->assertForbidden();
    }

    // ── Customer endpoint mode discriminator ─────────────────────────────────

    public function test_customer_tracking_mode_carrier_when_no_device(): void
    {
        $order = $this->order(['carrier' => 'GLS', 'tracking_number' => '50044195855']);

        OrderShipmentEvent::create([
            'order_id'     => $order->id,
            'order_ref'    => $order->ref,
            'event_date'   => '2026-07-01',
            'status_label' => 'Delivered',
            'location'     => 'Bornheim',
        ]);

        $payload = app(CustomerTrackingController::class)
            ->show($this->customerRequest($order), $order->ref)
            ->getData(true);

        $this->assertTrue($payload['data']['available']);
        $this->assertSame('carrier', $payload['data']['mode']);
        $this->assertSame('GLS', $payload['data']['carrier']);
        $this->assertCount(1, $payload['data']['events']);
    }

    public function test_customer_tracking_mode_unaffected_without_carrier_or_device(): void
    {
        $order = $this->order(); // no device, no carrier

        $payload = app(CustomerTrackingController::class)
            ->show($this->customerRequest($order), $order->ref)
            ->getData(true);

        $this->assertFalse($payload['data']['available']);
        $this->assertSame('no_device', $payload['data']['reason']);
    }
}
