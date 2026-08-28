<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\FinanceInvoice;
use App\Models\FinanceLiquidityWeekEntry;
use App\Models\Order;
use App\Services\OrderProfitabilityService;
use App\Services\OrderSignoffService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Order profitability — the finalized revenue invoice against supplier
 * invoices and fee lines — plus the export, the monthly summary, and the
 * rolling four-week liquidity window.
 *
 * Minimal-schema sqlite harness, same pattern as OrderSignoffAndBoardTest.
 */
class OrderProfitabilityTest extends TestCase
{
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::disableForeignKeyConstraints();

        foreach ([
            'finance_liquidity_week_entries', 'order_costs', 'order_signoffs',
            'finance_invoices', 'order_logs', 'orders',
            'personal_access_tokens', 'admin_users',
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
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('delivery_cost', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('currency', 3)->nullable();
            $table->string('status', 30)->default('pending');
            $table->string('payment_status', 30)->default('unpaid');
            $table->string('payment_stage', 40)->nullable();
            $table->string('payment_session_id')->nullable();
            $table->timestamps();
        });

        Schema::create('order_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('order_ref', 30);
            $table->unsignedBigInteger('admin_user_id')->nullable();
            $table->string('admin_user_email')->nullable();
            $table->string('action', 60);
            $table->text('notes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        // The real migrations — the role column, the cost table and the weekly
        // liquidity table are the schema under test.
        $this->runMigration('2026_08_13_000002_create_order_signoffs_table');
        $this->runMigration('2026_08_13_000003_create_finance_invoices_table');
        $this->runMigration('2026_08_14_000001_add_file_and_origin_to_finance_invoices');
        $this->runMigration('2026_08_28_000001_add_role_and_finalization_to_finance_invoices');
        $this->runMigration('2026_08_28_000002_create_order_costs_table');
        $this->runMigration('2026_08_28_000003_create_finance_liquidity_week_entries_table');

        Schema::enableForeignKeyConstraints();

        // Memoised schema guards, primed before the harness built the tables.
        OrderSignoffService::forgetTableCheck();
        FinanceInvoice::forgetRegisterCheck();
        OrderProfitabilityService::forgetCheck();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function runMigration(string $name): void
    {
        (require database_path("migrations/{$name}.php"))->up();
    }

    // ── harness ───────────────────────────────────────────────────────────

    private function admin(string $role = 'finance'): AdminUser
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
            'subtotal'       => 10000,
            'total'          => 10000,
            'currency'       => 'EUR',
            'status'         => 'confirmed',
            'payment_status' => 'unpaid',
        ], $attributes));

        if ($createdAt !== null) {
            $order->forceFill(['created_at' => $createdAt])->saveQuietly();
        }

