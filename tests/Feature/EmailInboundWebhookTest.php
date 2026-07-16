<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerCommunication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Inbound e-mail webhook (Cloudflare Email Worker → this API). Signature
 * verification needs no DB and could run standalone, but is tested here
 * alongside the full flow for simplicity; the full processing path needs
 * real migrations (MySQL-only in this repo), same as every other
 * DB-touching integration test.
 *
 * Run with:
 *   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=okelcor_cms_test \
 *   DB_USERNAME=root DB_PASSWORD= php artisan test --filter=EmailInboundWebhook
 */
class EmailInboundWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-webhook-secret';

    protected function setUp(): void
    {
        $connection = getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? 'sqlite');
        if ($connection !== 'mysql') {
            $this->markTestSkipped('These tests require a MySQL connection (legacy migrations are MySQL-only).');
        }

        parent::setUp();

        config([
            'services.mail_inbound.enabled'        => true,
            'services.mail_inbound.webhook_secret'  => self::SECRET,
            'services.mail_inbound.address'         => 'reply@reply.okelcor.com',
            'services.mail_inbound.message_id_domain' => 'okelcor.com',
        ]);
    }

    private function signed(array $payload): array
    {
        $body = json_encode($payload);
        $sig  = hash_hmac('sha256', $body, self::SECRET);

        return [$body, $sig];
    }

    private function customer(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'customer_type' => 'b2b', 'first_name' => 'Acme', 'last_name' => 'Buyer',
            'email' => 'buyer' . uniqid() . '@acme-tyres.com', 'password' => Hash::make('secret-pass-123'),
            'country' => 'DE', 'company_name' => 'Acme Tyres', 'is_active' => true,
            'onboarding_status' => 'active',
        ], $overrides));
    }

    public function test_rejects_missing_signature(): void
    {
        $payload = ['from' => ['address' => 'buyer@acme-tyres.com'], 'subject' => 'Re: hi', 'text' => 'hi'];

        $this->postJson('/api/v1/webhooks/email-inbound', $payload)
            ->assertStatus(403);

        $this->assertDatabaseCount('customer_communications', 0);
    }

    public function test_rejects_invalid_signature(): void
    {
        $payload = ['from' => ['address' => 'buyer@acme-tyres.com'], 'subject' => 'Re: hi', 'text' => 'hi'];

        $this->postJson('/api/v1/webhooks/email-inbound', $payload, ['X-Webhook-Signature' => 'wrong'])
            ->assertStatus(403);

        $this->assertDatabaseCount('customer_communications', 0);
    }

    public function test_accepts_valid_signature_and_creates_communication_from_known_customer(): void
    {
        $customer = $this->customer();

        $payload = [
            'from'    => ['address' => $customer->email, 'name' => 'Acme Buyer'],
            'to'      => [['address' => 'reply@reply.okelcor.com']],
            'subject' => 'Re: your quote',
            'text'    => 'Thanks, sounds good!',
        ];
        [$body, $sig] = $this->signed($payload);

        $this->call('POST', '/api/v1/webhooks/email-inbound', [], [], [],
            ['HTTP_X-Webhook-Signature' => $sig, 'CONTENT_TYPE' => 'application/json'], $body)
            ->assertOk();

        $comm = CustomerCommunication::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('inbound', $comm->direction);
        $this->assertSame('email', $comm->channel);
        $this->assertStringContainsString('Thanks, sounds good!', $comm->body);
    }

    public function test_unknown_sender_creates_a_lead(): void
    {
        $payload = [
            'from'    => ['address' => 'newlead@example.com', 'name' => 'New Lead'],
            'to'      => [['address' => 'reply@reply.okelcor.com']],
            'subject' => 'Tyre inquiry',
            'text'    => 'I need 100 tyres.',
        ];
        [$body, $sig] = $this->signed($payload);

        $this->call('POST', '/api/v1/webhooks/email-inbound', [], [], [],
            ['HTTP_X-Webhook-Signature' => $sig, 'CONTENT_TYPE' => 'application/json'], $body)
            ->assertOk();

        $this->assertDatabaseHas('quote_requests', [
            'email'       => 'newlead@example.com',
            'lead_source' => 'inbound_email',
        ]);
    }

    public function test_own_domain_sender_is_ignored(): void
    {
        config(['mail.from.address' => 'noreply@okelcor.com']);

        $payload = [
            'from'    => ['address' => 'noreply@okelcor.com', 'name' => 'Okelcor'],
            'to'      => [['address' => 'reply@reply.okelcor.com']],
            'subject' => 'Order confirmation',
            'text'    => 'Your order has been received.',
        ];
        [$body, $sig] = $this->signed($payload);

        $this->call('POST', '/api/v1/webhooks/email-inbound', [], [], [],
            ['HTTP_X-Webhook-Signature' => $sig, 'CONTENT_TYPE' => 'application/json'], $body)
            ->assertOk();

        $this->assertDatabaseCount('customer_communications', 0);
        $this->assertDatabaseCount('quote_requests', 0);
    }

    public function test_html_body_is_sanitized(): void
    {
        $customer = $this->customer();

        $payload = [
            'from'    => ['address' => $customer->email],
            'to'      => [['address' => 'reply@reply.okelcor.com']],
            'subject' => 'Re: hi',
            'html'    => '<p>Hello</p><script>alert(1)</script>',
        ];
        [$body, $sig] = $this->signed($payload);

        $this->call('POST', '/api/v1/webhooks/email-inbound', [], [], [],
            ['HTTP_X-Webhook-Signature' => $sig, 'CONTENT_TYPE' => 'application/json'], $body)
            ->assertOk();

        $comm = CustomerCommunication::where('customer_id', $customer->id)->firstOrFail();
        $this->assertStringContainsString('Hello', $comm->body);
        $this->assertStringNotContainsString('script', $comm->body);
    }
}
