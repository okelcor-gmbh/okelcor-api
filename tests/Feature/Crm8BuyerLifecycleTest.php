<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\AdminCustomerAccessRequestController;
use App\Http\Controllers\Admin\AdminCustomerController;
use App\Http\Controllers\Admin\AdminCustomerVerificationController;
use App\Http\Controllers\CustomerAccessRequestController;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\CustomerAccessRequest;
use App\Models\CustomerTimelineEvent;
use App\Services\CustomerApprovalService;
use App\Services\CustomerHealthService;
use App\Support\CustomerLifecyclePresenter;
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
            'email'     => 'approver' . uniqid() . '@okelcor.test',
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

    public function test_approve_buyer_activates_self_registered_customer(): void
    {
        // Self-registered: chose own password (must_reset_password=false), email verified.
        $c = $this->customer(['email_verified_at' => now(), 'must_reset_password' => false]);
        $admin = $this->admin();

        $c = app(CustomerApprovalService::class)->approveBuyer($c, 'approved_buyer', 'gold', $admin, 'Approved after proposal.');

        $this->assertSame($admin->id, $c->approved_by);
        $this->assertNotNull($c->approved_at);
        $this->assertSame('gold', $c->buyer_tier); // explicit override beats bronze default
        // Login state must be unlocked.
        $this->assertSame('active', $c->onboarding_status);
        $this->assertTrue((bool) $c->is_active);
        $this->assertSame('active', $c->status);
        $this->assertNull($c->rejection_reason);
        $this->assertTrue(CustomerLifecyclePresenter::fields($c)['login_ready']);
        $this->assertDatabaseHas('customer_timeline_events', [
            'customer_id' => $c->id,
            'event_type'  => 'customer_approved',
        ]);
    }

    public function test_approve_buyer_without_own_password_stays_in_invite_flow(): void
    {
        // Lead-converted: random password (must_reset_password=true) — needs invite.
        $c = $this->customer(['must_reset_password' => true]);

        $c = app(CustomerApprovalService::class)->approveBuyer($c, 'approved_buyer', null, $this->admin());

        $this->assertSame('approved', $c->onboarding_status);
        $this->assertFalse((bool) $c->is_active);
        $this->assertFalse(CustomerLifecyclePresenter::fields($c)['login_ready']);
        $this->assertTrue(CustomerLifecyclePresenter::fields($c)['pending_invitation']);
    }

    public function test_approval_sends_email_for_granting_profiles_only(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $svc = app(CustomerApprovalService::class);

        $granted = $this->customer(['email_verified_at' => now()]);
        $status  = $svc->sendApprovalEmail($granted, 'approved_buyer', $this->admin());
        $this->assertTrue($status['attempted']);
        $this->assertTrue($status['sent']);
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\ApprovedAccountEmail::class, fn ($m) => $m->hasTo($granted->email));
        $this->assertDatabaseHas('customer_timeline_events', [
            'customer_id' => $granted->id,
            'event_type'  => 'approval_email_sent',
        ]);

        // Restricted / blocked must NOT email.
        $restricted = $this->customer();
        $rStatus = $svc->sendApprovalEmail($restricted, 'restricted', $this->admin());
        $this->assertFalse($rStatus['attempted']);
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\ApprovedAccountEmail::class, 1); // still only the one above
    }

    public function test_approved_self_registered_customer_can_log_in(): void
    {
        // End-to-end: register-like customer, verify email, approve, then hit login.
        $password = 'super-secret-1';
        $c = $this->customer([
            'password'          => Hash::make($password),
            'email_verified_at' => now(),
            'must_reset_password' => false,
            'status'            => 'active',
        ]);

        app(CustomerApprovalService::class)->approveBuyer($c, 'approved_buyer', null, $this->admin());

        $resp = $this->postJson('/api/v1/auth/login', [
            'email'    => $c->email,
            'password' => $password,
        ]);

        $resp->assertStatus(200)
            ->assertJsonPath('data.customer.onboarding_status', 'active')
            ->assertJsonPath('data.customer.access_level', 'approved_buyer')
            ->assertJsonPath('data.customer.approved_for_checkout', true);
        $this->assertNotEmpty($resp->json('data.token'));
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

    public function test_rejected_verification_wins_the_rollup_over_an_unrelated_verified_one(): void
    {
        $c = $this->customer();
        $admin = $this->admin();
        $controller = app(AdminCustomerVerificationController::class);

        // Company registration verified first...
        $controller->store($this->adminRequest($admin, [
            'type' => 'company_registration', 'status' => 'verified',
        ]), $c->id);
        $this->assertSame('verified', $c->fresh()->verification_status);

        // ...then an unrelated VAT check comes back rejected. Overall status
        // must surface the rejection, not stay masked as "verified".
        $controller->store($this->adminRequest($admin, [
            'type' => 'vat_number', 'status' => 'rejected',
        ]), $c->id);

        $this->assertSame('rejected', $c->fresh()->verification_status);
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

    // ── Admin "Add Customer" onboarding (POST /admin/customers) ──────────────

    public function test_admin_add_customer_creates_approved_buyer_and_invites(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $admin = $this->admin();

        $req = $this->adminRequest($admin, [
            'customer_type'   => 'b2b',
            'first_name'      => 'New',
            'last_name'       => 'Buyer',
            'email'           => 'fresh-buyer@acme-tyres.com',
            'company_name'    => 'Acme Tyres GmbH',
            'country'         => 'DE',
            'access_level'    => 'approved_buyer',
            'onboarding_status' => 'approved',
            'send_invitation' => true,
            'notes'           => 'Met at trade show.',
            'created_via'     => 'admin',
        ]);

        $resp = app(AdminCustomerController::class)->store($req);
        $this->assertSame(201, $resp->getStatusCode());

        $payload = $resp->getData(true);
        $this->assertTrue($payload['data']['approved_for_checkout']);
        $this->assertTrue($payload['data']['approved_for_documents']);
        $this->assertSame('approved_buyer', $payload['data']['access_level']);
        // No usable login yet — must set a password via the invitation link.
        $this->assertSame('invited', $payload['data']['onboarding_status']);
        $this->assertTrue($payload['data']['pending_invitation']);
        $this->assertTrue($payload['data']['invitation_email']['sent']);
        $this->assertSame($admin->id, $payload['data']['approved_by']);

        $customer = Customer::where('email', 'fresh-buyer@acme-tyres.com')->firstOrFail();
        $this->assertSame('Met at trade show.', $customer->admin_notes);

        // A single-use set-password token was created.
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'fresh-buyer@acme-tyres.com']);
        \Illuminate\Support\Facades\Mail::assertSent(
            \App\Mail\CustomerInvitation::class,
            fn ($m) => $m->hasTo('fresh-buyer@acme-tyres.com')
        );
    }

    public function test_admin_add_customer_without_invitation_skips_email(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        $req = $this->adminRequest($this->admin(), [
            'customer_type'   => 'b2c',
            'first_name'      => 'Walk',
            'email'           => 'walkin@example.com',
            'access_level'    => 'approved_buyer',
            'send_invitation' => false,
        ]);

        $resp = app(AdminCustomerController::class)->store($req);
        $this->assertSame(201, $resp->getStatusCode());

        $payload = $resp->getData(true);
        $this->assertSame('approved', $payload['data']['onboarding_status']);
        $this->assertFalse($payload['data']['invitation_email']['attempted']);
        \Illuminate\Support\Facades\Mail::assertNothingSent();
    }

    public function test_admin_add_customer_b2b_requires_company_name(): void
    {
        $req = $this->adminRequest($this->admin(), [
            'customer_type'   => 'b2b',
            'first_name'      => 'No',
            'email'           => 'nocompany@example.com',
            'send_invitation' => false,
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(AdminCustomerController::class)->store($req);
    }

    public function test_admin_add_customer_duplicate_email_is_rejected(): void
    {
        $existing = $this->customer(['email' => 'taken@acme-tyres.com']);

        $req = $this->adminRequest($this->admin(), [
            'customer_type'   => 'b2c',
            'first_name'      => 'Dup',
            'email'           => 'taken@acme-tyres.com',
            'send_invitation' => false,
        ]);

        try {
            app(AdminCustomerController::class)->store($req);
            $this->fail('Expected a duplicate-email validation failure.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors());
        }
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

    // ── Health auto-recalculation wiring (Session 54 audit) ─────────────────
    //
    // Health/risk previously only recalculated on a verification change or a
    // manual "recalculate" click — order-paid and proposal-accepted events
    // (both scored factors) never touched it, so the score/band went stale
    // almost immediately after initial approval. These verify the fix.

    public function test_recalculate_for_email_updates_matching_customer(): void
    {
        // Complete profile (phone/country/company/email all present) scores
        // +15 under the formula — starting from a forced null proves the
        // lookup-by-email + recalculate + persist chain actually ran.
        $c = $this->customer(['health_score' => null]);

        app(CustomerHealthService::class)->recalculateForEmail($c->email);

        $this->assertSame(15, $c->fresh()->health_score);
    }

    public function test_recalculate_for_email_noops_when_no_matching_customer(): void
    {
        // Must not throw for a guest/eBay order email with no onboarded Customer row.
        app(CustomerHealthService::class)->recalculateForEmail('nobody-' . uniqid() . '@example.com');
        $this->assertTrue(true);
    }

    public function test_proposal_acceptance_triggers_health_recalculation(): void
    {
        $c = $this->customer(['health_score' => 0, 'risk_level' => 'critical']);

        $quote = \App\Models\QuoteRequest::create([
            'ref_number'            => 'QR-' . uniqid(),
            'full_name'             => $c->full_name,
            'email'                 => $c->email,
            'customer_id'           => $c->id,
            'country'               => 'DE',
            'tyre_category'         => 'pcr',
            'quantity'              => '100',
            'delivery_location'     => 'Berlin, DE',
            'notes'                 => 'Test inquiry',
            'status'                => 'quoted',
            'qualification_status'  => 'new',
            'proposal_status'       => 'sent',
            'proposal_number'       => 'QT-2026-0002',
        ]);

        $req = Request::create('/', 'POST');
        $req->setUserResolver(fn () => $c);

        app(\App\Http\Controllers\CustomerQuoteAcceptanceController::class)
            ->acceptProposal($req, $quote->ref_number);

        // "Accepted a proposal" is worth +20 in the scoring formula — a
        // customer starting at a forced 0 must move once this fires.
        $this->assertGreaterThan(0, $c->fresh()->health_score);
    }

    // ── Admin profile corrections (PATCH /admin/customers/{id}) ──────────────

    public function test_admin_can_correct_name_email_and_vat(): void
    {
        $admin = $this->admin();
        $c     = $this->customer(['vat_number' => 'DE111111111', 'vat_verified' => true]);

        $req = $this->adminRequest($admin, [
            'first_name' => 'Acmé',
            'email'      => 'corrected' . uniqid() . '@acme-tyres.com',
            'vat_number' => 'DE222222222',
        ]);

        $response = app(AdminCustomerController::class)->update($req, $c->id);
        $payload  = json_decode($response->getContent(), true);

        $this->assertTrue($payload['success']);
        $this->assertSame('Acmé', $payload['data']['first_name']);
        $this->assertSame('DE222222222', $payload['data']['vat_number']);

        // Changing the VAT number without confirming it resets the verified flag —
        // an admin-typed correction must not keep the old "verified" badge.
        $this->assertFalse($payload['data']['vat_verified']);

        $this->assertDatabaseHas('customer_timeline_events', [
            'customer_id' => $c->id,
            'event_type'  => 'profile_corrected',
        ]);
    }

    public function test_admin_can_confirm_vat_verified_explicitly(): void
    {
        $admin = $this->admin();
        $c     = $this->customer(['vat_number' => 'DE111111111', 'vat_verified' => false]);

        $req = $this->adminRequest($admin, [
            'vat_number'   => 'DE222222222',
            'vat_verified' => true,
        ]);

        $response = app(AdminCustomerController::class)->update($req, $c->id);
        $payload  = json_decode($response->getContent(), true);

        $this->assertTrue($payload['data']['vat_verified']);
    }

    public function test_admin_cannot_reuse_another_customers_email(): void
    {
        $admin = $this->admin();
        $taken = $this->customer();
        $c     = $this->customer();

        $req = $this->adminRequest($admin, ['email' => $taken->email]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(AdminCustomerController::class)->update($req, $c->id);
    }
}
