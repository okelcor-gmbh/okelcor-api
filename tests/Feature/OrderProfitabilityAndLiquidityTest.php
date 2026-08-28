<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\LiquidityWeek;
use App\Models\Order;
use App\Models\OrderCostLine;
use App\Models\OrderFinanceRecord;
use App\Models\OrderLog;
use App\Services\OrderSignoffService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Per-order profitability (Session 99): the finalized revenue invoice, the
 * supplier invoices and fees against it, finance's sign-off, the export and
 * the dashboard — and the weekly liquidity ladder.
 *
 * Minimal-schema sqlite harness, same pattern as OrderSignoffAndBoardTest, so
 * this runs in CI rather than behind the MySQL gate.
 */
class OrderProfitabilityAndLiquidityTest extends TestCase
{
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::disableForeignKeyConstraints();

        foreach ([
            'liquidity_weeks', 'order_cost_lines', 'order_finance_records',
            'order_signoffs', 'finance_invoices', 'order_logs', 'invoices',
            'trade_documents', 'order_items', 'order_shipment_events', 'eu_declarations',
            'customers', 'orders', 'personal_access_tokens', 'admin_users',
        ] as $table) {
            Schema::dropIfExists($table);
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
            $table->string('source', 20)->default('website');
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('country')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('delivery_cost', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('currency', 3)->nullable();
            $table->string('status', 30)->default('pending');
            $table->string('payment_status', 30)->default('unpaid');
            $table->string('payment_stage', 40)->nullable();
            $table->string('payment_session_id')->nullable();
            $table->string('customer_acceptance_status', 30)->nullable();
            $table->timestamps();
        });

        Schema::create('order_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('order_ref', 30);
            $table->unsignedBigInteger('admin_user_id')->nullable();
            $table->string('admin_user_email')->nullable();
            $table->string('action', 60);
            $table->string('old_value', 100)->nullable();
            $table->string('new_value', 100)->nullable();
            $table->text('notes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('invoice_number', 50)->nullable();
            $table->string('order_ref', 30)->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('status', 30)->default('issued');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('company_name')->nullable();
            $table->timestamps();
        });

        // The order DETAIL endpoint eager-loads these; the finance block it
        // now carries is reached through it.
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('name')->nullable();
            $table->string('brand')->nullable();
            $table->string('size')->nullable();
            $table->string('sku')->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('order_shipment_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->date('event_date')->nullable();
            $table->string('location')->nullable();
            $table->string('status_label')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('eu_declarations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->timestamps();
        });

        Schema::create('trade_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('order_ref', 30);
            $table->string('type', 30);
            $table->string('number', 50)->nullable();
            $table->string('status', 30)->default('draft');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        // The real migrations, run against real SQL — the unique order_id on
        // finance records is the thing enforcing "one revenue invoice per
        // order", so a hand-built table would be testing a different schema
        // from the one that ships.
        $this->runMigration('2026_08_13_000002_create_order_signoffs_table');
        $this->runMigration('2026_08_28_000001_create_order_profitability_tables');
        $this->runMigration('2026_08_28_000002_create_liquidity_weeks_table');

        Schema::enableForeignKeyConstraints();

        // The models memoise whether their tables exist, and the harness
        // creates them after the container boots.
        OrderSignoffService::forgetTableCheck();
        OrderFinanceRecord::forgetAvailableCheck();
        LiquidityWeek::forgetAvailableCheck();
    }

    private function runMigration(string $name): void
    {
        (require database_path("migrations/{$name}.php"))->up();
    }

    // ── harness ───────────────────────────────────────────────────────────

