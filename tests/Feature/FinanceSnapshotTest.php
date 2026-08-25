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
        foreach (['finance_liquidity_entries', 'finance_snapshot_items', 'admin_security_events', 'admin_users'] as $t) {
            Schema::dropIfExists($t);
        }

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

        Schema::create('finance_snapshot_items', function (Blueprint $table) {
            $table->id();
            $table->string('category', 40);
            $table->string('person', 100);
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
        foreach (['finance_liquidity_entries', 'finance_snapshot_items', 'admin_security_events', 'admin_users'] as $t) {
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
