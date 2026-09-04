<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Claim;
use App\Models\Customer;
use App\Models\StaffActivity;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The customer's half of the claims loop (Session 120): file a claim from
 * the portal, watch its status, hear the decision — landing in the SAME
 * queue staff already work (Session 119), marked source: portal.
 *
 * Minimal-schema sqlite harness, same pattern as ClaimsQueueTest, plus the
 * customer tables the portal side needs.
 */
class CustomerClaimPortalTest extends TestCase
{
    private int $seq = 0;

    private const TABLES = [
        'claims', 'staff_activities', 'staff_contributions',
        'admin_notifications', 'customer_notifications',
        'admin_security_events', 'quote_requests',
        'orders', 'personal_access_tokens', 'customers', 'admin_users',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::disableForeignKeyConstraints();
        foreach (self::TABLES as $t) {
            Schema::dropIfExists($t);
        }

        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('display_name')->nullable();
            $table->string('job_title')->nullable();
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role');
            $table->boolean('is_active')->default(true);
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('customer_type', 10)->default('b2b');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->unique();
            $table->string('password');
            $table->string('company_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('onboarding_status', 30)->default('active');
            $table->string('access_level', 30)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->json('notification_preferences')->nullable();
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('ref', 30)->unique();
            $table->string('customer_email')->nullable();
            $table->string('status', 40)->default('pending');
            $table->timestamps();
        });

