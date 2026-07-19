<?php

namespace Tests\Feature;

use App\Models\AdminPushToken;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\CustomerCommunication;
use App\Models\LiveChatSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Live chat (see FRONTEND_NOTE_admin-mobile-app-v2-premium.md, Pillar 1) —
 * session lifecycle, message scoping, transcript roll-up on close.
 * BROADCAST_CONNECTION=null in the test environment, so broadcast() calls
 * silently no-op — no Pusher faking needed to exercise the actual logic.
 *
 * Run with:
 *   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=okelcor_cms_test \
 *   DB_USERNAME=root DB_PASSWORD= php artisan test --filter=LiveChatTest
 */
class LiveChatTest extends TestCase
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

    private function admin(bool $available = true): AdminUser
    {
        return AdminUser::create([
            'name' => 'Ops ' . uniqid(), 'email' => 'ops' . uniqid() . '@okelcor.test', 'role' => 'admin',
            'password' => Hash::make('secret-pass-123'), 'is_active' => true, 'two_factor_confirmed_at' => now(),
            'available_for_chat' => $available,
        ]);
    }

    private function customer(): Customer
    {
        return Customer::create([
            'customer_type' => 'b2b', 'first_name' => 'Acme', 'last_name' => 'Buyer',
            'email' => 'buyer' . uniqid() . '@acme-tyres.com', 'password' => Hash::make('secret-pass-123'),
            'country' => 'DE', 'company_name' => 'Acme Tyres', 'is_active' => true, 'onboarding_status' => 'active',
        ]);
    }

    public function test_customer_starting_a_session_reuses_an_existing_pending_one(): void
    {
        $customer = $this->customer();

        $first  = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/auth/chat/sessions')->assertCreated();
        $second = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/auth/chat/sessions')->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('live_chat_sessions', 1);
    }

    public function test_starting_a_session_pushes_only_available_admins(): void
    {
        $available   = $this->admin(available: true);
        $unavailable = $this->admin(available: false);
        AdminPushToken::create(['admin_id' => $available->id, 'token' => 'tok-available', 'platform' => 'ios', 'last_seen_at' => now()]);
        AdminPushToken::create(['admin_id' => $unavailable->id, 'token' => 'tok-unavailable', 'platform' => 'ios', 'last_seen_at' => now()]);

        $this->actingAs($this->customer(), 'sanctum')->postJson('/api/v1/auth/chat/sessions')->assertCreated();

        Http::assertSent(fn ($request) => $request[0]['to'] === 'tok-available');
        Http::assertNotSent(fn ($request) => ($request[0]['to'] ?? null) === 'tok-unavailable');
    }

    public function test_admin_can_accept_a_pending_session_and_a_second_admin_cannot(): void
    {
        $customer = $this->customer();
        $session  = LiveChatSession::create(['customer_id' => $customer->id, 'status' => 'pending']);

        $firstAdmin  = $this->admin();
        $secondAdmin = $this->admin();

        $this->actingAs($firstAdmin, 'sanctum')
            ->postJson("/api/v1/admin/chat-sessions/{$session->id}/accept")
            ->assertOk();

        $this->assertSame('active', $session->fresh()->status);
        $this->assertSame($firstAdmin->id, $session->fresh()->admin_id);

        $this->actingAs($secondAdmin, 'sanctum')
            ->postJson("/api/v1/admin/chat-sessions/{$session->id}/accept")
            ->assertStatus(409)
            ->assertJsonPath('code', 'already_claimed');
    }

    public function test_messages_flow_both_ways_and_are_scoped_correctly(): void
    {
        $customer = $this->customer();
        $admin    = $this->admin();
        $session  = LiveChatSession::create(['customer_id' => $customer->id, 'admin_id' => $admin->id, 'status' => 'active']);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/auth/chat/sessions/{$session->id}/messages", ['body' => 'Hi, quick question about my order'])
            ->assertCreated();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/chat-sessions/{$session->id}/messages", ['body' => 'Sure, go ahead'])
            ->assertCreated();

        $this->assertDatabaseCount('live_chat_messages', 2);

        // Another customer cannot message into someone else's session.
        $otherCustomer = $this->customer();
        $this->actingAs($otherCustomer, 'sanctum')
            ->postJson("/api/v1/auth/chat/sessions/{$session->id}/messages", ['body' => 'nope'])
            ->assertStatus(404);

        // Another admin (not assigned) cannot message into this session either.
        $otherAdmin = $this->admin();
        $this->actingAs($otherAdmin, 'sanctum')
            ->postJson("/api/v1/admin/chat-sessions/{$session->id}/messages", ['body' => 'nope'])
            ->assertStatus(404);
    }

    public function test_closing_a_session_rolls_up_messages_into_a_customer_communication(): void
    {
        $customer = $this->customer();
        $admin    = $this->admin();
        $session  = LiveChatSession::create(['customer_id' => $customer->id, 'admin_id' => $admin->id, 'status' => 'active']);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/auth/chat/sessions/{$session->id}/messages", ['body' => 'Do you have stock of 205/55R16?'])
            ->assertCreated();
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/chat-sessions/{$session->id}/messages", ['body' => 'Yes, 40 units available.'])
            ->assertCreated();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/chat-sessions/{$session->id}/close")
            ->assertOk();

        $session->refresh();
        $this->assertSame('closed', $session->status);
        $this->assertNotNull($session->communication_id);

        $comm = CustomerCommunication::find($session->communication_id);
        $this->assertSame('live_chat', $comm->channel);
        $this->assertSame($customer->id, $comm->customer_id);
        $this->assertStringContainsString('Do you have stock of 205/55R16?', $comm->body);
        $this->assertStringContainsString('Yes, 40 units available.', $comm->body);
    }

    public function test_closing_a_session_with_no_messages_does_not_create_a_communication(): void
    {
        $customer = $this->customer();
        $admin    = $this->admin();
        $session  = LiveChatSession::create(['customer_id' => $customer->id, 'admin_id' => $admin->id, 'status' => 'active']);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/chat-sessions/{$session->id}/close")
            ->assertOk();

        $this->assertNull($session->fresh()->communication_id);
        $this->assertDatabaseCount('customer_communications', 0);
    }
}
