<?php

namespace Tests\Feature;

use App\Models\AdminPushToken;
use App\Models\AdminUser;
use App\Services\AdminNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Push-token registration (AdminPushTokenController) + the
 * AdminNotificationService -> ExpoPushService hook — every in-app
 * notification also reaches a registered device.
 *
 * Run with:
 *   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=okelcor_cms_test \
 *   DB_USERNAME=root DB_PASSWORD= php artisan test --filter=AdminPushNotification
 */
class AdminPushNotificationTest extends TestCase
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

    private function admin(): AdminUser
    {
        return AdminUser::create([
            'name'                    => 'Ops ' . uniqid(),
            'email'                   => 'ops' . uniqid() . '@okelcor.test',
            'role'                    => 'admin',
            'password'                => Hash::make('secret-pass-123'),
            'is_active'               => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    public function test_registers_a_push_token(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/push-tokens', ['token' => 'ExponentPushToken[abc123]', 'platform' => 'ios'])
            ->assertCreated();

        $this->assertDatabaseHas('admin_push_tokens', [
            'admin_id' => $admin->id,
            'token'    => 'ExponentPushToken[abc123]',
            'platform' => 'ios',
        ]);
    }

    public function test_registering_an_existing_token_repoints_it_to_the_new_admin(): void
    {
        $firstAdmin  = $this->admin();
        $secondAdmin = $this->admin();

        $this->actingAs($firstAdmin, 'sanctum')
            ->postJson('/api/v1/admin/push-tokens', ['token' => 'ExponentPushToken[shared]', 'platform' => 'android'])
            ->assertCreated();

        $this->actingAs($secondAdmin, 'sanctum')
            ->postJson('/api/v1/admin/push-tokens', ['token' => 'ExponentPushToken[shared]', 'platform' => 'android'])
            ->assertCreated();

        $this->assertDatabaseCount('admin_push_tokens', 1);
        $this->assertDatabaseHas('admin_push_tokens', ['token' => 'ExponentPushToken[shared]', 'admin_id' => $secondAdmin->id]);
    }

    public function test_unregisters_own_token_only(): void
    {
        $owner  = $this->admin();
        $other  = $this->admin();

        AdminPushToken::create(['admin_id' => $owner->id, 'token' => 'tok-owner', 'platform' => 'ios', 'last_seen_at' => now()]);

        $this->actingAs($other, 'sanctum')
            ->deleteJson('/api/v1/admin/push-tokens', ['token' => 'tok-owner'])
            ->assertOk();
        $this->assertDatabaseHas('admin_push_tokens', ['token' => 'tok-owner']); // untouched — wrong owner

        $this->actingAs($owner, 'sanctum')
            ->deleteJson('/api/v1/admin/push-tokens', ['token' => 'tok-owner'])
            ->assertOk();
        $this->assertDatabaseMissing('admin_push_tokens', ['token' => 'tok-owner']);
    }

    public function test_creating_a_notification_sends_a_push_to_the_admins_registered_device(): void
    {
        $admin = $this->admin();
        AdminPushToken::create(['admin_id' => $admin->id, 'token' => 'ExponentPushToken[xyz]', 'platform' => 'ios', 'last_seen_at' => now()]);

        Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok']]], 200)]);

        AdminNotificationService::notifyUser(
            adminUserId: $admin->id,
            type: 'test_event',
            title: 'Something happened',
            body: 'Details here.',
        );

        Http::assertSent(fn ($request) => str_contains($request->url(), 'exp.host')
            && $request[0]['to'] === 'ExponentPushToken[xyz]'
            && $request[0]['title'] === 'Something happened');
    }

    public function test_notification_with_a_mapped_type_carries_category_and_related_data(): void
    {
        $admin = $this->admin();
        AdminPushToken::create(['admin_id' => $admin->id, 'token' => 'ExponentPushToken[xyz]', 'platform' => 'ios', 'last_seen_at' => now()]);

        Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok']]], 200)]);

        AdminNotificationService::notifyUser(
            adminUserId: $admin->id,
            type: 'financial_revision_requested',
            title: 'Financial revision requested',
            body: 'Order OKL-123: price correction.',
            relatedType: 'order',
            relatedId: 184,
        );

        Http::assertSent(fn ($request) => $request[0]['categoryId'] === 'financial_revision_request'
            && $request[0]['data']['related_type'] === 'order'
            && $request[0]['data']['related_id'] === 184
            && $request[0]['data']['type'] === 'financial_revision_requested');
    }

    public function test_notification_with_an_unmapped_type_omits_category(): void
    {
        $admin = $this->admin();
        AdminPushToken::create(['admin_id' => $admin->id, 'token' => 'ExponentPushToken[xyz]', 'platform' => 'ios', 'last_seen_at' => now()]);

        Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok']]], 200)]);

        AdminNotificationService::notifyUser(adminUserId: $admin->id, type: 'some_unmapped_type', title: 'Hi');

        Http::assertSent(fn ($request) => ! array_key_exists('categoryId', $request[0]));
    }

    public function test_dead_token_is_pruned_after_device_not_registered_response(): void
    {
        $admin = $this->admin();
        AdminPushToken::create(['admin_id' => $admin->id, 'token' => 'ExponentPushToken[dead]', 'platform' => 'ios', 'last_seen_at' => now()]);

        Http::fake(['exp.host/*' => Http::response([
            'data' => [['status' => 'error', 'message' => 'not registered', 'details' => ['error' => 'DeviceNotRegistered']]],
        ], 200)]);

        AdminNotificationService::notifyUser($admin->id, 'test_event', 'Something happened');

        $this->assertDatabaseMissing('admin_push_tokens', ['token' => 'ExponentPushToken[dead]']);
    }

    public function test_notification_creation_succeeds_even_when_expo_is_unreachable(): void
    {
        $admin = $this->admin();
        AdminPushToken::create(['admin_id' => $admin->id, 'token' => 'ExponentPushToken[xyz]', 'platform' => 'ios', 'last_seen_at' => now()]);

        Http::fake(['exp.host/*' => Http::response('Server Error', 500)]);

        $notification = AdminNotificationService::notifyUser($admin->id, 'test_event', 'Something happened');

        $this->assertNotNull($notification);
        $this->assertDatabaseHas('admin_notifications', ['admin_user_id' => $admin->id, 'type' => 'test_event']);
    }
}
