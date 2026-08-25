<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Support\AdminPermissions;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Per-user permission overrides: a super admin can add or remove single
 * permissions for one person without moving them to a different role.
 *
 * Enforcement is proven against a real gated endpoint
 * (GET /admin/system/health, permission system.view) rather than by
 * inspecting arrays — the middleware honoring the override is the feature.
 */
class PermissionOverridesTest extends TestCase
{
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('admin_security_events');
        Schema::dropIfExists('admin_users');

        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role');
            $table->json('permission_grants')->nullable();
            $table->json('permission_revokes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('admin_security_events', function (Blueprint $table) {
            $table->id();
            $table->string('type', 100);
            $table->string('severity', 20)->default('info');
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('admin_email')->nullable();
            $table->string('admin_role', 50)->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('description', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();
    }

    protected function tearDown(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('admin_security_events');
        Schema::dropIfExists('admin_users');
        Schema::enableForeignKeyConstraints();

        parent::tearDown();
    }

    private function admin(string $role, array $overrides = []): AdminUser
    {
        return AdminUser::create(array_merge([
            'name'                    => 'Staff ' . (++$this->seq),
            'email'                   => 'staff' . $this->seq . uniqid() . '@okelcor.test',
            'role'                    => $role,
            'password'                => Hash::make('secret-pass-123'),
            'is_active'               => true,
            'two_factor_confirmed_at' => now(),
        ], $overrides));
    }

    // -------------------------------------------------------------------------
    // Resolution
    // -------------------------------------------------------------------------

    public function test_grants_add_and_revokes_remove_on_top_of_the_role(): void
    {
        $user = $this->admin('viewer');
        $user->forceFill([
            'permission_grants'  => ['system.view', 'not.a.real.permission'],
            'permission_revokes' => ['products.view'],
        ])->save();
        $user = $user->fresh();

        $effective = $user->effectivePermissions();

        $this->assertContains('system.view', $effective, 'granted permission missing');
        $this->assertNotContains('products.view', $effective, 'revoked permission still present');
        $this->assertNotContains('not.a.real.permission', $effective, 'unknown grant must be ignored');
        $this->assertContains('staff.self', $effective, 'role baseline must survive');
    }

    public function test_super_admin_is_immune_to_stored_overrides(): void
    {
        $user = $this->admin('super_admin');
        $user->forceFill(['permission_revokes' => ['admins.manage']])->save();

        $this->assertTrue($user->fresh()->hasPermission('admins.manage'));
    }

    // -------------------------------------------------------------------------
    // Enforcement — the middleware must honor overrides, not just store them
    // -------------------------------------------------------------------------

    public function test_a_granted_permission_opens_the_gated_endpoint(): void
    {
        $viewer = $this->admin('viewer');

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/admin/system/health')
            ->assertForbidden();

        $this->actingAs($this->admin('super_admin'), 'sanctum')
            ->putJson("/api/v1/admin/users/{$viewer->id}/permissions", [
                'grants'  => ['system.view'],
                'revokes' => [],
            ])
            ->assertOk()
            ->assertJsonPath('data.has_permission_overrides', true);

        $this->actingAs($viewer->fresh(), 'sanctum')
            ->getJson('/api/v1/admin/system/health')
            ->assertOk();
    }

    public function test_a_revoked_permission_closes_an_endpoint_the_role_normally_has(): void
    {
        $admin = $this->admin('admin');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/system/health')
            ->assertOk();

        $this->actingAs($this->admin('super_admin'), 'sanctum')
            ->putJson("/api/v1/admin/users/{$admin->id}/permissions", [
                'grants'  => [],
                'revokes' => ['system.view'],
            ])
            ->assertOk();

        $this->actingAs($admin->fresh(), 'sanctum')
            ->getJson('/api/v1/admin/system/health')
            ->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // The editing endpoint
    // -------------------------------------------------------------------------

    public function test_only_a_super_admin_can_edit_overrides(): void
    {
        $target = $this->admin('viewer');

        $this->actingAs($this->admin('admin'), 'sanctum')
            ->putJson("/api/v1/admin/users/{$target->id}/permissions", [
                'grants' => ['system.view'], 'revokes' => [],
            ])
            ->assertForbidden();
    }

    public function test_a_super_admin_target_is_refused(): void
    {
        $target = $this->admin('super_admin');

        $this->actingAs($this->admin('super_admin'), 'sanctum')
            ->putJson("/api/v1/admin/users/{$target->id}/permissions", [
                'grants' => [], 'revokes' => ['admins.manage'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'super_admin_immutable');
    }

    public function test_unknown_keys_and_grant_revoke_conflicts_are_rejected(): void
    {
        $target = $this->admin('viewer');
        $sa     = $this->admin('super_admin');

        $this->actingAs($sa, 'sanctum')
            ->putJson("/api/v1/admin/users/{$target->id}/permissions", [
                'grants' => ['no.such.permission'], 'revokes' => [],
            ])
            ->assertStatus(422);

        $this->actingAs($sa, 'sanctum')
            ->putJson("/api/v1/admin/users/{$target->id}/permissions", [
                'grants' => ['system.view'], 'revokes' => ['system.view'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'grant_revoke_conflict');
    }

    public function test_redundant_overrides_are_normalized_away(): void
    {
        // Granting what the role already holds, and revoking what it never
        // had, must store nothing — the user reads as standard for the role.
        $target = $this->admin('viewer');   // viewer already holds products.view

        $this->actingAs($this->admin('super_admin'), 'sanctum')
            ->putJson("/api/v1/admin/users/{$target->id}/permissions", [
                'grants'  => ['products.view'],
                'revokes' => ['ebay.manage'],
            ])
            ->assertOk()
            ->assertJsonPath('data.has_permission_overrides', false)
            ->assertJsonPath('data.permission_grants', [])
            ->assertJsonPath('data.permission_revokes', []);
    }

    public function test_the_catalog_lists_every_permission_with_its_default_roles(): void
    {
        $response = $this->actingAs($this->admin('super_admin'), 'sanctum')
            ->getJson('/api/v1/admin/permissions/catalog')
            ->assertOk();

        $keys = collect($response->json('data.permissions'))->pluck('key');

        $this->assertSame(count(AdminPermissions::MAP), $keys->count());
        $this->assertContains('quotes.manage', $keys->all());
        $this->assertSame(AdminPermissions::ROLES, $response->json('data.roles'));
    }

    public function test_user_payload_carries_effective_permissions(): void
    {
        $target = $this->admin('viewer');
        $target->forceFill(['permission_grants' => ['system.view']])->save();

        $this->actingAs($this->admin('super_admin'), 'sanctum')
            ->getJson("/api/v1/admin/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('data.has_permission_overrides', true);

        $this->assertContains(
            'system.view',
            $this->actingAs($this->admin('super_admin'), 'sanctum')
                ->getJson("/api/v1/admin/users/{$target->id}")
                ->json('data.permissions')
        );
    }
}