        Schema::create('quote_requests', function (Blueprint $table) {
            $table->id();
            $table->string('ref_number')->nullable();
            $table->string('company_name')->nullable();
            $table->string('full_name')->nullable();
            $table->string('status', 40)->nullable();
            $table->string('qualification_status', 40)->nullable();
            $table->string('lead_priority', 20)->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('follow_up_at')->nullable();
            $table->timestamp('follow_up_completed_at')->nullable();
            $table->string('proposal_status', 40)->nullable();
            $table->string('proposal_number')->nullable();
            $table->timestamp('proposal_accepted_at')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
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

        Schema::create('admin_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_user_id')->constrained('admin_users')->cascadeOnDelete();
            $table->string('type', 100);
            $table->string('severity', 20)->default('info');
            $table->string('title', 255);
            $table->text('body')->nullable();
            $table->text('message')->nullable();
            $table->string('action_url', 255)->nullable();
            $table->string('link', 255)->nullable();
            $table->string('related_type', 100)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->string('dedupe_key', 191)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('type', 100);
            $table->string('severity', 20)->default('info');
            $table->string('title', 255);
            $table->text('body')->nullable();
            $table->string('action_url', 255)->nullable();
            $table->string('related_type', 100)->nullable();
            $table->string('related_id', 64)->nullable();
            $table->timestamp('email_sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        // The real migrations, run against real SQL.
        $this->runMigration('2026_08_17_000001_create_staff_activity_tables');
        $this->runMigration('2026_09_04_000003_create_claims_table');
        $this->runMigration('2026_09_04_000004_add_customer_link_to_claims_table');

        Schema::enableForeignKeyConstraints();

        Claim::forgetAvailableCheck();
        StaffActivity::forgetLedgerCheck();
    }

    protected function tearDown(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (self::TABLES as $t) {
            Schema::dropIfExists($t);
        }
        Schema::enableForeignKeyConstraints();
        Claim::forgetAvailableCheck();
        StaffActivity::forgetLedgerCheck();
        parent::tearDown();
    }

    private function runMigration(string $name): void
    {
        (require database_path("migrations/{$name}.php"))->up();
    }

    private function admin(string $role = 'viewer'): AdminUser
    {
        return AdminUser::create([
            'name'                    => 'Staff ' . (++$this->seq),
            'email'                   => 'cp' . $this->seq . uniqid() . '@okelcor.test',
            'role'                    => $role,
            'password'                => Hash::make('secret-pass-123'),
            'is_active'               => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function customer(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'customer_type'     => 'b2b',
            'first_name'        => 'Greta',
            'last_name'         => 'Weber',
            'email'             => 'greta' . (++$this->seq) . uniqid() . '@kunde.test',
            'password'          => Hash::make('secret-pass-123'),
            'company_name'      => 'Weber Reifenhandel',
            'is_active'         => true,
            'onboarding_status' => 'active',
            'access_level'      => 'full',
            'email_verified_at' => now(),
        ], $overrides));
    }

    private function asCustomer(Customer $customer): array
    {
        return ['Authorization' => 'Bearer ' . $customer->createToken('portal')->plainTextToken];
    }

    // ── the migration itself ──────────────────────────────────────────────

    public function test_the_migration_applies_against_real_sql_and_is_idempotent(): void
    {
        $this->runMigration('2026_09_04_000004_add_customer_link_to_claims_table');

        $this->assertTrue(Schema::hasColumn('claims', 'customer_id'));
        $this->assertTrue(Schema::hasColumn('claims', 'source'));
    }

    // ── filing ────────────────────────────────────────────────────────────

    public function test_a_customer_files_a_claim_and_it_lands_in_the_admin_queue_marked_portal(): void
    {
        $manager  = $this->admin('order_manager');
        $customer = $this->customer();

        $this->withHeaders($this->asCustomer($customer))
            ->postJson('/api/v1/auth/claims', [
                'type'        => 'damage',
                'description' => 'Six tyres arrived with deep sidewall cuts from the pallet strapping.',
                'quantity'    => 6,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'new')
            ->assertJsonPath('data.ref', 'CLM-00001');

        $claim = Claim::firstOrFail();
        $this->assertSame($customer->id, $claim->customer_id);
        $this->assertSame('portal', $claim->source);
        $this->assertNull($claim->created_by);

        // The whole claims team hears about an unassigned portal claim…
        $this->assertDatabaseHas('admin_notifications', [
            'admin_user_id' => $manager->id,
            'type'          => 'claim_filed',
        ]);

        // …and the customer gets the filed-and-numbered confirmation the
        // e-mail thread never gave them.
        $this->assertDatabaseHas('customer_notifications', [
            'customer_id' => $customer->id,
            'type'        => 'claim_received',
        ]);

        // No person, no ledger row: the customer's act is nobody's staff
        // contribution.
        $this->assertDatabaseMissing('staff_activities', ['action' => 'claim_logged']);
    }

    public function test_an_order_ref_must_belong_to_the_customer(): void
    {
        $customer = $this->customer();
        \App\Models\Order::create(['ref' => 'OK-2026-0001', 'customer_email' => 'somebody.else@firma.test']);
        \App\Models\Order::create(['ref' => 'OK-2026-0002', 'customer_email' => strtoupper($customer->email)]);

        // Someone else's order: refused, not silently unlinked.
        $this->withHeaders($this->asCustomer($customer))
            ->postJson('/api/v1/auth/claims', [
                'order_ref'   => 'OK-2026-0001',
                'description' => 'The delivery came in with the wrong tread pattern on all items.',
            ])
            ->assertStatus(422);

        // Their own order (matched case-insensitively, like the rest of the
        // portal): linked by id AND ref.
        $this->withHeaders($this->asCustomer($customer))
            ->postJson('/api/v1/auth/claims', [
                'order_ref'   => 'OK-2026-0002',
                'description' => 'The delivery came in with the wrong tread pattern on all items.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.order_number', 'OK-2026-0002');
    }

    // ── watching ──────────────────────────────────────────────────────────

    public function test_a_customer_sees_only_their_own_claims_with_plain_words_and_no_internals(): void
    {
        $mine   = $this->customer();
        $other  = $this->customer(['email' => 'other' . uniqid() . '@kunde.test']);
        $staff  = $this->admin('support');

        $this->withHeaders($this->asCustomer($mine))
            ->postJson('/api/v1/auth/claims', [
                'description' => 'Two of the winter tyres in the shipment show cracking on the shoulder.',
            ])->assertCreated();

        // Another customer's portal claim and a staff-logged e-mail claim —
        // neither may appear in this account's list.
        $this->withHeaders($this->asCustomer($other))
            ->postJson('/api/v1/auth/claims', [
                'description' => 'A different account files a different complaint about a shipment.',
            ])->assertCreated();
        $this->actingAs($staff, 'sanctum')->postJson('/api/v1/admin/claims', [
            'customer_name' => 'E-mail thread GmbH', 'description' => 'Logged by staff from the inbox.',
        ])->assertCreated();

        $list = $this->withHeaders($this->asCustomer($mine))
            ->getJson('/api/v1/auth/claims')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.open_count', 1)
            ->json('data.0');

        $this->assertSame('Received. Our team will pick this up shortly.', $list['status_note']);
        // The customer view carries no assignee and no internal ids.
        $this->assertArrayNotHasKey('assigned_admin_id', $list);
        $this->assertArrayNotHasKey('assignee', $list);
    }

    // ── the decision reaches them ─────────────────────────────────────────

    public function test_a_status_change_by_staff_notifies_the_customer_in_plain_words(): void
    {
        $customer = $this->customer();
        $support  = $this->admin('support');

        $claimId = $this->withHeaders($this->asCustomer($customer))
            ->postJson('/api/v1/auth/claims', [
                'type'        => 'shortage',
                'description' => 'The container manifest lists 400 units but only 388 were delivered.',
            ])->json('data.id');

        $this->actingAs($support, 'sanctum')
            ->patchJson("/api/v1/admin/claims/{$claimId}", [
                'status'       => 'approved',
                'outcome_note' => 'Credit note for the twelve missing units is on its way.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('customer_notifications', [
            'customer_id' => $customer->id,
            'type'        => 'claim_update',
            'severity'    => 'success',
        ]);

        // And the customer's own list reflects it.
        $this->withHeaders($this->asCustomer($customer))
            ->getJson('/api/v1/auth/claims')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'approved')
            ->assertJsonPath('data.0.outcome_note', 'Credit note for the twelve missing units is on its way.');
    }

    public function test_a_staff_logged_email_claim_notifies_no_customer_account(): void
    {
        $support = $this->admin('support');

        $claimId = $this->actingAs($support, 'sanctum')->postJson('/api/v1/admin/claims', [
            'customer_name' => 'E-mail thread GmbH',
            'description'   => 'Logged by staff from the inbox; the customer has no portal account.',
        ])->json('data.id');

        $this->actingAs($support, 'sanctum')
            ->patchJson("/api/v1/admin/claims/{$claimId}", ['status' => 'approved'])
            ->assertOk();

        $this->assertDatabaseMissing('customer_notifications', ['type' => 'claim_update']);
    }

    // ── guardrails ────────────────────────────────────────────────────────

    public function test_filing_requires_a_real_description_and_an_account(): void
    {
        // No token → 401.
        $this->postJson('/api/v1/auth/claims', ['description' => str_repeat('x', 30)])
            ->assertStatus(401);

        // A description too short to act on → 422.
        $this->withHeaders($this->asCustomer($this->customer()))
            ->postJson('/api/v1/auth/claims', ['description' => 'broken'])
            ->assertStatus(422);
    }

    public function test_the_portal_degrades_gracefully_before_the_link_migration_runs(): void
    {
        // The Session 119 table without the Session 120 columns.
        Schema::disableForeignKeyConstraints();
        Schema::drop('claims');
        Schema::enableForeignKeyConstraints();
        $this->runMigration('2026_09_04_000003_create_claims_table');
        Claim::forgetAvailableCheck();

        $headers = $this->asCustomer($this->customer());

        $this->withHeaders($headers)
            ->getJson('/api/v1/auth/claims')
            ->assertOk()
            ->assertJsonPath('meta.claims_available', false);

        $this->withHeaders($headers)
            ->postJson('/api/v1/auth/claims', ['description' => str_repeat('a claim. ', 5)])
            ->assertStatus(503);

        // The admin queue keeps working exactly as before.
        $this->actingAs($this->admin('support'), 'sanctum')
            ->postJson('/api/v1/admin/claims', [
                'customer_name' => 'Still works GmbH', 'description' => 'Admin logging unaffected.',
            ])
            ->assertCreated();
    }
}
