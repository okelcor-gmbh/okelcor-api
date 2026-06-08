<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\AdminCustomerAccessRequestController;
use App\Http\Controllers\Admin\AdminCustomerVerificationController;
use App\Http\Controllers\CustomerAccessRequestController;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\CustomerAccessRequest;
use App\Models\CustomerTimelineEvent;
use App\Services\CustomerApprovalService;
use App\Services\CustomerHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * CRM-8 — Buyer Approval & Customer Lifecycle (backend).
 *
 * Runs the full migration set on in-memory SQLite (RefreshDatabase) and
 * exercises the services + lifecycle controllers directly, bypassing the admin
 * 2FA HTTP middleware while still hitting real business logic and the real
 * migrated schema.
 */
class Crm8BuyerLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        // Must run BEFORE parent::setUp() — RefreshDatabase migrates there, and
        // the pre-existing migration set uses MySQL-only DDL (raw MODIFY COLUMN
        // ENUM) that SQLite cannot parse. The app isn't booted yet, so read the
        // connection from the environment rather than the DB facade.
        // Run with: DB_CONNECTION=mysql DB_DATABASE=<test_db> php artisan test --filter=Crm8
        $connection = getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? 'sqlite');
        if ($connection !== 'mysql') {
            $this->markTestSkipped('CRM-8 lifecycle tests require a MySQL connection (legacy migrations are MySQL-only).');
        }

        parent::setUp();
    }

    private function admin(): AdminUser
    {
        return AdminUser::create([
            'name'      => 'Approver',
            'email'     => 'approver@okelcor.test',
            'role'      => 'super_admin',
            'password'  => Hash::make('secret-pass-123'),
            'is_active' => true,
        ]);
    }

    private function customer(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'customer_type' => 'b2b',
            'first_name'    => 'Acme',
            'last_name'     => 'Buyer',
            'email'         => 'buyer' . uniqid() . '@acme-tyres.com',
            'password'      => Hash::make('secret-pass-123'),
            'phone'         => '+49 30 1234567',
            'country'       => 'DE',
            'company_name'  => 'Acme Tyres GmbH',
            'is_active'     => false,
            'onboarding_status' => 'pending_review',
        ], $overrides));
    }

    private function adminRequest(AdminUser $admin, array $input = []): Request
    {
        $req = Request::create('/', 'POST', $input);
        $req->setUserResolver(fn () => $admin);
        return $req;
    }

    // ── Schema / additive defaults ───────────────────────────────────────────

    public function test_migrations_add_lifecycle_schema(): void
    {
        $this->assertTrue(Schema::hasColumn('customers', 'buyer_tier'));
        $this->assertTrue(Schema::hasColumn('customers', 'verification_status'));
        $this->assertTrue(Schema::hasColumn('customers', 'health_score'));
        $this->assertTrue(Schema::hasColumn('customers', 'risk_level'));
        $this->assertTrue(Schema::hasColumn('customers', 'approved_by'));
        $this->assertTrue(Schema::hasTable('customer_verifications'));
        $this->assertTrue(Schema::hasTable('customer_timeline_events'));
        $this->assertTrue(Schema::hasTable('customer_access_requests'));
    }

    public function test_new_customer_gets_safe_lifecycle_defaults(): void
    {
        // Reload so DB column defaults (applied by the migration) are reflected.
        $c = $this->customer()->fresh();

        $this->assertSame('none', $c->buyer_tier);
        $this->assertSame('not_started', $c->verification_status);
        $this->assertSame('unknown', $c->risk_level);
        $this->assertNull($c->health_score);
    }

    // ── Approval profiles ────────────────────────────────────────────────────

    public function test_apply_approved_buyer_profile_sets_flags_and_timeline(): void
    {
        $c = $this->customer();
        $svc = app(CustomerApprovalService::class);

        $c = $svc->applyApprovalProfile($c, 'approved_buyer', $this->admin(), 'ok');

        $this->assertSame('approved_buyer', $c->access_level);
        $this->assertTrue((bool) $c->approved_for_checkout);
        $this->assertTrue((bool) $c->approved_for_documents);
        $this->assertFalse((bool) $c->approved_for_wholesale_pricing);
        $this->assertSame('bronze', $c->buyer_tier);

        $this->assertDatabaseHas('customer_timeline_events', [
            'customer_id' => $c->id,
            'event_type'  => 'access_profile_applied',
        ]);
    }

    public function test_wholesale_profile_enables_wholesale_pricing(): void
    {
        $c = $this->customer();
        $c = app(CustomerApprovalService::class)->applyApprovalProfile($c, 'wholesale_buyer');

        $this->assertSame('wholesale_buyer', $c->access_level);
        $this->assertTrue((bool) $c->approved_for_wholesale_pricing);
        $this->assertSame('silver', $c->buyer_tier);
    }

    public function test_block_profile_revokes_tokens_and_deactivates(): void
    {
        $c = $this->customer(['is_active' => true]);
        $c->createToken('session');
        $this->assertSame(1, $c->tokens()->count());

        $c = app(CustomerApprovalService::class)->applyApprovalProfile($c, 'blocked', $this->admin());

        $this->assertSame('blocked', $c->access_level);
        $this->assertFalse((bool) $c->approved_for_quotes);
        $this->assertFalse((bool) $c->is_active);
        $this->assertSame(0, $c->tokens()->count());
        $this->assertDatabaseHas('customer_timeline_events', [
            'customer_id' => $c->id,
            'event_type'  => 'customer_blocked',
        ]);
    }

    public function test_approve_buyer_stamps_audit_and_advances_onboarding(): void
    {
        $c = $this->customer(); // pending_review
        $admin = $this->admin();

        $c = app(CustomerApprovalService::class)->approveBuyer($c, 'approved_buyer', 'gold', $admin, 'Approved after proposal.');

        $this->assertSame($admin->id, $c->approved_by);
        $this->assertNotNull($c->approved_at);
        $this->assertSame('gold', $c->buyer_tier); // explicit override beats bronze default
        $this->assertSame('approved', $c->onboarding_status);
        $this->assertNull($c->rejection_reason);
        $this->assertDatabaseHas('customer_timeline_events', [
            'customer_id' => $c->id,
            'event_type'  => 'customer_approved',
        ]);
    }

    // ── Health scoring ───────────────────────────────────────────────────────

    public function test_health_score_high_for_complete_verified_b2b(): void
    {
        $c = $this->customer(['vat_verified' => true, 'verification_status' => 'verified']);

        $result = app(CustomerHealthService::class)->calculate($c);

        // verified(25) + vat(15) + complete profile(15) = 55 at minimum
        $this->assertGreaterThanOrEqual(55, $result['score']);
        $this->assertContains($result['risk_level'], ['low', 'medium', 'high', 'critical']);
    }

    public function test_health_score_critical_for_sparse_personal_email_b2b(): void
    {
        $c = $this->customer([
            'email'        => 'someone@gmail.com',
            'phone'        => null,
            'country'      => null,
            'company_name' => null,
        ]);

        $result = app(CustomerHealthService::class)->recalculateAndSave($c, $this->admin());

        $this->assertLessThan(40, $result['score']);
        $this->assertSame('critical', $result['risk_level']);
        $this->assertSame('critical', $c->fresh()->risk_level);
    }

    public function test_health_bands(): void
    {
        $svc = app(CustomerHealthService::class);
        $this->assertSame('low', $svc->band(85));
        $this->assertSame('medium', $svc->band(70));
        $this->assertSame('high', $svc->band(50));
        $this->assertSame('critical', $svc->band(20));
    }

    // ── Verifications ────────────────────────────────────────────────────────

    public function test_verification_marks_customer_verified_and_logs_timeline(): void
    {
        $c = $this->customer();
        $admin = $this->admin();
        $controller = app(AdminCustomerVerificationController::class);

        $req = $this->adminRequest($admin, [
            'type'   => 'company_registration',
            'value'  => 'HRB 12345',
            'status' => 'verified',
        ]);
        $resp = $controller->store($req, $c->id);

        $this->assertSame(201, $resp->getStatusCode());
        $this->assertSame('verified', $c->fresh()->verification_status);
        $this->assertDatabaseHas('customer_verifications', [
            'customer_id' => $c->id,
            'type'        => 'company_registration',
            'status'      => 'verified',
        ]);
        $this->assertDatabaseHas('customer_timeline_events', [
            'customer_id' => $c->id,
            'event_type'  => 'verification_updated',
        ]);
    }

    // ── Access requests (customer → admin) ───────────────────────────────────

    public function test_customer_access_request_then_admin_approval_grants_flag(): void
    {
        $c = $this->customer(['is_active' => true]);
        $admin = $this->admin();

        // Customer raises a request
        $custReq = Request::create('/', 'POST', ['requested_access' => 'checkout', 'reason' => 'Ready to buy']);
        $custReq->setUserResolver(fn () => $c);
        $created = app(CustomerAccessRequestController::class)->store($custReq);
        $this->assertSame(201, $created->getStatusCode());

        $reqRow = CustomerAccessRequest::where('customer_id', $c->id)->firstOrFail();
        $this->assertSame('pending', $reqRow->status);

        // Duplicate pending request is rejected with 409
        $dup = app(CustomerAccessRequestController::class)->store($custReq);
        $this->assertSame(409, $dup->getStatusCode());

        // Admin approves → checkout flag granted
        $adminReq = $this->adminRequest($admin, ['notes' => 'Approved']);
        $resp = app(AdminCustomerAccessRequestController::class)->approve($adminReq, $reqRow->id);

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('approved', $reqRow->fresh()->status);
        $this->assertTrue((bool) $c->fresh()->approved_for_checkout);
        $this->assertDatabaseHas('customer_timeline_events', [
            'customer_id' => $c->id,
            'event_type'  => 'access_request_approved',
        ]);
    }

    public function test_existing_active_customer_keeps_access_after_block_of_another(): void
    {
        // Safety: blocking one buyer must never touch another.
        $a = $this->customer(['is_active' => true, 'access_level' => 'approved_buyer', 'approved_for_checkout' => true]);
        $b = $this->customer(['is_active' => true, 'access_level' => 'approved_buyer', 'approved_for_checkout' => true]);

        app(CustomerApprovalService::class)->applyApprovalProfile($b, 'blocked', $this->admin());

        $this->assertTrue((bool) $a->fresh()->approved_for_checkout);
        $this->assertTrue((bool) $a->fresh()->is_active);
        $this->assertSame('approved_buyer', $a->fresh()->access_level);
    }
}
