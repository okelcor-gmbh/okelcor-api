<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\SalesOrderEntry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sales & Order Management board (Session 101, from finance's OT 3.html
 * mockup): hand-entered orders, customer lines against supplier lines,
 * computed GP/margin/status, and the five KPI cards.
 *
 * Minimal-schema sqlite harness, same pattern as EcInvoiceListTest.
 */
class SalesOrderBoardTest extends TestCase
{
    private int $seq = 0;

    private const TABLES = ['sales_order_lines', 'sales_order_entries', 'admin_users'];

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
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role');
            $table->boolean('is_active')->default(true);
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamps();
        });

        // The real migration, run against real SQL — the unique order_no is
        // what prevents a doubled entry, so a hand-built table would be
        // testing a different schema from the one that ships.
        $this->runMigration('2026_08_28_000005_create_sales_order_board_tables');

        Schema::enableForeignKeyConstraints();

        SalesOrderEntry::forgetAvailableCheck();
    }

    protected function tearDown(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (self::TABLES as $t) {
            Schema::dropIfExists($t);
        }
        Schema::enableForeignKeyConstraints();
        parent::tearDown();
    }

    private function runMigration(string $name): void
    {
        (require database_path("migrations/{$name}.php"))->up();
    }

    private function admin(string $role = 'finance'): AdminUser
    {
        return AdminUser::create([
            'name'                    => 'Staff ' . (++$this->seq),
            'email'                   => 'so' . $this->seq . uniqid() . '@okelcor.test',
            'role'                    => $role,
            'password'                => Hash::make('secret-pass-123'),
            'is_active'               => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /** Creates an order and returns [entryId, customerLineId]. */
    private function order(AdminUser $finance, array $overrides = []): array
    {
        $response = $this->actingAs($finance, 'sanctum')
            ->postJson('/api/v1/admin/sales-orders', array_merge([
                'order_no'      => 'ORD-2026-' . str_pad((string) (++$this->seq), 3, '0', STR_PAD_LEFT),
                'customer_name' => 'Autohaus Schmidt GmbH',
                'segment'       => 'B2B',
                'period'        => '2026-05',
                'category'      => 'Tyres',
            ], $overrides));

        $response->assertCreated();

        return [(int) $response->json('data.id'), (int) $response->json('data.lines.0.id')];
    }

    // ── the migration itself ──────────────────────────────────────────────

    public function test_the_migration_applies_against_real_sql_and_is_idempotent(): void
    {
        $this->runMigration('2026_08_28_000005_create_sales_order_board_tables');

        $this->assertTrue(Schema::hasTable('sales_order_entries'));
        $this->assertTrue(Schema::hasTable('sales_order_lines'));
    }

    // ── orders ────────────────────────────────────────────────────────────

    public function test_a_new_order_carries_its_customer_line_and_a_duplicate_is_refused(): void
    {
        $finance = $this->admin();

        $response = $this->actingAs($finance, 'sanctum')
            ->postJson('/api/v1/admin/sales-orders', [
                'order_no' => 'ORD-2026-001', 'customer_name' => 'Autohaus Schmidt GmbH',
                'segment' => 'B2B', 'period' => '2026-05',
            ])
            ->assertCreated()
            // The revenue side always exists — the amount just starts at zero.
            ->assertJsonPath('data.lines.0.party_type', 'customer')
            ->assertJsonPath('data.lines.0.party_name', 'Autohaus Schmidt GmbH')
            ->assertJsonPath('data.status', 'pending_proof');

        $this->assertNotNull($response->json('data.id'));

        // The same real order twice would double the KPIs.
        $this->actingAs($finance, 'sanctum')
            ->postJson('/api/v1/admin/sales-orders', [
                'order_no' => ' ORD-2026-001 ', 'customer_name' => 'Someone Else', 'period' => '2026-06',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['order_no']);
    }

    public function test_the_period_must_be_a_month(): void
    {
        $finance = $this->admin();

        $this->actingAs($finance, 'sanctum')
            ->postJson('/api/v1/admin/sales-orders', [
                'order_no' => 'X-1', 'customer_name' => 'A', 'period' => '2026-Q1',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['period']);
    }

    // ── the arithmetic and the auto-status ────────────────────────────────

    public function test_gross_profit_and_status_come_from_the_lines(): void
    {
        Storage::fake('local');

        $finance = $this->admin();
        [$entryId, $customerLineId] = $this->order($finance);

        // The mockup's first example: 14,500 revenue for 100 tyres…
        $this->actingAs($finance, 'sanctum')
            ->patchJson("/api/v1/admin/sales-orders/lines/{$customerLineId}", [
                'amount' => 14500, 'tyre_qty' => 100,
            ])->assertOk();

        // …against a supplier at 10,000 (documented) and logistics at 1,800
        // (not yet documented).
        $this->actingAs($finance, 'sanctum')
            ->post("/api/v1/admin/sales-orders/{$entryId}/lines", [
                'party_type' => 'supplier', 'party_name' => 'Continental Wholesale',
                'amount' => 10000,
                'file' => UploadedFile::fake()->create('supp-inv-101.pdf', 40, 'application/pdf'),
            ], ['Accept' => 'application/json'])->assertCreated();

        $response = $this->actingAs($finance, 'sanctum')
            ->postJson("/api/v1/admin/sales-orders/{$entryId}/lines", [
                'party_type' => 'supplier', 'party_name' => 'Logistics Partner', 'amount' => 1800,
            ])->assertCreated();

        // GP = 14500 − 11800 = 2700; margin = 18.62%; a supplier line without
        // its document keeps the order at Pending Proof.
        $response->assertJsonPath('data.gross_profit', 2700)
            ->assertJsonPath('data.margin_percent', 18.62)
            ->assertJsonPath('data.status', 'pending_proof')
            ->assertJsonPath('data.tyres', 100);

        // The missing CMR arrives — the order verifies itself.
        $logisticsLine = collect($response->json('data.lines'))
            ->firstWhere('party_name', 'Logistics Partner')['id'];

        $this->actingAs($finance, 'sanctum')
            ->post("/api/v1/admin/sales-orders/lines/{$logisticsLine}/file", [
                'file' => UploadedFile::fake()->create('cmr-signed-1501.pdf', 30, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.status', 'verified');

        // And the document comes back down.
        $this->actingAs($finance, 'sanctum')
            ->get("/api/v1/admin/sales-orders/lines/{$logisticsLine}/download")
            ->assertStatus(200);
    }

    public function test_an_order_with_no_supplier_lines_is_pending_not_verified(): void
    {
        $finance = $this->admin();
        [, $customerLineId] = $this->order($finance);

        // Revenue with no recorded cost is not a verified margin — it is a
        // missing cost.
        $this->actingAs($finance, 'sanctum')
            ->patchJson("/api/v1/admin/sales-orders/lines/{$customerLineId}", ['amount' => 5000])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_proof');
    }

    public function test_a_supplier_line_cannot_carry_tyres_into_the_kpis(): void
    {
        $finance = $this->admin();
        [$entryId] = $this->order($finance);

        // A quantity on a supplier line would double-count the same tyres.
        $this->actingAs($finance, 'sanctum')
            ->postJson("/api/v1/admin/sales-orders/{$entryId}/lines", [
                'party_type' => 'supplier', 'party_name' => 'Continental', 'amount' => 100, 'tyre_qty' => 50,
            ])
            ->assertCreated()
            ->assertJsonPath('data.tyres', 0);
    }

    // ── the KPI cards ─────────────────────────────────────────────────────

    public function test_the_kpis_cover_the_board(): void
    {
        $finance = $this->admin();

        // B2B: 14,500 revenue / 100 tyres / 11,800 costs.
        [$b2b, $b2bLine] = $this->order($finance, ['customer_name' => 'Autohaus Schmidt GmbH']);
        $this->actingAs($finance, 'sanctum')
            ->patchJson("/api/v1/admin/sales-orders/lines/{$b2bLine}", ['amount' => 14500, 'tyre_qty' => 100])->assertOk();
        $this->actingAs($finance, 'sanctum')
            ->postJson("/api/v1/admin/sales-orders/{$b2b}/lines", [
                'party_type' => 'supplier', 'party_name' => 'Continental', 'amount' => 11800,
            ])->assertCreated();

        // B2C: 3,200 revenue / 40 tyres / 2,425.60 costs. Same customer name
        // in different case must not count twice.
        [$b2c, $b2cLine] = $this->order($finance, ['customer_name' => 'Michael Weber', 'segment' => 'B2C']);
        $this->actingAs($finance, 'sanctum')
            ->patchJson("/api/v1/admin/sales-orders/lines/{$b2cLine}", ['amount' => 3200, 'tyre_qty' => 40])->assertOk();
        $this->actingAs($finance, 'sanctum')
            ->postJson("/api/v1/admin/sales-orders/{$b2c}/lines", [
                'party_type' => 'supplier', 'party_name' => 'Bridgestone Direct', 'amount' => 2425.60,
            ])->assertCreated();

        $this->order($finance, ['customer_name' => 'MICHAEL WEBER', 'segment' => 'B2C', 'period' => '2026-06']);

        $kpis = $this->actingAs($finance, 'sanctum')
            ->getJson('/api/v1/admin/sales-orders')
            ->assertOk()
            ->json('data.kpis');

        $this->assertSame(2, $kpis['unique_customers']);
        $this->assertSame(140, $kpis['tyres_sold']);
        // (14500 + 3200) / 140 tyres.
        $this->assertSame(126.43, $kpis['avg_price_per_tyre']);
        // B2B: (14500 − 11800) / 14500 = 18.6%.
        $this->assertSame(18.6, $kpis['b2b_margin_percent']);
        // B2C: (3200 − 2425.60) / 3200 = 24.2%.
        $this->assertSame(24.2, $kpis['b2c_margin_percent']);
    }

    public function test_the_pending_filter_narrows_the_rows_but_not_the_cards(): void
    {
        Storage::fake('local');

        $finance = $this->admin();

        // One verified order…
        [$verified, $vLine] = $this->order($finance);
        $this->actingAs($finance, 'sanctum')
            ->patchJson("/api/v1/admin/sales-orders/lines/{$vLine}", ['amount' => 1000, 'tyre_qty' => 10])->assertOk();
        $this->actingAs($finance, 'sanctum')
            ->post("/api/v1/admin/sales-orders/{$verified}/lines", [
                'party_type' => 'supplier', 'party_name' => 'Continental', 'amount' => 800,
                'file' => UploadedFile::fake()->create('inv.pdf', 10, 'application/pdf'),
            ], ['Accept' => 'application/json'])->assertCreated();

        // …and one still owing proof.
        $this->order($finance, ['customer_name' => 'Laggard GmbH']);

        $response = $this->actingAs($finance, 'sanctum')
            ->getJson('/api/v1/admin/sales-orders?status=pending')
            ->assertOk();

        $rows = $response->json('data.entries');
        $this->assertCount(1, $rows);
        $this->assertSame('Laggard GmbH', $rows[0]['customer_name']);

        // The cards still describe the whole population — a worklist view
        // must not swing the margins.
        $this->assertSame(10, $response->json('data.kpis.tyres_sold'));
    }

    // ── permissions and inertness ─────────────────────────────────────────

    public function test_reading_is_finance_view_and_writing_is_finance_manage(): void
    {
        $ops = $this->admin('order_manager');

        $this->actingAs($ops, 'sanctum')
            ->getJson('/api/v1/admin/sales-orders')
            ->assertOk();

        $this->actingAs($ops, 'sanctum')
            ->postJson('/api/v1/admin/sales-orders', [
                'order_no' => 'X-1', 'customer_name' => 'A', 'period' => '2026-05',
            ])
            ->assertStatus(403);

        $this->actingAs($this->admin('viewer'), 'sanctum')
            ->getJson('/api/v1/admin/sales-orders')
            ->assertStatus(403);
    }

    public function test_the_board_survives_the_feature_arriving_before_its_migration(): void
    {
        $finance = $this->admin();

        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('sales_order_lines');
        Schema::dropIfExists('sales_order_entries');
        Schema::enableForeignKeyConstraints();
        SalesOrderEntry::forgetAvailableCheck();

        $this->actingAs($finance, 'sanctum')
            ->getJson('/api/v1/admin/sales-orders')
            ->assertOk()
            ->assertJsonPath('meta.sales_orders_available', false);
    }
}
