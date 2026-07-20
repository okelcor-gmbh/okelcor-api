<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * POST /webhooks/crisp — signature verification + push-on-visitor-message.
 *
 * Run with:
 *   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=okelcor_cms_test \
 *   DB_USERNAME=root DB_PASSWORD= php artisan test --filter=CrispWebhookTest
 */
class CrispWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-crisp-webhook-secret';

    protected function setUp(): void
    {
        $connection = getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? 'sqlite');
        if ($connection !== 'mysql') {
            $this->markTestSkipped('These tests require a MySQL connection (legacy migrations are MySQL-only).');
        }

        parent::setUp();
        config(['services.crisp.webhook_secret' => self::SECRET]);
        Http::fake(['exp.host/*' => Http::response(['data' => []], 200)]);
    }

    private function admin(): AdminUser
    {
        return AdminUser::create([
            'name' => 'Ops ' . uniqid(), 'email' => 'ops' . uniqid() . '@okelcor.test', 'role' => 'admin',
            'password' => Hash::make('secret-pass-123'), 'is_active' => true, 'two_factor_confirmed_at' => now(),
        ]);
    }

    private function signed(array $payload): array
    {
        $body = json_encode($payload);
        return [$body, hash_hmac('sha256', $body, self::SECRET)];
    }

    public function test_rejects_missing_signature(): void
    {
        $this->postJson('/api/v1/webhooks/crisp', ['event' => 'message:send'])->assertStatus(403);
    }

    public function test_rejects_invalid_signature(): void
    {
        $this->call('POST', '/api/v1/webhooks/crisp', [], [], [],
            ['HTTP_X-Crisp-Signature' => 'wrong', 'CONTENT_TYPE' => 'application/json'], json_encode(['event' => 'message:send']))
            ->assertStatus(403);
    }

    public function test_visitor_message_notifies_admins_with_crm_view(): void
    {
        $admin = $this->admin();

        [$body, $sig] = $this->signed([
            'event' => 'message:send',
            'data'  => ['session_id' => 'session_abc123', 'from' => 'user', 'content' => 'Do you ship to Poland?'],
        ]);

        $this->call('POST', '/api/v1/webhooks/crisp', [], [], [],
            ['HTTP_X-Crisp-Signature' => $sig, 'CONTENT_TYPE' => 'application/json'], $body)
            ->assertOk();

        $this->assertDatabaseHas('admin_notifications', [
            'admin_user_id' => $admin->id,
            'type'          => 'crisp_message_received',
        ]);
    }

    public function test_operator_message_does_not_notify(): void
    {
        $this->admin();

        [$body, $sig] = $this->signed([
            'event' => 'message:send',
            'data'  => ['session_id' => 'session_abc123', 'from' => 'operator', 'content' => 'Sure, we do!'],
        ]);

        $this->call('POST', '/api/v1/webhooks/crisp', [], [], [],
            ['HTTP_X-Crisp-Signature' => $sig, 'CONTENT_TYPE' => 'application/json'], $body)
            ->assertOk();

        $this->assertDatabaseCount('admin_notifications', 0);
    }

    public function test_unrelated_event_type_is_ignored(): void
    {
        $this->admin();

        [$body, $sig] = $this->signed(['event' => 'session:update_availability', 'data' => []]);

        $this->call('POST', '/api/v1/webhooks/crisp', [], [], [],
            ['HTTP_X-Crisp-Signature' => $sig, 'CONTENT_TYPE' => 'application/json'], $body)
            ->assertOk();

        $this->assertDatabaseCount('admin_notifications', 0);
    }
}
