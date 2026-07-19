<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * PUT /admin/presence — the mobile app's live-chat availability toggle.
 *
 * Run with:
 *   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=okelcor_cms_test \
 *   DB_USERNAME=root DB_PASSWORD= php artisan test --filter=AdminPresence
 */
class AdminPresenceTest extends TestCase
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
            'name' => 'Ops ' . uniqid(), 'email' => 'ops' . uniqid() . '@okelcor.test', 'role' => 'admin',
            'password' => Hash::make('secret-pass-123'), 'is_active' => true, 'two_factor_confirmed_at' => now(),
        ]);
    }

    public function test_defaults_to_unavailable(): void
    {
        $admin = $this->admin();
        $this->assertFalse((bool) $admin->fresh()->available_for_chat);
    }

    public function test_can_toggle_availability_on_and_off(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/admin/presence', ['available_for_chat' => true])
            ->assertOk()
            ->assertJsonPath('data.available_for_chat', true);

        $this->assertTrue((bool) $admin->fresh()->available_for_chat);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/admin/presence', ['available_for_chat' => false])
            ->assertOk()
            ->assertJsonPath('data.available_for_chat', false);

        $this->assertFalse((bool) $admin->fresh()->available_for_chat);
    }

    public function test_me_endpoint_reflects_current_presence(): void
    {
        $admin = $this->admin();
        $admin->update(['available_for_chat' => true]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/me')
            ->assertOk()
            ->assertJsonPath('data.available_for_chat', true);
    }
}
