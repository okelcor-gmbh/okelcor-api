<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Claim;
use App\Models\StaffActivity;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The after-sales claims queue (Session 119): claims come out of e-mail
 * threads and into a structured queue — status + assignee + My Work +
 * notify-on-change, the same machinery as the finance snapshot and the
 * team to-dos. Being assigned a claim is authorization to work it from
 * My Work; the queue itself is behind claims.view / claims.manage.
 *
 * Minimal-schema sqlite harness, same pattern as TeamTodoListTest.
 */
class ClaimsQueueTest extends TestCase
{
    private int $seq = 0;

    private const TABLES = [
        'claims', 'staff_activities', 'staff_contributions',
        'admin_notifications', 'admin_security_events',
        'quote_requests', 'admin_users',
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

        // The My Work endpoint reads these alongside the claims under test.
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

        // The real migrations, run against real SQL — the ledger too, so the
        // contribution rows a claim writes can be asserted.
        $this->runMigration('2026_08_17_000001_create_staff_activity_tables');
        $this->runMigration('2026_09_04_000003_create_claims_table');

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
            'email'                   => 'cl' . $this->seq . uniqid() . '@okelcor.test',
            'role'                    => $role,
            'password'                => Hash::make('secret-pass-123'),
            'is_active'               => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /** @return array{claim_id: int, creator: AdminUser, assignee: AdminUser} */
    private function loggedClaim(array $overrides = [], string $creatorRole = 'support', string $assigneeRole = 'viewer'): array
    {
        $creator  = $this->admin($creatorRole);
        $assignee = $this->admin($assigneeRole);

        $claimId = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/v1/admin/claims', array_merge([
                'customer_name'     => 'Reifen Gross GmbH',
                'customer_email'    => 'einkauf@reifengross.test',
                'order_number'      => 'OK-2026-0455',
                'type'              => 'damage',
                'description'       => 'Twelve of the forty tyres arrived with sidewall cuts from the strapping.',
                'quantity'          => 12,
                'assigned_admin_id' => $assignee->id,
            ], $overrides))
            ->assertCreated()
            ->json('data.id');

        return ['claim_id' => $claimId, 'creator' => $creator, 'assignee' => $assignee];
    }

    // ── the migration itself ──────────────────────────────────────────────

    public function test_the_migration_applies_against_real_sql_and_is_idempotent(): void
    {
        $this->runMigration('2026_09_04_000003_create_claims_table');

        $this->assertTrue(Schema::hasTable('claims'));
    }

    // ── logging a claim ───────────────────────────────────────────────────

    public function test_logging_a_claim_stamps_a_ref_and_credits_the_logger_in_the_ledger(): void
    {
        ['claim_id' => $claimId, 'creator' => $creator] = $this->loggedClaim();

        $claim = Claim::findOrFail($claimId);
        $this->assertSame(sprintf('CLM-%05d', $claimId), $claim->ref);
        $this->assertSame('new', $claim->status);

        // Pulling the claim out of the e-mail thread IS work, and it is the
        // support person's work — the single biggest reason finance had zero
        // recorded contributions was surfaces like this not writing rows.
        $this->assertDatabaseHas('staff_activities', [
            'admin_user_id' => $creator->id,
            'action'        => 'claim_logged',
            'category'      => 'support',
            'source_type'   => 'claim',
            'source_id'     => $claimId,
        ]);
    }

    public function test_the_queue_reads_oldest_open_claim_first_with_stats_in_meta(): void
    {
        ['claim_id' => $older] = $this->loggedClaim();
        DB::table('claims')->where('id', $older)->update(['created_at' => now()->subDays(6)]);

        $this->loggedClaim(['customer_name' => 'Newer claimant']);

        $response = $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->getJson('/api/v1/admin/claims')
            ->assertOk()
            ->assertJsonPath('meta.claims_available', true)
            ->assertJsonPath('meta.open_count', 2)
            // Oldest first: the customer who has waited longest is on top.
            ->assertJsonPath('data.0.id', $older);

        $this->assertGreaterThanOrEqual(6, $response->json('data.0.age_days'));
    }

    // ── who may open the queue ────────────────────────────────────────────

    public function test_roles_outside_the_claims_team_cannot_open_the_queue(): void
    {
        $this->actingAs($this->admin('content_manager'), 'sanctum')
            ->getJson('/api/v1/admin/claims')
            ->assertForbidden();

        $this->actingAs($this->admin('marketing'), 'sanctum')
            ->postJson('/api/v1/admin/claims', ['customer_name' => 'X', 'description' => 'Y'])
            ->assertForbidden();
    }

    public function test_finance_reads_the_queue_but_does_not_write_it(): void
    {
        $finance = $this->admin('finance');

        $this->actingAs($finance, 'sanctum')
            ->getJson('/api/v1/admin/claims')
            ->assertOk();

        $this->actingAs($finance, 'sanctum')
            ->postJson('/api/v1/admin/claims', [
                'customer_name' => 'X', 'description' => 'Y',
            ])
            ->assertForbidden();
    }

    // ── the tag ───────────────────────────────────────────────────────────

    public function test_tagging_an_assignee_notifies_them_and_lands_in_their_my_work(): void
    {
        ['claim_id' => $claimId, 'assignee' => $assignee] = $this->loggedClaim();

        $this->assertDatabaseHas('admin_notifications', [
            'admin_user_id' => $assignee->id,
            'type'          => 'claim_assigned',
        ]);

        $work = $this->actingAs($assignee, 'sanctum')
            ->getJson('/api/v1/admin/my-work')
            ->assertOk()
            ->assertJsonPath('meta.counts.claim_tasks', 1)
            ->json('data.claim_tasks.0');

        $this->assertSame($claimId, $work['id']);
        $this->assertTrue($work['editable']);
        $this->assertStringContainsString('Reifen Gross', $work['title']);
        // The status select's options travel with the item — the panel
        // renders whatever the API declares, which is how drift is avoided.
        $this->assertSame(
            array_keys(Claim::STATUS_LABELS),
            array_column($work['status_options'], 'value'),
        );
        // A viewer cannot open the claims queue, so no queue link is offered.
        $this->assertNull($work['queue_url']);
    }

    // ── the assignee's half of the loop ───────────────────────────────────

    public function test_the_assignee_decides_the_claim_from_my_work_without_any_claims_permission(): void
    {
        ['claim_id' => $claimId, 'creator' => $creator, 'assignee' => $assignee] = $this->loggedClaim();

        // `viewer` holds neither claims.view nor claims.manage — being the
        // assignee IS the authorization, the snapshot-board contract.
        $this->actingAs($assignee, 'sanctum')
            ->patchJson("/api/v1/admin/my-work/claims/{$claimId}", [
                'status'       => 'approved',
                'outcome_note' => 'Credit note for the twelve damaged units; photos on file.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $claim = Claim::findOrFail($claimId);
        $this->assertNotNull($claim->resolved_at);
        $this->assertSame($assignee->id, $claim->resolved_by);
        $this->assertNull($claim->closed_at);

        // The decision travels back to whoever logged the claim.
        $this->assertDatabaseHas('admin_notifications', [
            'admin_user_id' => $creator->id,
            'type'          => 'claim_status_changed',
        ]);

        // And the decision is the decider's contribution.
        $this->assertDatabaseHas('staff_activities', [
            'admin_user_id' => $assignee->id,
            'action'        => 'claim_resolved',
            'source_id'     => $claimId,
        ]);
    }

    public function test_a_bystander_cannot_update_someone_elses_claim_from_my_work(): void
    {
        ['claim_id' => $claimId] = $this->loggedClaim();

        $this->actingAs($this->admin('viewer'), 'sanctum')
            ->patchJson("/api/v1/admin/my-work/claims/{$claimId}", ['status' => 'closed'])
            ->assertForbidden();
    }

    public function test_an_approved_claim_stays_in_my_work_until_closed_and_reopening_clears_the_decision(): void
    {
        ['claim_id' => $claimId, 'assignee' => $assignee] = $this->loggedClaim();

        // Approve: still open work — the credit note has not gone out.
        $this->actingAs($assignee, 'sanctum')
            ->patchJson("/api/v1/admin/my-work/claims/{$claimId}", ['status' => 'approved'])
            ->assertOk();

        $this->actingAs($assignee, 'sanctum')
            ->getJson('/api/v1/admin/my-work')
            ->assertJsonPath('meta.counts.claim_tasks', 1);

        // Close: the loop is done and the claim leaves the plate.
        $this->actingAs($assignee, 'sanctum')
            ->patchJson("/api/v1/admin/my-work/claims/{$claimId}", ['status' => 'closed'])
            ->assertOk();

        $this->actingAs($assignee, 'sanctum')
            ->getJson('/api/v1/admin/my-work')
            ->assertJsonPath('meta.counts.claim_tasks', 0);

        $claim = Claim::findOrFail($claimId);
        $this->assertNotNull($claim->closed_at);
        // Closing after approving is not a second decision — the decision
        // date survives.
        $this->assertNotNull($claim->resolved_at);

        // Reopen: it was NOT decided after all, and the stamps say so.
        $this->actingAs($assignee, 'sanctum')
            ->patchJson("/api/v1/admin/my-work/claims/{$claimId}", ['status' => 'in_review'])
            ->assertOk();

        $claim->refresh();
        $this->assertNull($claim->resolved_at);
        $this->assertNull($claim->resolved_by);
        $this->assertNull($claim->closed_at);
    }

    // ── the quality signal ────────────────────────────────────────────────

    public function test_meta_reports_the_average_days_from_logged_to_decided(): void
    {
        ['claim_id' => $claimId, 'assignee' => $assignee] = $this->loggedClaim();
        DB::table('claims')->where('id', $claimId)->update(['created_at' => now()->subDays(4)]);

        $this->actingAs($assignee, 'sanctum')
            ->patchJson("/api/v1/admin/my-work/claims/{$claimId}", ['status' => 'approved'])
            ->assertOk();

        $avg = $this->actingAs($this->admin('support'), 'sanctum')
            ->getJson('/api/v1/admin/claims?status=all')
            ->assertOk()
            ->json('meta.avg_days_to_decision');

        $this->assertEqualsWithDelta(4.0, $avg, 0.2);
    }

    // ── deleting ──────────────────────────────────────────────────────────

    public function test_only_super_admin_deletes_a_claim(): void
    {
        ['claim_id' => $claimId] = $this->loggedClaim();

        $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->deleteJson("/api/v1/admin/claims/{$claimId}")
            ->assertForbidden();

        $this->actingAs($this->admin('super_admin'), 'sanctum')
            ->deleteJson("/api/v1/admin/claims/{$claimId}")
            ->assertOk();

        $this->assertDatabaseMissing('claims', ['id' => $claimId]);
    }

    // ── deploy-order safety ───────────────────────────────────────────────

    public function test_the_panel_degrades_gracefully_before_the_migration_runs(): void
    {
        Schema::drop('claims');
        Claim::forgetAvailableCheck();

        $support = $this->admin('support');

        $this->actingAs($support, 'sanctum')
            ->getJson('/api/v1/admin/claims')
            ->assertOk()
            ->assertJsonPath('meta.claims_available', false);

        $this->actingAs($support, 'sanctum')
            ->postJson('/api/v1/admin/claims', [
                'customer_name' => 'X', 'description' => 'Y',
            ])
            ->assertStatus(503);

        // My Work must not 500 over a table that is not there yet.
        $this->actingAs($support, 'sanctum')
            ->getJson('/api/v1/admin/my-work')
            ->assertOk()
            ->assertJsonPath('meta.counts.claim_tasks', 0);
    }
}