    private function admin(string $role): AdminUser
    {
        $this->seq++;

        return AdminUser::create([
            'name'                    => ucfirst($role) . ' ' . $this->seq,
            'email'                   => "{$role}{$this->seq}@okelcor.com",
            'password'                => Hash::make('secret-password'),
            'role'                    => $role,
            'is_active'               => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function headers(AdminUser $admin): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer ' . $admin->createToken('t')->plainTextToken];
    }

    private function order(array $attributes = []): Order
    {
        $this->seq++;

        $createdAt = $attributes['created_at'] ?? null;
        unset($attributes['created_at']);

        $order = Order::create(array_merge([
            'ref'            => 'OKL-P' . str_pad((string) $this->seq, 4, '0', STR_PAD_LEFT),
            'source'         => 'website',
            'customer_name'  => 'Acme GmbH',
            'customer_email' => 'buyer' . $this->seq . '@acme.de',
            'total'          => 10000,
            'subtotal'       => 10000,
            'currency'       => 'EUR',
            'status'         => 'confirmed',
            'payment_status' => 'unpaid',
        ], $attributes));

        if ($createdAt !== null) {
            $order->forceFill(['created_at' => $createdAt])->saveQuietly();
        }

        return $order->fresh();
    }

    private function setRevenue(AdminUser $finance, Order $order, float $amount = 10000, array $extra = []): void
    {
        $this->withHeaders($this->headers($finance))
            ->postJson("/api/v1/admin/orders/{$order->id}/profitability/revenue", array_merge([
                'invoice_number' => 'RE-' . $order->id,
                'amount'         => $amount,
            ], $extra))
            ->assertStatus(201);
    }

    private function addCost(AdminUser $finance, Order $order, array $payload): int
    {
        $response = $this->withHeaders($this->headers($finance))
            ->postJson("/api/v1/admin/orders/{$order->id}/profitability/costs", $payload);

        $response->assertStatus(201);

        return (int) $response->json('data.id');
    }

    // ── the migrations themselves ─────────────────────────────────────────

    public function test_the_migrations_apply_against_real_sql_and_are_idempotent(): void
    {
        // setUp already ran them once against real SQL. Running up() again
        // must be a no-op, not an error — the deploy story depends on it.
        $this->runMigration('2026_08_28_000001_create_order_profitability_tables');
        $this->runMigration('2026_08_28_000002_create_liquidity_weeks_table');

        $this->assertTrue(Schema::hasTable('order_finance_records'));
        $this->assertTrue(Schema::hasTable('order_cost_lines'));
        $this->assertTrue(Schema::hasTable('liquidity_weeks'));
    }

    // ── the revenue invoice ───────────────────────────────────────────────

    public function test_finance_records_the_revenue_invoice_with_its_pdf(): void
    {
        Storage::fake('local');

        $finance = $this->admin('finance');
        $order   = $this->order();

        $this->withHeaders($this->headers($finance))
            ->post("/api/v1/admin/orders/{$order->id}/profitability/revenue", [
                'invoice_number' => 'RE-2026-0815',
                'amount'         => 9800,
                'issued_on'      => '2026-08-20',
                'file'           => UploadedFile::fake()->create('re-2026-0815.pdf', 120, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(201)
            ->assertJsonPath('data.revenue.invoice_number', 'RE-2026-0815')
            ->assertJsonPath('data.revenue.amount', 9800)
            ->assertJsonPath('data.revenue.has_file', true)
            // 9800 invoiced against a 10000 order — the variance finance
            // reconciles travels with the record.
            ->assertJsonPath('data.revenue.variance_from_order_total', -200);

        // The customer-agreed default: a revenue invoice is BY DEFINITION the
        // one the customer agreed to, so the timestamp lands unless finance
        // says otherwise.
        $this->assertNotNull(OrderFinanceRecord::first()->customer_agreed_at);

        // The action is in the audit trail, and its literal is declared in
        // OrderLog::ACTIONS (the scan test enforces the second half).
        $this->assertSame(1, OrderLog::where('order_ref', $order->ref)->where('action', 'revenue_invoice_set')->count());

        // And the PDF comes back down.
        $this->withHeaders($this->headers($finance))
            ->get("/api/v1/admin/orders/{$order->id}/profitability/revenue/download")
            ->assertStatus(200);
    }

    public function test_reading_is_finance_view_and_writing_is_finance_manage(): void
    {
        $order = $this->order();

        // An order manager holds finance.view — the board logic: operations
        // and finance look at the same numbers — but not finance.manage.
        $ops = $this->admin('order_manager');

        $this->withHeaders($this->headers($ops))
            ->getJson("/api/v1/admin/orders/{$order->id}/profitability")
            ->assertStatus(200);

        $this->withHeaders($this->headers($ops))
            ->postJson("/api/v1/admin/orders/{$order->id}/profitability/revenue", [
                'invoice_number' => 'RE-1', 'amount' => 100,
            ])
            ->assertStatus(403);

        // A viewer holds neither.
        $this->withHeaders($this->headers($this->admin('viewer')))
            ->getJson("/api/v1/admin/orders/{$order->id}/profitability")
            ->assertStatus(403);

        // Finance holds both.
        $this->setRevenue($this->admin('finance'), $order);
    }

    // ── costs and the arithmetic ──────────────────────────────────────────

    public function test_costs_subtract_from_revenue_and_fees_split_by_category(): void
    {
        $finance = $this->admin('finance');
        $order   = $this->order();

        $this->setRevenue($finance, $order, 10000);

        $this->addCost($finance, $order, [
            'kind' => 'supplier_invoice', 'supplier' => 'Shandong Tyres Co.',
            'reference' => 'ST-4471', 'amount' => 6000,
        ]);
        $this->addCost($finance, $order, ['kind' => 'fee', 'category' => 'stripe', 'amount' => 290]);
        $this->addCost($finance, $order, ['kind' => 'fee', 'category' => 'ebay', 'amount' => 150]);

        $this->withHeaders($this->headers($finance))
            ->getJson("/api/v1/admin/orders/{$order->id}/profitability")
            ->assertStatus(200)
            ->assertJsonPath('data.costs.supplier_total', 6000)
            ->assertJsonPath('data.costs.fees_total', 440)
            ->assertJsonPath('data.costs.total', 6440)
            ->assertJsonPath('data.costs.by_category.stripe', 290)
            ->assertJsonPath('data.costs.by_category.ebay', 150)
            ->assertJsonPath('data.profit.amount', 3560)
            ->assertJsonPath('data.profit.margin_percent', 35.6)
            ->assertJsonPath('data.profit.mixed_currency', false);
    }

    public function test_a_fee_needs_a_category_and_a_supplier_invoice_needs_a_supplier(): void
    {
        $finance = $this->admin('finance');
        $order   = $this->order();

        // A fee with no category cannot be reported by channel, which is the
        // reason fees are recorded at all.
        $this->withHeaders($this->headers($finance))
            ->postJson("/api/v1/admin/orders/{$order->id}/profitability/costs", [
                'kind' => 'fee', 'amount' => 100,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category']);

        $this->withHeaders($this->headers($finance))
            ->postJson("/api/v1/admin/orders/{$order->id}/profitability/costs", [
                'kind' => 'supplier_invoice', 'amount' => 100,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['supplier']);
    }

    public function test_a_cost_in_another_currency_is_named_not_converted(): void
    {
        $finance = $this->admin('finance');
        $order   = $this->order();

        $this->setRevenue($finance, $order, 10000);

        $this->addCost($finance, $order, [
            'kind' => 'supplier_invoice', 'supplier' => 'US Freight Inc.',
            'amount' => 500, 'currency' => 'USD',
        ]);
        $this->addCost($finance, $order, [
            'kind' => 'supplier_invoice', 'supplier' => 'Shandong Tyres Co.', 'amount' => 6000,
        ]);

        // The USD line is excluded from the EUR totals and named separately —
        // converting at today's rate would make a historic order's margin
        // change every time the page is opened.
        $this->withHeaders($this->headers($finance))
            ->getJson("/api/v1/admin/orders/{$order->id}/profitability")
            ->assertJsonPath('data.costs.total', 6000)
            ->assertJsonPath('data.costs.other_currencies.USD', 500)
            ->assertJsonPath('data.profit.amount', 4000)
            ->assertJsonPath('data.profit.mixed_currency', true);
    }

    // ── verification ──────────────────────────────────────────────────────

    public function test_verification_needs_a_revenue_figure(): void
    {
        $finance = $this->admin('finance');
        $order   = $this->order();

        $this->withHeaders($this->headers($finance))
            ->postJson("/api/v1/admin/orders/{$order->id}/profitability/verify")
            ->assertStatus(422)
            ->assertJsonPath('code', 'no_revenue_invoice');
    }

    public function test_the_figures_moving_withdraws_a_standing_verification(): void
    {
        $finance = $this->admin('finance');
        $order   = $this->order();

        $this->setRevenue($finance, $order, 10000);
        $costId = $this->addCost($finance, $order, ['kind' => 'fee', 'category' => 'bank', 'amount' => 45]);

        $this->withHeaders($this->headers($finance))
            ->postJson("/api/v1/admin/orders/{$order->id}/profitability/verify", ['note' => 'Reconciled against sevDesk'])
            ->assertStatus(200)
            ->assertJsonPath('data.verification.verified', true);

        // The money moves — the signature goes with it, automatically, and
        // the withdrawal is itself in the trail.
        $this->withHeaders($this->headers($finance))
            ->patchJson("/api/v1/admin/orders/{$order->id}/profitability/costs/{$costId}", ['amount' => 90])
            ->assertStatus(200);

        $this->assertNull(OrderFinanceRecord::first()->verified_at);
        $this->assertSame(1, OrderLog::where('order_ref', $order->ref)
            ->where('action', 'profitability_verification_withdrawn')->count());

        // Fixing a typo in a note must NOT cost finance a signature.
        $this->withHeaders($this->headers($finance))
            ->postJson("/api/v1/admin/orders/{$order->id}/profitability/verify")
            ->assertStatus(200);

        $this->withHeaders($this->headers($finance))
            ->patchJson("/api/v1/admin/orders/{$order->id}/profitability/costs/{$costId}", ['notes' => 'Monthly bank charge'])
            ->assertStatus(200);

        $this->assertNotNull(OrderFinanceRecord::first()->verified_at);
    }

    public function test_withdrawing_by_hand_requires_a_written_reason(): void
    {
        $finance = $this->admin('finance');
        $order   = $this->order();

        $this->setRevenue($finance, $order);

        $this->withHeaders($this->headers($finance))
            ->postJson("/api/v1/admin/orders/{$order->id}/profitability/verify")
            ->assertStatus(200);

        $this->withHeaders($this->headers($finance))
            ->deleteJson("/api/v1/admin/orders/{$order->id}/profitability/verify")
            ->assertStatus(422);

        $this->withHeaders($this->headers($finance))
            ->deleteJson("/api/v1/admin/orders/{$order->id}/profitability/verify", [
                'reason' => 'Supplier invoice was for a different order',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.verification.verified', false);
    }

    // ── the order page knows ──────────────────────────────────────────────

    public function test_the_order_detail_carries_the_finance_block(): void
    {
        $finance = $this->admin('finance');
        $order   = $this->order();

        $this->setRevenue($finance, $order, 9800);
        $this->addCost($finance, $order, ['kind' => 'fee', 'category' => 'stripe', 'amount' => 300]);

        $this->withHeaders($this->headers($this->admin('order_manager')))
            ->getJson("/api/v1/admin/orders/{$order->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.finance.has_revenue_invoice', true)
            ->assertJsonPath('data.finance.customer_agreed', true)
            ->assertJsonPath('data.finance.profit', 9500)
            ->assertJsonPath('data.finance.verified', false);
    }

    public function test_the_order_page_survives_the_feature_arriving_before_its_migration(): void
    {
        $order = $this->order();
        $ops   = $this->admin('order_manager');

        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('order_cost_lines');
        Schema::dropIfExists('order_finance_records');
        Schema::enableForeignKeyConstraints();
        OrderFinanceRecord::forgetAvailableCheck();

        // The order page keeps working, saying plainly there is nothing.
        $this->withHeaders($this->headers($ops))
            ->getJson("/api/v1/admin/orders/{$order->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.finance', null);

        // The list is openable and says why it is empty.
        $this->withHeaders($this->headers($ops))
            ->getJson('/api/v1/admin/finance/profitability')
            ->assertStatus(200)
            ->assertJsonPath('meta.profitability_available', false);

        // A write refuses loudly rather than 500ing.
        $this->withHeaders($this->headers($this->admin('finance')))
            ->postJson("/api/v1/admin/orders/{$order->id}/profitability/revenue", [
                'invoice_number' => 'RE-1', 'amount' => 100,
            ])
            ->assertStatus(503);
    }

    // ── the list and the export ───────────────────────────────────────────

    public function test_the_list_reports_one_row_per_order_with_its_signoff_state(): void
    {
        $finance = $this->admin('finance');

        $verified   = $this->order();
        $unverified = $this->order();
        $this->order(['status' => 'cancelled']);           // not business
        $this->order(['payment_session_id' => 'cs_test_abc123']); // Stripe test checkout

        $this->setRevenue($finance, $verified, 9000);
        $this->addCost($finance, $verified, ['kind' => 'supplier_invoice', 'supplier' => 'Shandong', 'amount' => 5000]);

        $this->withHeaders($this->headers($finance))
            ->postJson("/api/v1/admin/orders/{$verified->id}/profitability/verify")
            ->assertStatus(200);

        $response = $this->withHeaders($this->headers($finance))
            ->getJson('/api/v1/admin/finance/profitability')
            ->assertStatus(200);

        $rows = collect($response->json('data'));

        // The cancelled order and the test checkout are not in the list.
        $this->assertSame(2, $rows->count());

        $row = $rows->firstWhere('order_ref', $verified->ref);
        $this->assertSame(9000.0, (float) $row['revenue_amount']);
        $this->assertSame(4000.0, (float) $row['profit']);
        $this->assertTrue($row['verified']);

        $this->assertFalse($rows->firstWhere('order_ref', $unverified->ref)['verified']);

        // The verified filter answers "what still needs signing".
        $pending = $this->withHeaders($this->headers($finance))
            ->getJson('/api/v1/admin/finance/profitability?verified=no')
            ->json('data');

        $this->assertSame([$unverified->ref], array_column($pending, 'order_ref'));
    }

    public function test_the_export_is_excel_safe_and_carries_the_verification_column(): void
    {
        $finance = $this->admin('finance');
        $order   = $this->order();

        $this->setRevenue($finance, $order, 9000);

        $this->withHeaders($this->headers($finance))
            ->postJson("/api/v1/admin/orders/{$order->id}/profitability/verify")
            ->assertStatus(200);

        $response = $this->withHeaders($this->headers($finance))
            ->get('/api/v1/admin/finance/profitability/export');

        $response->assertStatus(200);

        $csv = $response->streamedContent();

        // Excel reads a UTF-8 CSV as Latin-1 without the BOM — and this file
        // goes to the finance team, who will open it in Excel.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Order ref', $csv);
        $this->assertStringContainsString($order->ref, $csv);
        $this->assertStringContainsString('yes', $csv);

        // Export needs orders.export on top of finance.view — support has
        // neither and gets the door, not a file.
        $this->withHeaders($this->headers($this->admin('support')))
            ->get('/api/v1/admin/finance/profitability/export')
            ->assertStatus(403);
    }

    // ── the dashboard ─────────────────────────────────────────────────────

    public function test_the_dashboard_summarises_month_by_month_from_january(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-28 12:00:00'));

        $finance = $this->admin('finance');

        $january = $this->order(['created_at' => '2026-01-15 10:00:00']);
        $march   = $this->order(['created_at' => '2026-03-10 10:00:00']);

        $this->setRevenue($finance, $january, 10000);
        $this->addCost($finance, $january, ['kind' => 'supplier_invoice', 'supplier' => 'Shandong', 'amount' => 6000]);
        $this->addCost($finance, $january, ['kind' => 'fee', 'category' => 'stripe', 'amount' => 300]);

        $this->setRevenue($finance, $march, 5000);
        $this->addCost($finance, $march, ['kind' => 'fee', 'category' => 'ebay', 'amount' => 500]);

        $response = $this->withHeaders($this->headers($finance))
            ->getJson('/api/v1/admin/finance/profitability/dashboard')
            ->assertStatus(200)
            ->assertJsonPath('data.year', 2026);

        $months = collect($response->json('data.months'));

        // Gap-free from January to the current month — February present as
        // zeros, nothing after August invented.
        $this->assertSame(
            ['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08'],
            $months->pluck('key')->all(),
        );

        $jan = $months->firstWhere('key', '2026-01');
        $this->assertSame(10000.0, (float) $jan['revenue_eur']);
        $this->assertSame(6300.0, (float) $jan['costs_eur']);
        $this->assertSame(3700.0, (float) $jan['profit_eur']);
        $this->assertSame(37.0, (float) $jan['margin_percent']);

        $this->assertSame(0.0, (float) $months->firstWhere('key', '2026-02')['revenue_eur']);

        $totals = $response->json('data.totals');
        $this->assertSame(15000.0, (float) $totals['revenue_eur']);
        $this->assertSame(8200.0, (float) $totals['profit_eur']);
    }

    // ── the liquidity ladder ──────────────────────────────────────────────

    public function test_the_planner_shows_the_current_week_and_the_three_ahead(): void
    {
        // 2026-08-28 is a Friday in ISO week 35 — the week the note named.
        $this->travelTo(CarbonImmutable::parse('2026-08-28 12:00:00'));

        $finance = $this->admin('finance');

        $this->withHeaders($this->headers($finance))
            ->putJson('/api/v1/admin/finance/liquidity/2026-W35', [
                'bank_balance' => 42000, 'expected_in' => 10000, 'expected_out' => 6000,
            ])
            ->assertStatus(200);

        $this->withHeaders($this->headers($finance))
            ->putJson('/api/v1/admin/finance/liquidity/2026-W36', [
                'expected_in' => 2000, 'expected_out' => 9000,
            ])
            ->assertStatus(200);

        $response = $this->withHeaders($this->headers($finance))
            ->getJson('/api/v1/admin/finance/liquidity')
            ->assertStatus(200)
            ->assertJsonPath('meta.current_week', '2026-W35')
            ->assertJsonPath('meta.window', 4);

        $weeks = $response->json('data.weeks');

        $this->assertSame(['2026-W35', '2026-W36', '2026-W37', '2026-W38'], array_column($weeks, 'week_key'));
        $this->assertTrue($weeks[0]['is_current']);

        // The ladder: week 35 closes at 42000 + 10000 − 6000 = 46000; week 36
        // has no entered balance, so it opens on that and closes at
        // 46000 + 2000 − 9000 = 39000.
        $this->assertSame(46000.0, (float) $weeks[0]['projected_closing']);
        $this->assertSame(39000.0, (float) $weeks[1]['projected_closing']);
    }

    public function test_the_window_rolls_and_a_finished_week_moves_to_history(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-28 12:00:00')); // week 35

        $finance = $this->admin('finance');

        $this->withHeaders($this->headers($finance))
            ->putJson('/api/v1/admin/finance/liquidity/2026-W35', ['bank_balance' => 42000])
            ->assertStatus(200);

        // The calendar moves; nobody runs anything.
        $this->travelTo(CarbonImmutable::parse('2026-09-02 09:00:00')); // week 36

        $weeks = $this->withHeaders($this->headers($finance))
            ->getJson('/api/v1/admin/finance/liquidity')
            ->assertJsonPath('meta.current_week', '2026-W36')
            ->json('data.weeks');

        // Week 35 has dropped out of the view...
        $this->assertSame(['2026-W36', '2026-W37', '2026-W38', '2026-W39'], array_column($weeks, 'week_key'));

        // ...and survives untouched in history.
        $this->withHeaders($this->headers($finance))
            ->getJson('/api/v1/admin/finance/liquidity/history')
            ->assertStatus(200)
            ->assertJsonPath('data.weeks.0.week_key', '2026-W35')
            ->assertJsonPath('data.weeks.0.bank_balance', 42000);
    }

    public function test_a_week_key_must_name_a_real_iso_week_near_today(): void
    {
        $finance = $this->admin('finance');

        // 2026 has 53 ISO weeks; 2027 does not — but W54 exists in no year.
        $this->withHeaders($this->headers($finance))
            ->putJson('/api/v1/admin/finance/liquidity/2026-W54', ['bank_balance' => 1])
            ->assertStatus(422);

        $this->withHeaders($this->headers($finance))
            ->putJson('/api/v1/admin/finance/liquidity/week-35', ['bank_balance' => 1])
            ->assertStatus(422);

        // More than a year out is far more likely a typo than a plan.
        $farOut = CarbonImmutable::today()->addWeeks(80)->format('o-\WW');

        $this->withHeaders($this->headers($finance))
            ->putJson("/api/v1/admin/finance/liquidity/{$farOut}", ['bank_balance' => 1])
            ->assertStatus(422);
    }

    public function test_liquidity_reading_is_finance_view_and_writing_is_finance_manage(): void
    {
        $ops = $this->admin('order_manager');
        $key = LiquidityWeek::keyFor(CarbonImmutable::today());

        $this->withHeaders($this->headers($ops))
            ->getJson('/api/v1/admin/finance/liquidity')
            ->assertStatus(200);

        $this->withHeaders($this->headers($ops))
            ->putJson("/api/v1/admin/finance/liquidity/{$key}", ['bank_balance' => 1])
            ->assertStatus(403);

        $this->withHeaders($this->headers($this->admin('viewer')))
            ->getJson('/api/v1/admin/finance/liquidity')
            ->assertStatus(403);
    }
}
