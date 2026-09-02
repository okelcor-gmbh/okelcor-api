<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\EcInvoiceLine;
use App\Models\FinanceLiquidityEntry;
use App\Models\FinanceSnapshotItem;
use App\Models\StaffActivity;
use App\Models\Todo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Finance's own work reaches the contribution ledger (Session 111).
 *
 * The ledger was built from the ORDER trail — order logs, trade documents,
 * sign-offs, invoices. Finance does most of its work beside that trail, on the
 * snapshot board, the ZM portal and the weekly liquidity file, none of which
 * was wired in. Production showed the consequence exactly: **both finance
 * accounts had 0 ledger rows** while 293 snapshot items sat in their table,
 * and the monthly report that goes to the boss said they had done nothing.
 */
class FinanceContributionLedgerTest extends TestCase
{
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::disableForeignKeyConstraints();

        foreach ([
            'staff_activities', 'todos', 'finance_liquidity_entries',
            'ec_invoice_lines', 'finance_snapshot_items', 'admin_users',
        ] as $t) {
            Schema::dropIfExists($t);
        }

        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('display_name')->nullable();
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role');
            $table->string('job_title', 60)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('staff_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_user_id')->nullable();
            $table->string('admin_name')->nullable();
            $table->string('admin_role', 40)->nullable();
            $table->string('admin_job_title', 60)->nullable();
            $table->string('category', 30);
            $table->string('action', 80);
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable();
            $table->string('source_type', 60);
            $table->unsignedBigInteger('source_id');
            $table->timestamp('occurred_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['source_type', 'source_id', 'action']);
        });

        Schema::create('finance_snapshot_items', function (Blueprint $table) {
            $table->id();
            $table->string('category', 60)->nullable();
            $table->string('person')->nullable();
            $table->unsignedBigInteger('assigned_admin_id')->nullable();
            $table->string('ref')->nullable();
            $table->date('date')->nullable();
            $table->string('client')->nullable();
            $table->string('status', 40)->nullable();
            $table->text('comment')->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('ec_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('group_id')->nullable();
            $table->string('invoice_number')->nullable();
            $table->date('invoice_date')->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->unsignedBigInteger('assigned_admin_id')->nullable();
            $table->string('person_name')->nullable();
            $table->string('task_status', 40)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('finance_liquidity_entries', function (Blueprint $table) {
            $table->id();
            $table->string('line', 60);
            $table->string('period', 30)->nullable();
            $table->string('week_key', 12)->nullable();
            $table->string('supplier')->nullable();
            $table->string('description')->nullable();
            $table->string('reference')->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('currency', 8)->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();
        });

        Schema::create('todos', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->text('details')->nullable();
            $table->text('assignee_note')->nullable();
            $table->date('due_on')->nullable();
            $table->string('priority', 10)->default('normal');
            $table->string('status', 20)->default('open');
            $table->unsignedBigInteger('assigned_admin_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_role', 30)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();

        StaffActivity::forgetLedgerCheck();
        Todo::forgetAvailableCheck();
        FinanceLiquidityEntry::forgetAttributionCheck();
    }

    protected function tearDown(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach ([
            'staff_activities', 'todos', 'finance_liquidity_entries',
            'ec_invoice_lines', 'finance_snapshot_items', 'admin_users',
        ] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::enableForeignKeyConstraints();

        FinanceLiquidityEntry::forgetAttributionCheck();
        Todo::forgetAvailableCheck();

        parent::tearDown();
    }

    private function admin(string $role = 'finance'): AdminUser
    {
        return AdminUser::create([
            'name'      => 'Staff ' . (++$this->seq),
            'email'     => 'fl' . $this->seq . uniqid() . '@okelcor.test',
            'role'      => $role,
            'password'  => Hash::make('secret-pass-123'),
            'is_active' => true,
        ]);
    }

    // ── the board finance actually works on ───────────────────────────────

    public function test_a_snapshot_record_credits_the_finance_user_who_raised_it(): void
    {
        // The 293-row case. This table is finance's daily working surface and
        // contributed nothing to their record.
        $joseph = $this->admin('finance');

        $item = FinanceSnapshotItem::create([
            'category'   => 'PENDING RECEIPTS',
            'client'     => 'Reifen Krieg',
            'status'     => 'Pending',
            'amount'     => -147.42,
            'created_by' => $joseph->id,
        ]);

        $row = StaffActivity::where('source_type', 'finance_snapshot_item')
            ->where('source_id', $item->id)
            ->firstOrFail();

        $this->assertSame($joseph->id, $row->admin_user_id);
        $this->assertSame('finance', $row->category);
        $this->assertSame('Reifen Krieg', $row->subject_label);
        // The name and role are stamped, not read live — the ledger's own
        // rule, so a month's record does not change when somebody's job does.
        $this->assertSame('finance', $row->admin_role);
    }

    public function test_an_ec_invoice_line_credits_its_creator(): void
    {
        $daniel = $this->admin('finance');

        $line = EcInvoiceLine::create([
            'invoice_number' => 'RE-2026-0042',
            'amount'         => 3100.00,
            'created_by'     => $daniel->id,
        ]);

        $row = StaffActivity::where('source_type', 'ec_invoice_line')
            ->where('source_id', $line->id)
            ->firstOrFail();

        $this->assertSame($daniel->id, $row->admin_user_id);
        $this->assertSame('finance', $row->category);
        $this->assertSame('RE-2026-0042', $row->subject_label);
    }

    public function test_a_liquidity_line_credits_its_author(): void
    {
        Schema::table('finance_liquidity_entries', function (Blueprint $t) {
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
        });
        FinanceLiquidityEntry::forgetAttributionCheck();

        $joseph = $this->admin('finance');

        $entry = FinanceLiquidityEntry::create([
            'line'        => 'bank_balance',
            'week_key'    => '2026-W36',
            'description' => 'Commerzbank opening',
            'amount'      => 10440.54,
            'created_by'  => $joseph->id,
        ]);

        $row = StaffActivity::where('source_type', 'finance_liquidity_entry')
            ->where('source_id', $entry->id)
            ->firstOrFail();

        $this->assertSame($joseph->id, $row->admin_user_id);
        $this->assertSame('finance', $row->category);
    }

    public function test_a_liquidity_line_still_saves_before_its_column_exists(): void
    {
        // Deploy-order safety. The file is finance's live working; a reporting
        // column must never be able to block a line being written.
        $this->assertFalse(FinanceLiquidityEntry::supportsAttribution());

        $entry = FinanceLiquidityEntry::create([
            'line' => 'bank_balance', 'week_key' => '2026-W36', 'amount' => 1.00,
        ]);

        $this->assertNotNull($entry->id);
        $this->assertSame(0, StaffActivity::where('source_type', 'finance_liquidity_entry')->count());
    }

    // ── the judgement that keeps the report honest ────────────────────────

    public function test_a_todo_credits_whoever_finished_it_not_whoever_asked(): void
    {
        $joseph   = $this->admin('finance');
        $solomon  = $this->admin('super_admin');

        $todo = Todo::create([
            'title'           => 'Share Invoice copy',
            'created_by'      => $joseph->id,
            'created_by_role' => 'finance',
            'assigned_admin_id' => $solomon->id,
        ]);

        // Asking for work is not doing it — nothing recorded yet.
        $this->assertSame(0, StaffActivity::where('source_type', 'todo')->count());

        $todo->update([
            'status'       => 'done',
            'completed_by' => $solomon->id,
            'completed_at' => now(),
        ]);

        $row = StaffActivity::where('source_type', 'todo')->where('source_id', $todo->id)->firstOrFail();

        $this->assertSame($solomon->id, $row->admin_user_id);
        $this->assertSame('todo_completed', $row->action);
        // Categorised by the department that RAISED it, so finishing finance's
        // errand reads as finance work wherever the assignee sits.
        $this->assertSame('finance', $row->category);
    }

    public function test_raising_ninety_one_duplicates_earns_nobody_anything(): void
    {
        // The report this feeds goes to the boss. On the day this was built,
        // one finance user had raised 91 near-identical to-dos in two hours
        // because a broken panel made him think they had not saved. Crediting
        // creation would have handed him 91 contributions for an accident and
        // made the report worse than the silence it replaced.
        $joseph = $this->admin('finance');

        for ($i = 0; $i < 91; $i++) {
            Todo::create([
                'title'           => 'Share Invoice copy',
                'created_by'      => $joseph->id,
                'created_by_role' => 'finance',
            ]);
        }

        $this->assertSame(91, Todo::count());
        $this->assertSame(0, StaffActivity::where('admin_user_id', $joseph->id)->count());
    }

    public function test_an_unfinished_todo_records_nothing_even_when_reopened(): void
    {
        $joseph = $this->admin('finance');
        $daniel = $this->admin('finance');

        $todo = Todo::create([
            'title' => 'Share Bank statement', 'created_by' => $joseph->id, 'created_by_role' => 'finance',
        ]);

        $todo->update(['status' => 'done', 'completed_by' => $daniel->id, 'completed_at' => now()]);
        $this->assertSame(1, StaffActivity::where('source_type', 'todo')->count());

        // Reopened: the completion stamp is cleared by the controller. The
        // ledger row already written stays — it is a record of something that
        // did happen — but nothing new is added.
        $todo->update(['status' => 'open', 'completed_by' => null, 'completed_at' => null]);

        $this->assertSame(1, StaffActivity::where('source_type', 'todo')->count());
    }

    // ── the rules the whole ledger rests on, on the new sources ───────────

    public function test_a_record_with_nobody_behind_it_is_not_credited(): void
    {
        // Rule 2. The liquidity file's 66 production rows arrived through an
        // import command; attributing them to whoever ran it would credit one
        // person with a spreadsheet somebody else built.
        FinanceSnapshotItem::create(['category' => 'PENDING RECEIPTS', 'created_by' => null]);
        EcInvoiceLine::create(['invoice_number' => 'RE-1', 'created_by' => null]);

        $this->assertSame(0, StaffActivity::count());
    }

    public function test_saving_the_same_record_twice_does_not_double_a_month(): void
    {
        // Rule 4. Finance edits a snapshot record repeatedly as a payment is
        // chased; each save must update the row, never add one.
        $joseph = $this->admin('finance');

        $item = FinanceSnapshotItem::create([
            'category' => 'PENDING RECEIPTS', 'client' => 'Reifen Krieg', 'created_by' => $joseph->id,
        ]);

        $item->update(['status' => 'Sent']);
        $item->update(['status' => 'Completed']);

        $this->assertSame(1, StaffActivity::where('source_type', 'finance_snapshot_item')->count());
    }

    public function test_the_ledger_never_fails_the_work_it_reports_on(): void
    {
        // Rule 3. With the ledger table gone, finance must still be able to
        // raise a record — reporting is downstream of the work, never in
        // front of it.
        Schema::dropIfExists('staff_activities');
        StaffActivity::forgetLedgerCheck();

        $joseph = $this->admin('finance');

        $item = FinanceSnapshotItem::create([
            'category' => 'PENDING RECEIPTS', 'created_by' => $joseph->id,
        ]);

        $this->assertNotNull($item->id);
    }
}
