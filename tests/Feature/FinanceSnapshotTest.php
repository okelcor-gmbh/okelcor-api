<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\FinanceLiquidityEntry;
use App\Models\FinanceSnapshotItem;
use App\Support\AdminPermissions;
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
            $table->string('week_key', 10)->nullable();
            $table->string('supplier', 150)->nullable();
            $table->string('description', 255);
            $table->string('reference', 100)->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('currency', 3)->nullable();
            $table->string('comment', 255)->nullable();
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

    /**
     * The board is closed to everyone but finance and super_admin — the
     * business's instruction, and the reason it has its own permission key
     * instead of riding on finance.view. `admin` and `order_manager` are
     * named individually because both DO still hold finance.view and would
     * be in had this ridden on it; that is exactly the regression this
     * guards. Replaces an earlier test asserting the order manager could
     * read: its name had become a lie.
     */
    public function test_only_finance_and_super_admin_may_open_the_board(): void
    {
        foreach (['super_admin', 'finance'] as $role) {
            $this->actingAs($this->admin($role), 'sanctum')
                ->getJson('/api/v1/admin/finance-snapshot')
                ->assertOk();
        }

        $shutOut = array_values(array_diff(
            AdminPermissions::ROLES,
            ['super_admin', 'finance'],
        ));

        // Nothing here should ever be empty — an accidentally empty list
        // would make this whole assertion pass by testing nothing.
        $this->assertNotEmpty($shutOut);
        $this->assertContains('admin', $shutOut);
        $this->assertContains('order_manager', $shutOut);

        foreach ($shutOut as $role) {
            $user = $this->admin($role);

            $this->actingAs($user, 'sanctum')
                ->getJson('/api/v1/admin/finance-snapshot')
                ->assertForbidden();

            $this->actingAs($user, 'sanctum')
                ->postJson('/api/v1/admin/finance-snapshot/items', [
                    'category' => 'OPEN ORDERS', 'person' => 'X', 'ref' => 'R1', 'amount' => 1,
                ])
                ->assertForbidden();
        }
    }

    /**
     * Narrowing the board must not have narrowed the finance work the order
     * manager and `admin` were deliberately given in Sessions 83 and 99 —
     * they still hold finance.view, which is a different key.
     */
    public function test_closing_the_board_left_the_wider_finance_permissions_alone(): void
    {
        foreach (['admin', 'order_manager'] as $role) {
            $this->assertTrue(
                AdminPermissions::can($role, 'finance.view'),
                "{$role} should still hold finance.view",
            );
            $this->assertFalse(
                AdminPermissions::can($role, 'finance.snapshot'),
                "{$role} should not hold finance.snapshot",
            );
        }

        $this->assertTrue(AdminPermissions::can('admin', 'finance.manage'));
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

    /**
     * The weekly model from finance's "Liquidity File V1" (Session 105):
     * an entry lives in an ISO week and carries supplier / currency /
     * comment, and the grid's category list + arithmetic are SERVED.
     */
    public function test_weekly_liquidity_entries_round_trip(): void
    {
        $finance = $this->admin('finance');
        // The current week — a static key here would CLOSE the moment the
        // calendar moved past it, and this test would start failing for a
        // reason that has nothing to do with what it asserts.
        $week = FinanceLiquidityEntry::currentWeekKey();

        $created = $this->actingAs($finance, 'sanctum')
            ->postJson('/api/v1/admin/finance-snapshot/liquidity', [
                'line'     => 'cost_of_sales',
                'week_key' => $week,
                'supplier' => 'ELTE International B.V',
                'description' => 'ANTWERP - TEMA — KKFU7958920',
                'amount'   => -7787.50,
                'currency' => 'eur',
                'comment'  => 'To Get Invoice',
            ])
            ->assertCreated()
            ->assertJsonPath('data.week_key', $week)
            ->assertJsonPath('data.supplier', 'ELTE International B.V')
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.comment', 'To Get Invoice')
            ->json('data');

        // No week AND no period is nowhere — refused.
        $this->actingAs($finance, 'sanctum')
            ->postJson('/api/v1/admin/finance-snapshot/liquidity', [
                'line' => 'rent', 'amount' => -100,
            ])
            ->assertStatus(422);

        // A malformed week key is refused, not stored as a stray bucket.
        $this->actingAs($finance, 'sanctum')
            ->postJson('/api/v1/admin/finance-snapshot/liquidity', [
                'line' => 'rent', 'week_key' => 'Week 36', 'amount' => -100,
            ])
            ->assertStatus(422);

        // The board serves the file's category list and the grid arithmetic.
        $meta = $this->actingAs($finance, 'sanctum')
            ->getJson('/api/v1/admin/finance-snapshot')
            ->assertOk()
            ->assertJsonPath('data.liquidity.0.week_key', $week)
            ->json('data.meta');

        $lineKeys = array_column($meta['liquidity_lines'], 'key');
        $this->assertContains('it_expenses', $lineKeys);
        $this->assertContains('internet_phone', $lineKeys);
        $this->assertContains('cost_of_sales', $meta['liquidity_expense_lines']);
        $this->assertNotContains('bank_balance', $meta['liquidity_expense_lines']);
        $this->assertNotContains('revenue_payment', $meta['liquidity_expense_lines']);

        $this->actingAs($finance, 'sanctum')
            ->deleteJson("/api/v1/admin/finance-snapshot/liquidity/{$created['id']}")
            ->assertOk();
    }

    /**
     * A week that has ended is CLOSED (Session 106): no new entries, no
     * in-place edits, no deletes. The one thing that still works is moving
     * a record FORWARD into an open week — rolling an unpaid item into the
     * week ahead is exactly what finance does when a week ends, and the
     * delete-and-retype workaround was the complaint.
     */
    public function test_closed_weeks_refuse_writes_but_a_record_moves_forward(): void
    {
        $finance  = $this->admin('finance');
        $lastWeek = now()->subWeek()->format('o-\WW');
        $thisWeek = FinanceLiquidityEntry::currentWeekKey();

        // History: a record that landed while its week was still open.
        $old = FinanceLiquidityEntry::create([
            'line' => 'revenue_payment', 'period' => '', 'week_key' => $lastWeek,
            'supplier' => 'TyreFlexx', 'description' => 'AB-1182 2nd 50%',
            'amount' => 13000, 'currency' => 'EUR',
        ]);

        // No new entries into a closed week.
        $this->actingAs($finance, 'sanctum')
            ->postJson('/api/v1/admin/finance-snapshot/liquidity', [
                'line' => 'rent', 'week_key' => $lastWeek, 'amount' => -100,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.week_key.0', 'This week is closed.');

        // No editing in place — the write would land in the closed week.
        $this->actingAs($finance, 'sanctum')
            ->putJson("/api/v1/admin/finance-snapshot/liquidity/{$old->id}", [
                'line' => 'revenue_payment', 'week_key' => $lastWeek, 'amount' => 14000,
            ])
            ->assertStatus(422);

        // No deleting — a closed week keeps its records.
        $this->actingAs($finance, 'sanctum')
            ->deleteJson("/api/v1/admin/finance-snapshot/liquidity/{$old->id}")
            ->assertStatus(422);

        // But the unpaid item rolls forward, and can be corrected as it
        // moves — the write lands in an open week.
        $this->actingAs($finance, 'sanctum')
            ->putJson("/api/v1/admin/finance-snapshot/liquidity/{$old->id}", [
                'line' => 'revenue_payment', 'week_key' => $thisWeek,
                'supplier' => 'TyreFlexx', 'description' => 'AB-1182 2nd 50% — rolled from last week',
                'amount' => 13000, 'currency' => 'EUR',
            ])
            ->assertOk()
            ->assertJsonPath('data.week_key', $thisWeek);

        // Once in an open week, it is an ordinary record again.
        $this->actingAs($finance, 'sanctum')
            ->deleteJson("/api/v1/admin/finance-snapshot/liquidity/{$old->id}")
            ->assertOk();

        // The panel closes columns off the server's clock, which is served.
        $this->actingAs($finance, 'sanctum')
            ->getJson('/api/v1/admin/finance-snapshot')
            ->assertOk()
            ->assertJsonPath('data.meta.current_week', $thisWeek);
    }

    public function test_the_csv_exports_carry_the_data_and_the_liquidity_one_round_trips(): void
    {
        $finance  = $this->admin('finance');
        $thisWeek = FinanceLiquidityEntry::currentWeekKey();

        $this->actingAs($finance, 'sanctum')->postJson('/api/v1/admin/finance-snapshot/items', [
            'category' => 'OPEN PROPOSALS', 'person' => 'Edinah', 'ref' => 'AN-1298',
            'client' => 'Müller Reifen GmbH', 'amount' => 20712,
        ])->assertCreated();

        $this->actingAs($finance, 'sanctum')->postJson('/api/v1/admin/finance-snapshot/liquidity', [
            'line' => 'cost_of_sales', 'week_key' => $thisWeek,
            'supplier' => 'ELTE International B.V', 'amount' => -7787.50, 'comment' => 'To Get Invoice',
        ])->assertCreated();

        // The pipeline export: BOM (Excel), header, the row with its umlaut.
        $items = $this->actingAs($finance, 'sanctum')
            ->get('/api/v1/admin/finance-snapshot/export')
            ->assertOk()
            ->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $items);
        $this->assertStringContainsString('Category,Person,Assignee,Ref', $items);
        $this->assertStringContainsString('Müller Reifen GmbH', $items);
        $this->assertStringContainsString('20712.00', $items);

        // The liquidity export is written in the import's OWN column layout,
        // so a downloaded file is also a restorable one. Proved by feeding
        // it straight back through liquidity:import.
        $csv = $this->actingAs($finance, 'sanctum')
            ->get('/api/v1/admin/finance-snapshot/liquidity/export')
            ->assertOk()
            ->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Item,Supplier,Description,Week,Currency,Amount,Comment', $csv);
        $this->assertStringContainsString('"Cost of sales","ELTE International B.V"', $csv);

        $path = tempnam(sys_get_temp_dir(), 'liqx') . '.csv';
        file_put_contents($path, $csv);
        $this->artisan('liquidity:import', [
            'file' => $path, '--fix' => true, '--replace' => true,
            '--year' => (int) substr($thisWeek, 0, 4),
        ])->assertExitCode(0);
        unlink($path);

        $this->assertSame(1, FinanceLiquidityEntry::count());
        $this->assertDatabaseHas('finance_liquidity_entries', [
            'line' => 'cost_of_sales', 'week_key' => $thisWeek, 'supplier' => 'ELTE International B.V',
        ]);

        // Both downloads are board-permission territory, like the board.
        $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->get('/api/v1/admin/finance-snapshot/liquidity/export')
            ->assertForbidden();
    }

    public function test_the_weekly_columns_migration_applies_against_real_sql_and_is_idempotent(): void
    {
        // From the pre-migration state: the base table without the columns.
        Schema::dropIfExists('finance_liquidity_entries');
        Schema::create('finance_liquidity_entries', function (Blueprint $table) {
            $table->id();
            $table->string('line', 40);
            $table->string('period', 20);
            $table->string('description', 255);
            $table->string('reference', 100)->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamps();
        });

        $migration = require base_path('database/migrations/2026_08_31_000001_add_weekly_fields_to_finance_liquidity_entries.php');
        $migration->up();
        // Idempotent — every column guarded.
        $migration->up();

        foreach (['week_key', 'supplier', 'currency', 'comment'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('finance_liquidity_entries', $column),
                "finance_liquidity_entries.{$column} is missing",
            );
        }
    }

    public function test_the_liquidity_file_import_surveys_then_writes_then_replaces(): void
    {
        $csv = implode("\n", [
            'Item,Supplier,Description,Week,Currency,Amount,Comment',
            'Bank Balance,Wise EUR,Bank Balance,Week 35,EUR,9380.38,',
            'Salaries,Solomon,,Week 35,EUR,-6000,',
            'IT Expenses,Google Ireland Limited,,Week 37,EUR,-500,',
            'Revenue Payment,TyreFlexx,Order AB-1182 Paid 2nd 50%,Week 40,Euro,13000,To Pay on 30-Sep-2026',
        ]);
        $path = tempnam(sys_get_temp_dir(), 'liq') . '.csv';
        file_put_contents($path, $csv);

        // Survey writes nothing.
        $this->artisan('liquidity:import', ['file' => $path, '--year' => 2026])
            ->assertExitCode(0);
        $this->assertSame(0, FinanceLiquidityEntry::count());

        // --fix writes, mapping labels to keys and weeks to ISO keys.
        $this->artisan('liquidity:import', ['file' => $path, '--fix' => true, '--year' => 2026])
            ->assertExitCode(0);
        $this->assertSame(4, FinanceLiquidityEntry::count());
        $this->assertDatabaseHas('finance_liquidity_entries', [
            'line' => 'it_expenses', 'week_key' => '2026-W37', 'supplier' => 'Google Ireland Limited',
        ]);
        $this->assertDatabaseHas('finance_liquidity_entries', [
            'line' => 'revenue_payment', 'week_key' => '2026-W40', 'currency' => 'EUR',
            'comment' => 'To Pay on 30-Sep-2026',
        ]);

        // --replace supersedes rather than doubles.
        $this->artisan('liquidity:import', ['file' => $path, '--fix' => true, '--replace' => true, '--year' => 2026])
            ->assertExitCode(0);
        $this->assertSame(4, FinanceLiquidityEntry::count());

        // An unknown item refuses the whole import — a figure filed under
        // the wrong category is worse than one that is absent.
        file_put_contents($path, $csv . "\nPetty Cash,Someone,,Week 35,EUR,-50,");
        $this->artisan('liquidity:import', ['file' => $path, '--fix' => true, '--replace' => true, '--year' => 2026])
            ->assertExitCode(1);
        $this->assertSame(4, FinanceLiquidityEntry::count());

        unlink($path);
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

    public function test_tagging_notifies_once_per_batch_not_once_per_record(): void
    {
        $finance = $this->admin('finance');
        $edinah  = $this->admin('marketing');

        // Finance tags three records in one sitting…
        foreach (['AN-1298', 'AN-1327', 'AN-1333'] as $ref) {
            $this->actingAs($finance, 'sanctum')
                ->postJson('/api/v1/admin/finance-snapshot/items', [
                    'category' => 'OPEN PROPOSALS', 'person' => 'Edinah', 'ref' => $ref,
                    'assigned_admin_id' => $edinah->id, 'amount' => 100,
                ])
                ->assertCreated();
        }

        // …and Edinah gets ONE nudge, not three.
        $this->assertSame(1, \DB::table('admin_notifications')
            ->where('admin_user_id', $edinah->id)
            ->where('type', 'finance_task_assigned')
            ->count());
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

    /**
     * Opening a tagged task lands on the task, not on the whole board.
     *
     * The assignee here is an order manager — someone who can no longer open
     * the board at all — so a link to it would be a 403 dressed up as a call
     * to action. She gets the My Work deep link, the status list she needs to
     * answer with, and no board link. Finance, who can open it, gets one.
     */
    public function test_a_tagged_task_opens_the_task_and_not_the_whole_board(): void
    {
        $finance = $this->admin('finance');
        $edinah  = $this->admin('order_manager');

        $item = $this->actingAs($finance, 'sanctum')->postJson('/api/v1/admin/finance-snapshot/items', [
            'category' => 'PENDING RECEIPTS', 'person' => 'Edinah', 'ref' => 'RC-77',
            'assigned_admin_id' => $edinah->id, 'amount' => 900,
        ])->json('data');

        $task = $this->actingAs($edinah, 'sanctum')
            ->getJson('/api/v1/admin/my-work')
            ->assertOk()
            ->assertJsonPath('data.finance_tasks.0.action_url', '/admin/my-work?finance_item=' . $item['id'])
            ->assertJsonPath('data.finance_tasks.0.board_url', null)
            ->assertJsonPath('data.finance_tasks.0.editable', true)
            ->json('data.finance_tasks.0');

        // The status select is served, not held as a second copy in the panel.
        $this->assertSame(
            FinanceSnapshotItem::STATUSES,
            array_column($task['status_options'], 'value'),
        );

        // ...and she can actually answer with one of them, holding no
        // finance permission whatsoever.
        $this->assertFalse(AdminPermissions::can('order_manager', 'finance.snapshot'));
        $this->actingAs($edinah, 'sanctum')
            ->patchJson("/api/v1/admin/my-work/finance-items/{$item['id']}", ['status' => 'Under Review'])
            ->assertOk()
            ->assertJsonPath('data.status', 'Under Review');

        // Finance tags itself on a second record and does get the board link.
        $mine = $this->actingAs($finance, 'sanctum')->postJson('/api/v1/admin/finance-snapshot/items', [
            'category' => 'PENDING RECEIPTS', 'person' => 'Fin', 'ref' => 'RC-78',
            'assigned_admin_id' => $finance->id, 'amount' => 10,
        ])->json('data');

        $this->actingAs($finance, 'sanctum')
            ->getJson('/api/v1/admin/my-work')
            ->assertOk()
            ->assertJsonPath('data.finance_tasks.0.action_url', '/admin/my-work?finance_item=' . $mine['id'])
            ->assertJsonPath('data.finance_tasks.0.board_url', '/admin/finance-snapshot?item=' . $mine['id']);
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

    public function test_the_daily_digest_is_one_report_per_person_with_one_email(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        $edinah = $this->admin('marketing');
        $lisa   = $this->admin('viewer');

        // Edinah: two open (one overdue), one closed (must not appear).
        FinanceSnapshotItem::create([
            'category' => 'PENDING RECEIPTS', 'person' => 'Edinah', 'ref' => 'RE-7',
            'assigned_admin_id' => $edinah->id, 'date' => now()->subDays(3)->toDateString(),
            'status' => 'Pending', 'amount' => 750,
        ]);
        FinanceSnapshotItem::create([
            'category' => 'OPEN PROPOSALS', 'person' => 'Edinah', 'ref' => 'AN-2',
            'assigned_admin_id' => $edinah->id, 'date' => now()->addWeek()->toDateString(),
            'status' => 'Pending', 'amount' => 100,
        ]);
        FinanceSnapshotItem::create([
            'category' => 'PENDING RECEIPTS', 'person' => 'Edinah', 'ref' => 'RE-8',
            'assigned_admin_id' => $edinah->id, 'date' => now()->subDay()->toDateString(),
            'status' => 'Completed', 'amount' => 10,
        ]);
        // Lisa: one open.
        FinanceSnapshotItem::create([
            'category' => 'OPEN ORDERS', 'person' => 'Lisa', 'ref' => 'ORD-1',
            'assigned_admin_id' => $lisa->id, 'status' => 'Sent', 'amount' => 40,
        ]);

        $this->artisan('finance:remind-assignees')->assertSuccessful();
        $this->artisan('finance:remind-assignees')->assertSuccessful();   // same day: deduped

        // One panel notification per person — a report, not one per record.
        $digests = \DB::table('admin_notifications')->where('type', 'finance_task_digest')->get();
        $this->assertCount(2, $digests);

        $edinahDigest = $digests->firstWhere('admin_user_id', $edinah->id);
        $this->assertStringContainsString('2 open', $edinahDigest->title);
        $this->assertStringContainsString('1 overdue', $edinahDigest->title);
        $this->assertStringNotContainsString('RE-8', (string) $edinahDigest->body);

        // And one email per person, listing everything, never re-sent same day.
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\FinanceTaskDigest::class, 2);
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\FinanceTaskDigest::class, function ($mail) use ($edinah) {
            return $mail->hasTo($edinah->email)
                && count($mail->tasks) === 2
                && $mail->summary['overdue'] === 1;
        });
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
