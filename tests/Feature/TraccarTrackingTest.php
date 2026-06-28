<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\AdminTrackingController;
use App\Http\Controllers\CustomerTrackingController;
use App\Models\Customer;
use App\Models\Order;
use App\Services\TraccarService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Traccar GPS/fleet tracking integration.
 *
 * Uses Http::fake so no live Traccar server is required — asserts the service
 * shaping (incl. knots→km/h, metres→km), graceful degradation, and customer
 * scoping.
 *
 * Run with:
 *   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=okelcor_cms_test \
 *   DB_USERNAME=root DB_PASSWORD= php artisan test --filter=TraccarTracking
 */
class TraccarTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $connection = getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? 'sqlite');
        if ($connection !== 'mysql') {
            $this->markTestSkipped('Traccar tests require a MySQL connection (legacy migrations are MySQL-only).');
        }

        parent::setUp();

        config([
            'services.traccar.url'   => 'https://demo.traccar.org',
            'services.traccar.token' => 'test-token',
            'services.traccar.email' => null,
            'services.traccar.password' => null,
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
            'phone'             => '+49 30 1234567',
            'country'           => 'DE',
            'company_name'      => 'Acme Tyres GmbH',
            'is_active'         => true,
            'onboarding_status' => 'active',
        ], $overrides));
    }

    private function order(Customer $customer, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'ref'            => 'OKL-' . strtoupper(uniqid()),
            'customer_name'  => $customer->full_name,
            'customer_email' => $customer->email,
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

    private function customerRequest(Customer $customer): Request
    {
        $req = Request::create('/', 'GET');
        $req->setUserResolver(fn () => $customer);
        return $req;
    }

    private function adminRequest(array $input = []): Request
    {
        return Request::create('/', 'PUT', $input);
    }

    // ── Service shaping ──────────────────────────────────────────────────────────

    public function test_devices_with_positions_merges_and_converts_speed(): void
    {
        Http::fake([
            'https://demo.traccar.org/api/devices*'   => Http::response([
                ['id' => 7, 'name' => 'Truck 1', 'uniqueId' => 'T-001', 'status' => 'online', 'lastUpdate' => '2026-06-28T10:00:00Z'],
            ]),
            'https://demo.traccar.org/api/positions*' => Http::response([
                ['id' => 99, 'deviceId' => 7, 'latitude' => 52.52, 'longitude' => 13.4, 'speed' => 10, 'course' => 90, 'address' => 'Berlin', 'fixTime' => '2026-06-28T10:00:00Z', 'valid' => true],
            ]),
        ]);

        $result = app(TraccarService::class)->devicesWithPositions();

        $this->assertArrayNotHasKey('error', $result);
        $this->assertCount(1, $result['devices']);
        $device = $result['devices'][0];
        $this->assertSame('Truck 1', $device['name']);
        $this->assertSame(52.52, $device['position']['latitude']);
        // 10 knots × 1.852 = 18.5 km/h
        $this->assertSame(18.5, $device['position']['speed_kmh']);
    }

    public function test_trips_converts_distance_and_speed_units(): void
    {
        Http::fake([
            'https://demo.traccar.org/api/reports/trips*' => Http::response([
                ['startTime' => '2026-06-28T08:00:00Z', 'endTime' => '2026-06-28T09:00:00Z', 'distance' => 25000, 'averageSpeed' => 20, 'maxSpeed' => 35, 'duration' => 3600000, 'startAddress' => 'A', 'endAddress' => 'B'],
            ]),
        ]);

        $result = app(TraccarService::class)->trips(7, '2026-06-28T00:00:00Z', '2026-06-28T23:59:59Z');

        $this->assertCount(1, $result['trips']);
        $trip = $result['trips'][0];
        $this->assertSame(25.0, $trip['distance_km']);          // 25000 m → 25 km
        $this->assertSame(37.0, $trip['avg_speed_kmh']);        // 20 kn → 37.0
        $this->assertSame(64.8, $trip['max_speed_kmh']);        // 35 kn → 64.8
    }

    public function test_route_shapes_points(): void
    {
        Http::fake([
            'https://demo.traccar.org/api/reports/route*' => Http::response([
                ['latitude' => 52.5, 'longitude' => 13.4, 'speed' => 0, 'fixTime' => '2026-06-28T08:00:00Z', 'valid' => true],
                ['latitude' => 52.6, 'longitude' => 13.5, 'speed' => 5, 'fixTime' => '2026-06-28T08:05:00Z', 'valid' => true],
            ]),
        ]);

        $result = app(TraccarService::class)->route(7);

        $this->assertCount(2, $result['route']);
        $this->assertSame(52.5, $result['route'][0]['latitude']);
    }

    public function test_geofences_shaping(): void
    {
        Http::fake([
            'https://demo.traccar.org/api/geofences*' => Http::response([
                ['id' => 1, 'name' => 'Depot', 'description' => 'Main yard', 'area' => 'CIRCLE (52.5 13.4, 200)'],
            ]),
        ]);

        $result = app(TraccarService::class)->geofences();

        $this->assertCount(1, $result['geofences']);
        $this->assertSame('Depot', $result['geofences'][0]['name']);
        $this->assertStringStartsWith('CIRCLE', $result['geofences'][0]['area']);
    }

    public function test_not_configured_degrades_gracefully(): void
    {
        config(['services.traccar.url' => '', 'services.traccar.token' => null]);

        $svc = app(TraccarService::class);
        $this->assertFalse($svc->isConfigured());
        $this->assertArrayHasKey('error', $svc->devicesWithPositions());
        $this->assertFalse($svc->status()['connected']);
    }

    public function test_server_error_returns_503_via_admin_controller(): void
    {
        Http::fake(['https://demo.traccar.org/api/*' => Http::response('nope', 500)]);

        $resp = app(AdminTrackingController::class)->devices();

        $this->assertSame(503, $resp->getStatusCode());
    }

    // ── Customer scoping ─────────────────────────────────────────────────────────

    public function test_customer_tracking_returns_available_false_without_device(): void
    {
        $c = $this->customer();
        $order = $this->order($c); // no tracking_device_id

        $payload = app(CustomerTrackingController::class)
            ->show($this->customerRequest($c), $order->ref)
            ->getData(true);

        $this->assertFalse($payload['data']['available']);
        $this->assertSame('no_device', $payload['data']['reason']);
    }

    public function test_customer_tracking_returns_live_position_for_own_order(): void
    {
        Http::fake([
            'https://demo.traccar.org/api/devices*'        => Http::response([
                ['id' => 7, 'name' => 'Truck 1', 'uniqueId' => 'T-001', 'status' => 'online', 'lastUpdate' => '2026-06-28T10:00:00Z'],
            ]),
            'https://demo.traccar.org/api/positions*'      => Http::response([
                ['deviceId' => 7, 'latitude' => 52.52, 'longitude' => 13.4, 'speed' => 10, 'fixTime' => '2026-06-28T10:00:00Z', 'valid' => true],
            ]),
            'https://demo.traccar.org/api/reports/route*'  => Http::response([
                ['latitude' => 52.5, 'longitude' => 13.4, 'speed' => 0, 'fixTime' => '2026-06-28T09:00:00Z', 'valid' => true],
            ]),
        ]);

        $c = $this->customer();
        $order = $this->order($c, ['tracking_device_id' => '7']);

        $payload = app(CustomerTrackingController::class)
            ->show($this->customerRequest($c), $order->ref)
            ->getData(true);

        $this->assertTrue($payload['data']['available']);
        $this->assertSame('Truck 1', $payload['data']['name']);
        $this->assertSame(52.52, $payload['data']['position']['latitude']);
        $this->assertCount(1, $payload['data']['route']);
    }

    public function test_customer_cannot_track_another_customers_order(): void
    {
        $owner = $this->customer();
        $other = $this->customer();
        $order = $this->order($owner, ['tracking_device_id' => '7']);

        $this->expectException(ModelNotFoundException::class);

        app(CustomerTrackingController::class)->show($this->customerRequest($other), $order->ref);
    }

    // ── Admin device assignment ──────────────────────────────────────────────────

    public function test_admin_assigns_and_clears_tracking_device(): void
    {
        $c = $this->customer();
        $order = $this->order($c);

        app(AdminTrackingController::class)
            ->assignDevice($this->adminRequest(['tracking_device_id' => '7']), $order->id);
        $this->assertSame('7', $order->fresh()->tracking_device_id);

        app(AdminTrackingController::class)
            ->assignDevice($this->adminRequest(['tracking_device_id' => null]), $order->id);
        $this->assertNull($order->fresh()->tracking_device_id);
    }
}