        return $order->fresh();
    }

    /** Records and finalizes the revenue invoice for an order. */
    private function finalizedRevenueInvoice(Order $order, float $amount, string $number = null): FinanceInvoice
    {
        $finance = $this->admin('finance');

        $stored = $this->actingAs($finance, 'sanctum')->postJson('/api/v1/admin/finance-invoices', [
            'system'          => 'upload',
            'external_number' => $number ?? 'REV-' . $order->ref,
            'order_ref'       => $order->ref,
            'amount'          => $amount,
            'issued_on'       => now()->toDateString(),
            'role'            => 'revenue',
        ])->assertStatus(201)->json('data.id');

        $this->actingAs($finance, 'sanctum')
            ->postJson("/api/v1/admin/finance-invoices/{$stored}/finalize")
            ->assertStatus(200);

        return FinanceInvoice::findOrFail($stored);
    }

    // ── profitability ─────────────────────────────────────────────────────

    public function test_finalized_revenue_minus_supplier_invoices_and_fees_is_the_profit(): void
    {
        $order   = $this->order();
        $finance = $this->admin('finance');

        $this->finalizedRevenueInvoice($order, 9500);

        $this->actingAs($finance, 'sanctum')->postJson('/api/v1/admin/finance-invoices', [
            'system'          => 'upload',
            'external_number' => 'SUP-77',
            'order_ref'       => $order->ref,
            'amount'          => 6000,
            'issued_on'       => now()->toDateString(),
            'role'            => 'supplier',
            'supplier_name'   => 'Linglong Tyres BV',
        ])->assertStatus(201);

        $this->actingAs($finance, 'sanctum')->postJson("/api/v1/admin/orders/{$order->id}/costs", [
            'type' => 'stripe_fee', 'amount' => 150,
        ])->assertStatus(201);

        $this->actingAs($finance, 'sanctum')->postJson("/api/v1/admin/orders/{$order->id}/costs", [
            'type' => 'bank_cost', 'amount' => 50, 'label' => 'Wise transfer charge',
        ])->assertStatus(201);

        $this->actingAs($finance, 'sanctum')
            ->getJson("/api/v1/admin/finance/profitability/{$order->ref}")
            ->assertStatus(200)
            ->assertJsonPath('data.profitability.revenue', 9500)
            ->assertJsonPath('data.profitability.supplier_costs', 6000)
            ->assertJsonPath('data.profitability.fees', 200)
            ->assertJsonPath('data.profitability.total_costs', 6200)
            ->assertJsonPath('data.profitability.profit', 3300)
            ->assertJsonPath('data.profitability.margin_percent', 34.7)
            ->assertJsonPath('data.profitability.profitability_status', 'complete')
            ->assertJsonPath('data.revenue_invoice.finalized', true)
            ->assertJsonPath('data.supplier_invoices.0.supplier_name', 'Linglong Tyres BV');
    }

    public function test_profit_stays_null_until_the_revenue_invoice_is_finalized(): void
    {
        $order   = $this->order();
        $finance = $this->admin('finance');

        // Recorded but not finalized — the customer has not agreed yet.
        $this->actingAs($finance, 'sanctum')->postJson('/api/v1/admin/finance-invoices', [
            'system'          => 'upload',
            'external_number' => 'REV-DRAFT-1',
            'order_ref'       => $order->ref,
            'amount'          => 9500,
            'issued_on'       => now()->toDateString(),
            'role'            => 'revenue',
        ])->assertStatus(201);

        $this->actingAs($finance, 'sanctum')
            ->getJson("/api/v1/admin/finance/profitability/{$order->ref}")
            ->assertStatus(200)
            ->assertJsonPath('data.profitability.revenue', null)
            ->assertJsonPath('data.profitability.profit', null)
            ->assertJsonPath('data.profitability.profitability_status', 'awaiting_revenue_invoice')
            ->assertJsonPath('data.revenue_invoice', null)
            ->assertJsonPath('data.draft_revenue_invoices.0.external_number', 'REV-DRAFT-1');
    }

    public function test_an_order_gets_exactly_one_finalized_revenue_invoice(): void
    {
        $order   = $this->order();
        $finance = $this->admin('finance');

        $this->finalizedRevenueInvoice($order, 9500);

        $second = $this->actingAs($finance, 'sanctum')->postJson('/api/v1/admin/finance-invoices', [
            'system'          => 'upload',
            'external_number' => 'REV-SECOND',
            'order_ref'       => $order->ref,
            'amount'          => 9800,
            'issued_on'       => now()->toDateString(),
            'role'            => 'revenue',
        ])->assertStatus(201)->json('data.id');

        $this->actingAs($finance, 'sanctum')
            ->postJson("/api/v1/admin/finance-invoices/{$second}/finalize")
            ->assertStatus(409)
            ->assertJsonPath('code', 'revenue_invoice_exists');
    }

    public function test_finalize_refuses_missing_amount_unknown_order_and_supplier_rows(): void
    {
        $order   = $this->order();
        $finance = $this->admin('finance');

        $noAmount = $this->actingAs($finance, 'sanctum')->postJson('/api/v1/admin/finance-invoices', [
            'system' => 'upload', 'external_number' => 'X-1',
            'order_ref' => $order->ref, 'issued_on' => now()->toDateString(), 'role' => 'revenue',
        ])->json('data.id');

        $this->actingAs($finance, 'sanctum')
            ->postJson("/api/v1/admin/finance-invoices/{$noAmount}/finalize")
            ->assertStatus(409)->assertJsonPath('code', 'amount_missing');

        $unknownOrder = $this->actingAs($finance, 'sanctum')->postJson('/api/v1/admin/finance-invoices', [
            'system' => 'upload', 'external_number' => 'X-2', 'amount' => 100,
            'order_ref' => 'NO-SUCH-ORDER', 'issued_on' => now()->toDateString(),
        ])->json('data.id');

        $this->actingAs($finance, 'sanctum')
            ->postJson("/api/v1/admin/finance-invoices/{$unknownOrder}/finalize")
            ->assertStatus(409)->assertJsonPath('code', 'order_unknown');

        $supplier = $this->actingAs($finance, 'sanctum')->postJson('/api/v1/admin/finance-invoices', [
            'system' => 'upload', 'external_number' => 'X-3', 'amount' => 100,
            'order_ref' => $order->ref, 'issued_on' => now()->toDateString(),
            'role' => 'supplier', 'supplier_name' => 'Somewhere BV',
        ])->json('data.id');

        $this->actingAs($finance, 'sanctum')
            ->postJson("/api/v1/admin/finance-invoices/{$supplier}/finalize")
            ->assertStatus(409)->assertJsonPath('code', 'not_revenue');
    }

    public function test_a_finalized_invoice_is_locked_until_unfinalized(): void
    {
        $order   = $this->order();
        $finance = $this->admin('finance');
        $invoice = $this->finalizedRevenueInvoice($order, 9500);

        $this->actingAs($finance, 'sanctum')
            ->patchJson("/api/v1/admin/finance-invoices/{$invoice->id}", ['amount' => 1])
            ->assertStatus(409)->assertJsonPath('code', 'finalized_locked');

        $this->actingAs($finance, 'sanctum')
            ->deleteJson("/api/v1/admin/finance-invoices/{$invoice->id}")
            ->assertStatus(409)->assertJsonPath('code', 'finalized_locked');

        $this->actingAs($finance, 'sanctum')
            ->postJson("/api/v1/admin/finance-invoices/{$invoice->id}/unfinalize")
            ->assertStatus(200);

        $this->actingAs($finance, 'sanctum')
            ->patchJson("/api/v1/admin/finance-invoices/{$invoice->id}", ['amount' => 9600])
            ->assertStatus(200);
    }

    // ── the list, sign-off columns and the export ─────────────────────────

    public function test_list_shows_verification_and_the_verified_filter_narrows(): void
    {
        $signed   = $this->order();
        $unsigned = $this->order();

        $this->finalizedRevenueInvoice($signed, 9500);

        $service = app(OrderSignoffService::class);
        $this->assertTrue($service->sign($signed, $this->admin('order_manager'), 'ops')['ok']);
        $this->assertTrue($service->sign($signed, $this->admin('finance'), 'finance')['ok']);

        $viewer = $this->admin('finance');

        $response = $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/admin/finance/profitability')
            ->assertStatus(200);

        $rows = collect($response->json('data'));
        $this->assertTrue($rows->firstWhere('ref', $signed->ref)['verified']);
        $this->assertFalse($rows->firstWhere('ref', $unsigned->ref)['verified']);
        $this->assertNotNull($rows->firstWhere('ref', $signed->ref)['signoff']['finance_signed_by']);

        $verifiedOnly = collect($this->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/admin/finance/profitability?verified=yes')
            ->assertStatus(200)->json('data'));

        $this->assertCount(1, $verifiedOnly);
        $this->assertSame($signed->ref, $verifiedOnly[0]['ref']);
    }

    public function test_export_is_a_csv_with_one_reference_per_order_and_the_verified_column(): void
    {
        $order = $this->order();
        $this->finalizedRevenueInvoice($order, 9500);

        $response = $this->actingAs($this->admin('finance'), 'sanctum')
            ->get('/api/v1/admin/finance/profitability/export')
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString($order->ref, $csv);
        $this->assertStringContainsString('Revenue (agreed)', $csv);
        $this->assertStringContainsString('Verified', $csv);
        $this->assertStringContainsString('9500', $csv);
    }

    public function test_summary_rolls_the_year_up_month_by_month(): void
    {
        Carbon::setTestNow('2026-08-28 12:00:00');

        $march = $this->order(['created_at' => '2026-03-10 09:00:00']);
        $this->finalizedRevenueInvoice($march, 8000);

        $finance = $this->admin('finance');

        $this->actingAs($finance, 'sanctum')->postJson("/api/v1/admin/orders/{$march->id}/costs", [
            'type' => 'ebay_fee', 'amount' => 500,
        ])->assertStatus(201);

        // An August order with no revenue invoice yet.
        $this->order(['created_at' => '2026-08-05 09:00:00']);

        $data = $this->actingAs($finance, 'sanctum')
            ->getJson('/api/v1/admin/finance/profitability/summary?year=2026')
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(8, $data['months']); // January through August

        $monthOf = collect($data['months'])->keyBy('month');

        $this->assertSame(8000.0, (float) $monthOf[3]['revenue']);
        $this->assertSame(500.0, (float) $monthOf[3]['fees']);
        $this->assertSame(7500.0, (float) $monthOf[3]['profit']);
        $this->assertSame(1, $monthOf[8]['orders_missing_revenue_invoice']);
        $this->assertSame(7500.0, (float) $data['totals']['profit']);
    }

    // ── the rolling four-week liquidity window ────────────────────────────

    public function test_liquidity_shows_four_weeks_from_the_current_one_and_drops_closed_weeks(): void
    {
        Carbon::setTestNow('2026-08-28 12:00:00'); // Friday of ISO week 35

        $finance = $this->admin('finance');

        // A closed week's entry, straight into the table as history.
        FinanceLiquidityWeekEntry::create([
            'week_start' => '2026-08-17', 'line' => 'bank_balance', 'amount' => 999,
        ]);

        $this->actingAs($finance, 'sanctum')->postJson('/api/v1/admin/finance-liquidity/weeks/entries', [
            'week_start' => '2026-09-02', // Wednesday of week 36 — normalized to its Monday
            'line'       => 'revenue_payment',
            'description' => 'NIOS balance payment',
            'amount'     => 2000,
        ])->assertStatus(201)->assertJsonPath('data.week_start', '2026-08-31');

        $this->actingAs($finance, 'sanctum')->postJson('/api/v1/admin/finance-liquidity/weeks/entries', [
            'week_start' => '2026-08-31', 'line' => 'salaries', 'amount' => 1500,
        ])->assertStatus(201);

        $data = $this->actingAs($finance, 'sanctum')
            ->getJson('/api/v1/admin/finance-liquidity/weeks')
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(4, $data);
        $this->assertSame([35, 36, 37, 38], array_column($data, 'week'));
        $this->assertTrue($data[0]['is_current']);

        // Week 34 is closed: its balance appears nowhere in the window.
        $this->assertNotContains('2026-08-17', array_column($data, 'starts_on'));

        $this->assertSame(2000.0, (float) $data[1]['expected_in']);
        $this->assertSame(1500.0, (float) $data[1]['expected_out']);
        $this->assertSame(500.0, (float) $data[1]['projected_closing']);
    }

    public function test_bank_balance_is_set_per_week_and_closed_weeks_refuse_writes(): void
    {
        Carbon::setTestNow('2026-08-28 12:00:00');

        $finance = $this->admin('finance');

        $first = $this->actingAs($finance, 'sanctum')->putJson('/api/v1/admin/finance-liquidity/weeks/bank-balance', [
            'week_start' => '2026-08-26', 'amount' => 4520, 'reference' => 'Wise EUR',
        ])->assertStatus(200)->json('data');

        $this->assertSame('2026-08-24', $first['week_start']);
        $this->assertSame(4520.0, (float) $first['amount']);

        // Setting it again for the same week updates the same row.
        $second = $this->actingAs($finance, 'sanctum')->putJson('/api/v1/admin/finance-liquidity/weeks/bank-balance', [
            'week_start' => '2026-08-24', 'amount' => 5000,
        ])->assertStatus(200)->json('data');

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, FinanceLiquidityWeekEntry::where('line', 'bank_balance')->count());

        // Last week is closed.
        $this->actingAs($finance, 'sanctum')->putJson('/api/v1/admin/finance-liquidity/weeks/bank-balance', [
            'week_start' => '2026-08-19', 'amount' => 4000,
        ])->assertStatus(409)->assertJsonPath('code', 'week_closed');

        $this->actingAs($finance, 'sanctum')->postJson('/api/v1/admin/finance-liquidity/weeks/entries', [
            'week_start' => '2026-08-19', 'line' => 'rent', 'amount' => 800,
        ])->assertStatus(409)->assertJsonPath('code', 'week_closed');
    }

    // ── permissions ───────────────────────────────────────────────────────

    public function test_order_managers_can_read_profitability_but_not_write_finance_data(): void
    {
        $order   = $this->order();
        $manager = $this->admin('order_manager'); // holds finance.view, not finance.manage

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/admin/finance/profitability')
            ->assertStatus(200);

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/costs", ['type' => 'bank_cost', 'amount' => 10])
            ->assertStatus(403);

        $this->actingAs($manager, 'sanctum')
            ->putJson('/api/v1/admin/finance-liquidity/weeks/bank-balance', [
                'week_start' => now()->toDateString(), 'amount' => 100,
            ])
            ->assertStatus(403);
    }
}
