<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Customer;
use Tests\TestCase;

/**
 * P0 security fix — EnsureAdminToken middleware.
 *
 * Tests use actingAs() with in-memory model instances so no DB migration or
 * real Sanctum tokens are needed. actingAs() sets $request->user() directly,
 * which is exactly what EnsureAdminToken reads.
 *
 * Probe routes:
 *   GET  /admin/dashboard    — previously unguarded (no admin.role)
 *   GET  /admin/me           — previously unguarded
 *   GET  /admin/profile      — previously unguarded
 *   POST /admin/logout       — previously unguarded
 *   GET  /admin/orders       — has admin.role:super_admin,admin,order_manager
 *   GET  /admin/products     — has admin.role:super_admin,admin,editor
 *   GET  /admin/users        — has admin.role:super_admin,admin
 */
class AdminTokenGuardTest extends TestCase
{
    private function makeCustomer(): Customer
    {
        $c = new Customer([
            'id'                => 1,
            'customer_type'     => 'b2c',
            'first_name'        => 'Test',
            'last_name'         => 'Customer',
            'email'             => 'customer@test.com',
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $c->exists = true;

        return $c;
    }

    private function makeAdmin(string $role = 'super_admin'): AdminUser
    {
        $a = new AdminUser([
            'id'         => 1,
            'name'       => 'Test Admin',
            'first_name' => 'Test',
            'last_name'  => 'Admin',
            'email'      => 'admin@test.com',
            'role'       => $role,
            'is_active'  => true,
        ]);
        $a->exists = true;

        return $a;
    }

    // -------------------------------------------------------------------------
    // Customer model → must receive 403 on every tested admin route
    // -------------------------------------------------------------------------

    public function test_customer_token_rejected_on_dashboard(): void
    {
        $this->actingAs($this->makeCustomer(), 'sanctum')
            ->getJson('/api/v1/admin/dashboard')
            ->assertStatus(403)
            ->assertJson(['message' => 'Forbidden.']);
    }

    public function test_customer_token_rejected_on_me(): void
    {
        $this->actingAs($this->makeCustomer(), 'sanctum')
            ->getJson('/api/v1/admin/me')
            ->assertStatus(403)
            ->assertJson(['message' => 'Forbidden.']);
    }

    public function test_customer_token_rejected_on_profile(): void
    {
        $this->actingAs($this->makeCustomer(), 'sanctum')
            ->getJson('/api/v1/admin/profile')
            ->assertStatus(403)
            ->assertJson(['message' => 'Forbidden.']);
    }

    public function test_customer_token_rejected_on_logout(): void
    {
        $this->actingAs($this->makeCustomer(), 'sanctum')
            ->postJson('/api/v1/admin/logout')
            ->assertStatus(403)
            ->assertJson(['message' => 'Forbidden.']);
    }

    public function test_customer_token_rejected_on_orders(): void
    {
        $this->actingAs($this->makeCustomer(), 'sanctum')
            ->getJson('/api/v1/admin/orders')
            ->assertStatus(403)
            ->assertJson(['message' => 'Forbidden.']);
    }

    public function test_customer_token_rejected_on_products(): void
    {
        $this->actingAs($this->makeCustomer(), 'sanctum')
            ->getJson('/api/v1/admin/products')
            ->assertStatus(403)
            ->assertJson(['message' => 'Forbidden.']);
    }

    public function test_customer_token_rejected_on_users(): void
    {
        $this->actingAs($this->makeCustomer(), 'sanctum')
            ->getJson('/api/v1/admin/users')
            ->assertStatus(403)
            ->assertJson(['message' => 'Forbidden.']);
    }

    public function test_no_token_rejected_with_401_on_dashboard(): void
    {
        $this->getJson('/api/v1/admin/dashboard')
            ->assertStatus(401);
    }

    // -------------------------------------------------------------------------
    // AdminUser model → must pass EnsureAdminToken
    // (controllers may return other codes — we only assert NOT 403 from our guard)
    // -------------------------------------------------------------------------

    public function test_admin_token_passes_ensureAdminToken_on_dashboard(): void
    {
        // Dashboard has no admin.role guard.
        // The controller will hit the DB and likely fail in the test environment,
        // but the response must NOT be 403 from EnsureAdminToken.
        $response = $this->actingAs($this->makeAdmin(), 'sanctum')
            ->getJson('/api/v1/admin/dashboard');

        $this->assertNotEquals(403, $response->status(),
            'EnsureAdminToken must not block a valid AdminUser token.');
    }

    public function test_admin_token_passes_ensureAdminToken_on_me(): void
    {
        $response = $this->actingAs($this->makeAdmin(), 'sanctum')
            ->getJson('/api/v1/admin/me');

        $this->assertNotEquals(403, $response->status());
    }

    public function test_admin_token_passes_ensureAdminToken_on_profile(): void
    {
        $response = $this->actingAs($this->makeAdmin(), 'sanctum')
            ->getJson('/api/v1/admin/profile');

        $this->assertNotEquals(403, $response->status());
    }

    public function test_admin_token_passes_ensureAdminToken_on_orders(): void
    {
        $response = $this->actingAs($this->makeAdmin(), 'sanctum')
            ->getJson('/api/v1/admin/orders');

        $this->assertNotEquals(403, $response->status());
    }

    public function test_admin_token_passes_ensureAdminToken_on_products(): void
    {
        $response = $this->actingAs($this->makeAdmin(), 'sanctum')
            ->getJson('/api/v1/admin/products');

        $this->assertNotEquals(403, $response->status());
    }

    public function test_admin_token_passes_ensureAdminToken_on_users(): void
    {
        $response = $this->actingAs($this->makeAdmin(), 'sanctum')
            ->getJson('/api/v1/admin/users');

        $this->assertNotEquals(403, $response->status());
    }

    public function test_editor_passes_ensureAdminToken_but_blocked_by_role_on_users(): void
    {
        // EnsureAdminToken passes (editor IS an AdminUser).
        // 2FA is mandatory and runs before the access check (ensure.admin.2fa), so
        // the editor must have 2FA confirmed to reach it — otherwise the request
        // is short-circuited with 428 (two_factor_required).
        // /admin/users is guarded by permission:admins.manage, which an editor
        // lacks, so the access guard must return 403.
        $editor = $this->makeAdmin('editor');
        $editor->two_factor_confirmed_at = now();

        $this->actingAs($editor, 'sanctum')
            ->getJson('/api/v1/admin/users')
            ->assertStatus(403)
            ->assertJson(['message' => 'Forbidden. You do not have permission to perform this action.']);
    }

    public function test_editor_can_access_products_via_role(): void
    {
        // Editors are allowed on products (super_admin,admin,editor).
        // The controller may fail in test environment but must not be 403 from
        // either EnsureAdminToken or CheckAdminRole.
        $response = $this->actingAs($this->makeAdmin('editor'), 'sanctum')
            ->getJson('/api/v1/admin/products');

        $this->assertNotEquals(403, $response->status());
    }
}
