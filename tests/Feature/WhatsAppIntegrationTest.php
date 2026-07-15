<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\CustomerCommunication;
use App\Models\QuoteRequest;
use App\Services\CustomerNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * WhatsApp Business integration — inbound webhook (lead capture, existing-
 * contact logging, status updates, signature verification) and the admin
 * compose/send path.
 *
 * Run with:
 *   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=okelcor_cms_test \
 *   DB_USERNAME=root DB_PASSWORD= php artisan test --filter=WhatsAppIntegration
 */
class WhatsAppIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const APP_SECRET = 'test-app-secret';

    protected function setUp(): void
    {
        $connection = getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? 'sqlite');
        if ($connection !== 'mysql') {
            $this->markTestSkipped('These tests require a MySQL connection (legacy migrations are MySQL-only).');
        }

        parent::setUp();

        config([
            'services.whatsapp.app_secret'   => self::APP_SECRET,
            'services.whatsapp.verify_token' => 'test-verify-token',
            'services.whatsapp.phone_number_id' => '111',
            'services.whatsapp.access_token'    => 'test-token',
        ]);
    }

    private function signed(array $payload): array
    {
        $body = json_encode($payload);
        $sig  = 'sha256=' . hash_hmac('sha256', $body, self::APP_SECRET);

        return [$body, $sig];
    }

    private function admin(): AdminUser
    {
        return AdminUser::create([
            'name' => 'Ops', 'email' => 'ops' . uniqid() . '@okelcor.test', 'role' => 'order_manager',
            'password' => Hash::make('secret-pass-123'), 'is_active' => true, 'two_factor_confirmed_at' => now(),
        ]);
    }

    private function customer(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'customer_type' => 'b2b', 'first_name' => 'Acme', 'last_name' => 'Buyer',
            'email' => 'buyer' . uniqid() . '@acme-tyres.com', 'password' => Hash::make('secret-pass-123'),
            'phone' => '+233241234567', 'country' => 'GH', 'company_name' => 'Acme Tyres',
            'is_active' => true, 'onboarding_status' => 'active',
        ], $overrides));
    }

    private function inboundMessagePayload(string $from, string $text, ?string $waId = null, ?string $name = 'John Doe'): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry'  => [[
                'id'      => 'WABA_ID',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'contacts' => [['profile' => ['name' => $name], 'wa_id' => $from]],
                        'messages' => [[
                            'from'      => $from,
                            'id'        => $waId ?? ('wamid.' . uniqid()),
                            'timestamp' => (string) time(),
                            'type'      => 'text',
                            'text'      => ['body' => $text],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    // ── Webhook verification ───────────────────────────────────────────────

    public function test_verification_handshake_succeeds_with_correct_token(): void
    {
        $this->get('/api/v1/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=test-verify-token&hub_challenge=echo123')
            ->assertOk()
            ->assertSee('echo123');
    }

    public function test_verification_handshake_rejects_wrong_token(): void
    {
        $this->get('/api/v1/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=echo123')
            ->assertStatus(403);
    }

    // ── Signature verification ────────────────────────────────────────────

    public function test_rejects_payload_with_invalid_signature(): void
    {
        $payload = $this->inboundMessagePayload('233241234567', 'hi');

        $this->postJson('/api/v1/webhooks/whatsapp', $payload, ['X-Hub-Signature-256' => 'sha256=deadbeef'])
            ->assertStatus(403);

        $this->assertDatabaseCount('customer_communications', 0);
    }

    public function test_accepts_payload_with_valid_signature(): void
    {
        $payload = $this->inboundMessagePayload('233241234567', 'hi');
        [$body, $sig] = $this->signed($payload);

        $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [],
            ['HTTP_X-Hub-Signature-256' => $sig, 'CONTENT_TYPE' => 'application/json'], $body)
            ->assertOk();

        $this->assertDatabaseCount('customer_communications', 1);
    }

    // ── Inbound message → lead capture ────────────────────────────────────

    public function test_inbound_message_from_unknown_number_creates_lead_and_log_entry(): void
    {
        $payload = $this->inboundMessagePayload('233241234567', 'I need 200 tyres 205/55R16 for Accra', name: 'Kwame Boateng');
        [$body, $sig] = $this->signed($payload);

        $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [],
            ['HTTP_X-Hub-Signature-256' => $sig, 'CONTENT_TYPE' => 'application/json'], $body)
            ->assertOk();

        $this->assertDatabaseHas('quote_requests', [
            'phone'       => '233241234567',
            'lead_source' => 'whatsapp',
            'full_name'   => 'Kwame Boateng',
        ]);

        $comm = CustomerCommunication::where('phone_number', '233241234567')->firstOrFail();
        $this->assertSame('whatsapp', $comm->type);
        $this->assertSame('inbound', $comm->direction);
        $this->assertNotNull($comm->quote_request_id);
    }

    public function test_inbound_message_from_known_customer_does_not_create_duplicate_lead(): void
    {
        $customer = $this->customer(['phone' => '+233 24 123 4567']);

        $payload = $this->inboundMessagePayload('233241234567', 'Any update on my order?');
        [$body, $sig] = $this->signed($payload);

        $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [],
            ['HTTP_X-Hub-Signature-256' => $sig, 'CONTENT_TYPE' => 'application/json'], $body)
            ->assertOk();

        $this->assertDatabaseCount('quote_requests', 0);

        $comm = CustomerCommunication::where('phone_number', '233241234567')->firstOrFail();
        $this->assertSame($customer->id, $comm->customer_id);
    }

    public function test_duplicate_webhook_delivery_does_not_double_log(): void
    {
        $payload = $this->inboundMessagePayload('233241234567', 'hi', waId: 'wamid.FIXED123');
        [$body, $sig] = $this->signed($payload);

        $headers = ['HTTP_X-Hub-Signature-256' => $sig, 'CONTENT_TYPE' => 'application/json'];

        $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], $headers, $body)->assertOk();
        $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], $headers, $body)->assertOk();

        $this->assertDatabaseCount('customer_communications', 1);
    }

    // ── Status updates ──────────────────────────────────────────────────────

    public function test_status_update_marks_existing_message_delivered(): void
    {
        $customer = $this->customer();
        $comm = CustomerCommunication::create([
            'customer_id' => $customer->id, 'phone_number' => $customer->phone,
            'type' => 'whatsapp', 'direction' => 'outbound', 'channel' => 'whatsapp',
            'body' => 'Your order shipped', 'whatsapp_message_id' => 'wamid.OUT1',
            'whatsapp_status' => 'sent', 'status' => 'sent', 'completed_at' => now(),
        ]);

        $payload = [
            'entry' => [[
                'changes' => [[
                    'field' => 'messages',
                    'value' => ['statuses' => [[
                        'id' => 'wamid.OUT1', 'status' => 'delivered', 'timestamp' => (string) time(),
                        'recipient_id' => $customer->phone,
                    ]]],
                ]],
            ]],
        ];
        [$body, $sig] = $this->signed($payload);

        $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [],
            ['HTTP_X-Hub-Signature-256' => $sig, 'CONTENT_TYPE' => 'application/json'], $body)
            ->assertOk();

        $this->assertSame('delivered', $comm->fresh()->whatsapp_status);
    }

    // ── Admin compose/send ─────────────────────────────────────────────────

    public function test_admin_can_send_whatsapp_message_to_customer(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.SENT1']]], 200)]);
        $customer = $this->customer();

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/customers/{$customer->id}/communications/send-whatsapp", [
                'body' => 'Hi! Your proposal is ready for review.',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.channel', 'whatsapp');
        $response->assertJsonPath('data.whatsapp_status', 'sent');

        $this->assertDatabaseHas('customer_communications', [
            'customer_id' => $customer->id, 'type' => 'whatsapp', 'direction' => 'outbound',
        ]);
    }

    public function test_send_whatsapp_requires_customer_to_have_a_phone_number(): void
    {
        $customer = $this->customer(['phone' => null]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/customers/{$customer->id}/communications/send-whatsapp", ['body' => 'Hi'])
            ->assertStatus(422)
            ->assertJson(['code' => 'missing_recipient_phone']);
    }

    // ── Opt-in gating ──────────────────────────────────────────────────────

    public function test_customer_does_not_want_whatsapp_by_default(): void
    {
        $customer = $this->customer();
        $this->assertFalse(CustomerNotifier::wantsWhatsApp($customer));
    }

    public function test_customer_can_opt_in_to_whatsapp(): void
    {
        $customer = $this->customer(['notification_preferences' => ['whatsapp_enabled' => true]]);
        $this->assertTrue(CustomerNotifier::wantsWhatsApp($customer));
    }

    public function test_customer_without_phone_never_wants_whatsapp_even_if_opted_in(): void
    {
        $customer = $this->customer(['phone' => null, 'notification_preferences' => ['whatsapp_enabled' => true]]);
        $this->assertFalse(CustomerNotifier::wantsWhatsApp($customer));
    }
}
