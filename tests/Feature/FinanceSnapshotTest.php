<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\FinanceLiquidityEntry;
use App\Models\FinanceSnapshotItem;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The finance snapshot board — the shared replacement for the localStorage
 * D13.html tracker. The import test uses the exact export shape that board
 * produces, because restoring finance's real backup is the migration path.
 */
class FinanceSnapshotTest extends TestCase
{
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::disableForeignKeyConstraints();
        foreach (['finance_liquidity_entries', 'finance_snapshot_items', 'admin_notifications', 'admin_security_events', 'quote_requests', 'admin_users'] as $t) {
            Schema::dropIfExists($t);
        }

        // Minimal quote_requests: the My Work endpoint reads it for the CRM
        // groups alongside the finance tasks under test here.
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

        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role');
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
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('finance_snapshot_items', function (Blueprint $table) {
            $table->id();
            $table->string('category', 40);
            $table->string('person', 100);
            $table->foreignId('assigned_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('ref', 50);
            $table->date('date')->nullable();
            $table->string('client', 255)->nullable();
            $table->string('status', 30)->default('Pending');
            $table->string('comment', 500)->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('finance_liquidity_entries', function (Blueprint $table) {
            $table->id();
            $table->string('line', 40);
            $table->string('period', 20);
            $table->string('description', 255);
            $table->string('reference', 100)->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();
    }

    protected function tearDown(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (['finance_liquidity_entries', 'finance_snapshot_items', 'admin_notifications', 'admin_security_events', 'quote_requests', 'admin_users'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::enableForeignKeyConstraints();

        parent::tearDown();
    }

    private function admin(string $role = 'finance'): AdminUser
    {
        return AdminUser::create([
            'name'                    => 'Staff ' . (++$this->seq),
            'email'                   => 'fin' . $this->seq . uniqid() . '@okelcor.test',
            'role'                    => $role,
            'password'                => Hash::make('secret-pass-123'),
            'is_active'               => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    public function test_finance_can_create_and_read_board_items(): void
    {
        $finance = $this->admin('finance');

        $this->actingAs($finance, 'sanctum')
            ->postJson('/api/v1/admin/finance-snapshot/items', [
                'category' => 'OPEN PROPOSALS',
                'person'   => 'Edinah',
                'ref'      => 'AN-1298',
                'date'     => '2026-08-03',
                'client'   => 'NIOS GROUP CORP, Atlanta',
                'status'   => 'Pending',
                'comment'  => 'Edinah to give status',
                'amount'   => 20712.00,
            ])
            ->assertCreated()
            ->assertJsonPath('data.person', 'Edinah')
            ->assertJsonPath('data.amount', 20712);

        $this->actingAs($finance, 'sanctum')
            ->getJson('/api/v1/admin/finance-snapshot')
            ->assertOk()
            ->assertJsonPath('data.items.0.ref', 'AN-1298')
            ->assertJsonPath('data.meta.categories.0', 'OPEN PROPOSALS');
    }

    public function test_order_manager_can_read_but_not_write(): void
    {
        $om = $this->admin('order_manager');

        $this->actingAs($om, 'sanctum')
            ->getJson('/api/v1/admin/finance-snapshot')
            ->assertOk();

        $this->actingAs($om, 'sanctum')
            ->postJson('/api/v1/admin/finance-snapshot/items', [
                'category' => 'OPEN ORDERS', 'person' => 'X', 'ref' => 'R1', 'amount' => 1,
            ])
            ->assertForbidden();
    }

    public function test_roles_outside_finance_cannot_read(): void
    {
        $this->actingAs($this->admin('marketing'), 'sanctum')
            ->getJson('/api/v1/admin/finance-snapshot')
            ->assertForbidden();
    }

    public function test_an_unknown_category_is_refused_and_an_odd_status_is_normalized(): void
    {
        $finance = $this->admin('finance');

        $this->actingAs($finance, 'sanctum')
            ->postJson('/api/v1/admin/finance-snapshot/items', [
                'category' => 'NOT A BOX', 'person' => 'X', 'ref' => 'R1', 'amount' => 1,
            ])
            ->assertStatus(422);

        $this->actingAs($finance, 'sanctum')
            ->postJson('/api/v1/admin/finance-snapshot/items', [
                'category' => 'OPEN ORDERS', 'person' => 'X', 'ref' => 'R2',
                'status'   => 'waiting on Godot', 'amount' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'Pending');
    }

    public function test_liquidity_entries_crud(): void
    {
        $finance = $this->admin('finance');

        $created = $this->actingAs($finance, 'sanctum')
            ->postJson('/api/v1/admin/finance-snapshot/liquidity', [
                'line'        => 'rent',
                'period'      => 'next_month',
                'description' => 'Warehouse & Office Rent',
                'reference'   => 'LEASE-2026',
                'amount'      => -8284.00,
            ])
            ->assertCreated()
            ->json('data');

        $this->actingAs($finance, 'sanctum')
            ->putJson("/api/v1/admin/finance-snapshot/liquidity/{$created['id']}", [
                'line' => 'rent', 'period' => 'next_month',
                'description' => 'Warehouse rent (renegotiated)', 'amount' => -8000,
            ])
            ->assertOk()
            ->assertJsonPath('data.amount', -8000);

        $this->actingAs($finance, 'sanctum')
            ->deleteJson("/api/v1/admin/finance-snapshot/liquidity/{$created['id']}")
            ->assertOk();

        $this->assertSame(0, FinanceLiquidityEntry::count());
    }

    public function test_restoring_a_d13_backup_replaces_the_board(): void
    {
        FinanceSnapshotItem::create([
            'category' => 'OPEN ORDERS', 'person' => 'Old', 'ref' => 'OLD-1', 'amount' => 5,
        ]);

        // The exact export shape D13.html produces.
        $backup = [
            'items' => [
                ['id' => 2, 'category' => 'OPEN PROPOSALS', 'person' => 'Edinah', 'ref' => 'AN-1298',
                 'date' => '2026-04-30', 'client' => 'NIOS GROUP CORP', 'status' => 'Pending',
                 'comment' => 'Client Say wait', 'amount' => 20712],
                ['id' => 9, 'category' => 'PENDING RECEIPTS', 'person' => 'Lisa', 'ref' => 'RE-0042',
                 'date' => '2026-06-04', 'client' => 'Muscali Tyres', 'status' => 'In Progress',
                 'comment' => '', 'amount' => 20319.9],
            ],
            'liquidityItems' => [
                ['id' => 'bank_balance', 'label' => 'Bank Balance',
                 'openCurrent' => [['id' => 101, 'desc' => 'Primary Operating Account', 'ref' => 'BNK-01', 'amount' => 26219.60]],
                 'nextMonth'   => []],
                ['id' => 'salaries', 'label' => 'Salaries',
                 'openCurrent' => [['id' => 401, 'desc' => 'Net Staff Payroll', 'ref' => 'PAY-AUG', 'amount' => -16993.00]],
                 'nextMonth'   => [['id' => 402, 'desc' => 'September payroll', 'ref' => 'PAY-SEP', 'amount' => -17000.00]]],
            ],
        ];

        $this->actingAs($this->admin('finance'), 'sanctum')
            ->postJson('/api/v1/admin/finance-snapshot/import', $backup)
            ->assertOk();

        $this->assertSame(2, FinanceSnapshotItem::count());
        $this->assertDatabaseMissing('finance_snapshot_items', ['ref' => 'OLD-1']);
        $this->assertDatabaseHas('finance_snapshot_items', ['ref' => 'AN-1298', 'person' => 'Edinah']);

        $this->assertSame(3, FinanceLiquidityEntry::count());
        $this->assertDatabaseHas('finance_liquidity_entries', [
            'line' => 'salaries', 'period' => 'next_month', 'reference' => 'PAY-SEP',
        ]);
    }

    // -------------------------------------------------------------------------
    // Staff tagging, My Work, and reminders
    // -------------------------------------------------------------------------

    public function test_tagging_a_staff_member_notifies_them(): void
    {
        $finance = $this->admin('finance');
        $edinah  = $this->admin('marketing');

        $this->actingAs($finance, 'sanctum')
            ->postJson('/api/v1/admin/finance-snapshot/items', [
                'category' => 'OPEN PROPOSALS', 'person' => 'Edinah', 'ref' => 'AN-1298',
                'assigned_admin_id' => $edinah->id, 'client' => 'NIOS GROUP', 'amount' => 20712,
            ])
            ->assertCreated()
            ->assertJsonPath('data.assigned_admin_id', $edinah->id);

        $this->assertDatabaseHas('admin_notifications', [
            'admin_user_id' => $edinah->id,
            'type'          => 'finance_task_assigned',
            'related_type'  => 'finance_snapshot_item',
        ]);
    }

    public function test_self_assignment_and_plain_edits_do_not_notify(): void
    {
        $finance = $this->admin('finance');
        $edinah  = $this->admin('marketing');

        // Assigning to yourself: silence.
        $self = $this->actingAs($finance, 'sanctum')
            ->postJson('/api/v1/admin/finance-snapshot/items', [
                'category' => 'OPEN ORDERS', 'person' => 'Me', 'ref' => 'SELF-1',
                'assigned_admin_id' => $finance->id, 'amount' => 1,
            ])->assertCreated()->json('data');

        // Editing an amount on an already-assigned record: silence.
        $item = $this->actingAs($finance, 'sanctum')
            ->postJson('/api/v1/admin/finance-snapshot/items', [
                'category' => 'OPEN ORDERS', 'person' => 'Edinah', 'ref' => 'EDIT-1',
                'assigned_admin_id' => $edinah->id, 'amount' => 1,
            ])->assertCreated()->json('data');

        $before = \DB::table('admin_notifications')->where('admin_user_id', $edinah->id)->count();

        $this->actingAs($finance, 'sanctum')
            ->putJson("/api/v1/admin/finance-snapshot/items/{$item['id']}", [
                'category' => 'OPEN ORDERS', 'person' => 'Edinah', 'ref' => 'EDIT-1',
                'assigned_admin_id' => $edinah->id, 'amount' => 999,
            ])->assertOk();

        $this->assertSame($before, \DB::table('admin_notifications')->where('admin_user_id', $edinah->id)->count());
        $this->assertSame(0, \DB::table('admin_notifications')->where('admin_user_id', $finance->id)->count());
        $this->assertSame('SELF-1', $self['ref']);
    }

    public function test_my_work_lists_tagged_finance_records_for_the_assignee_only(): void
    {
        $finance = $this->admin('finance');
        $edinah  = $this->admin('marketing');

        $this->actingAs($finance, 'sanctum')->postJson('/api/v1/admin/finance-snapshot/items', [
            'category' => 'OUTSTANDING INVOICES', 'person' => 'Edinah', 'ref' => 'INV-9',
            'assigned_admin_id' => $edinah->id, 'client' => 'Muscali Tyres', 'amount' => 500,
        ])->assertCreated();

        $this->actingAs($edinah, 'sanctum')
            ->getJson('/api/v1/admin/my-work')
            ->assertOk()
            ->assertJsonPath('meta.counts.finance_tasks', 1)
            ->assertJsonPath('data.finance_tasks.0.type', 'finance_task')
            ->assertJsonPath('data.finance_tasks.0.editable', true);

        $this->actingAs($finance, 'sanctum')
            ->getJson('/api/v1/admin/my-work')
            ->assertOk()
            ->assertJsonPath('meta.counts.finance_tasks', 0);
    }

    public function test_the_assignee_can_update_status_from_my_work_and_the_creator_hears_back(): void
    {
        $finance = $this->admin('finance');
        $edinah  = $this->admin('marketing');
        $other   = $this->admin('viewer');

        $item = $this->actingAs($finance, 'sanctum')->postJson('/api/v1/admin/finance-snapshot/items', [
            'category' => 'OPEN PROPOSALS', 'person' => 'Edinah', 'ref' => 'AN-1333',
            'assigned_admin_id' => $edinah->id, 'amount' => 20000,
        ])->json('data');

        // A bystander may not touch it.
        $this->actingAs($other, 'sanctum')
            ->patchJson("/api/v1/admin/my-work/finance-items/{$item['id']}", ['status' => 'Completed'])
            ->assertForbidden();

        // The assignee may, without finance.manage.
        $this->actingAs($edinah, 'sanctum')
            ->patchJson("/api/v1/admin/my-work/finance-items/{$item['id']}", [
                'status' => 'In Progress', 'comment' => 'Client reviewing, call booked Friday',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'In Progress');

        $this->assertDatabaseHas('admin_notifications', [
            'admin_user_id' => $finance->id,
            'type'          => 'finance_task_updated',
        ]);
    }

    public function test_due_and_overdue_tasks_are_reminded_once_per_day(): void
    {
        $edinah = $this->admin('marketing');

        FinanceSnapshotItem::create([
            'category' => 'PENDING RECEIPTS', 'person' => 'Edinah', 'ref' => 'RE-7',
            'assigned_admin_id' => $edinah->id, 'date' => now()->subDays(3)->toDateString(),
            'status' => 'Pending', 'amount' => 750,
        ]);
        // Closed and future items must stay silent.
        FinanceSnapshotItem::create([
            'category' => 'PENDING RECEIPTS', 'person' => 'Edinah', 'ref' => 'RE-8',
            'assigned_admin_id' => $edinah->id, 'date' => now()->subDay()->toDateString(),
            'status' => 'Completed', 'amount' => 10,
        ]);
        FinanceSnapshotItem::create([
            'category' => 'PENDING RECEIPTS', 'person' => 'Edinah', 'ref' => 'RE-9',
            'assigned_admin_id' => $edinah->id, 'date' => now()->addWeek()->toDateString(),
            'status' => 'Pending', 'amount' => 10,
        ]);

        $this->artisan('finance:remind-assignees')->assertSuccessful();
        $this->artisan('finance:remind-assignees')->assertSuccessful();   // same day: deduped

        $reminders = \DB::table('admin_notifications')
            ->where('admin_user_id', $edinah->id)
            ->where('type', 'finance_task_reminder')
            ->get();

        $this->assertCount(1, $reminders);
        $this->assertStringContainsString('RE-7', $reminders[0]->title);
        $this->assertStringContainsString('overdue', $reminders[0]->title);
    }

    public function test_restore_reads_european_dates_day_first_and_salvages_bad_ones(): void
    {
        // The real backup mixes ISO dates with DD/MM/YYYY. 30/12/2024 used to
        // fail validation outright; 05/02/2026 was worse — PHP would silently
        // read it month-first as the 2nd of May.
        $backup = [
            'items' => [
                ['category' => 'PENDING RECEIPTS', 'person' => 'A', 'ref' => 'D-1', 'date' => '30/12/2024', 'amount' => 1],
                ['category' => 'PENDING RECEIPTS', 'person' => 'A', 'ref' => 'D-2', 'date' => '05/02/2026', 'amount' => 1],
                ['category' => 'PENDING RECEIPTS', 'person' => 'A', 'ref' => 'D-3', 'date' => '2026-08-19', 'amount' => 1],
                ['category' => 'PENDING RECEIPTS', 'person' => 'A', 'ref' => 'D-4', 'date' => '13-Aug-2026', 'amount' => 1],
                ['category' => 'PENDING RECEIPTS', 'person' => 'A', 'ref' => 'D-5', 'date' => 'no idea', 'amount' => 1],
                ['category' => 'PENDING RECEIPTS', 'person' => 'A', 'ref' => 'D-6', 'date' => '', 'amount' => 1],
            ],
            'liquidityItems' => [],
        ];

        $this->actingAs($this->admin('finance'), 'sanctum')
            ->postJson('/api/v1/admin/finance-snapshot/import', $backup)
            ->assertOk();

        $dates = FinanceSnapshotItem::orderBy('ref')->pluck('date', 'ref')
            ->map(fn ($d) => $d?->format('Y-m-d'));

        $this->assertSame('2024-12-30', $dates['D-1']);
        $this->assertSame('2026-02-05', $dates['D-2'], 'slash dates must be read day-first');
        $this->assertSame('2026-08-19', $dates['D-3']);
        $this->assertSame('2026-08-13', $dates['D-4']);
        $this->assertNull($dates['D-5'], 'an unreadable date costs the date, not the restore');
        $this->assertNull($dates['D-6']);
    }

    public function test_bulk_item_upload(): void
    {
        $this->actingAs($this->admin('finance'), 'sanctum')
            ->postJson('/api/v1/admin/finance-snapshot/items/bulk', [
                'items' => [
                    ['category' => 'OUTSTANDING INVOICES', 'person' => 'Edinah', 'ref' => 'INV-1', 'amount' => 100],
                    ['category' => 'OUTSTANDING INVOICES', 'person' => 'Lisa', 'ref' => 'INV-2', 'amount' => 200.5],
                ],
            ])
            ->assertCreated();

        $this->assertSame(2, FinanceSnapshotItem::count());
    }
}
