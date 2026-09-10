<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\StockItem;
use App\Models\StockTransaction;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The finance stock ledger, built from finance's own draft (stock.html):
 * supplier invoices merge stock in, customer invoices deduct stock out
 * with the whole invoice shortage-checked, and every booking is kept as
 * the transaction log. The stock arithmetic is the feature, so the
 * arithmetic is what gets tested.
 */
class StockLedgerTest extends TestCase
{
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::disableForeignKeyConstraints();
        foreach (['stock_transactions', 'stock_items', 'admin_security_events', 'admin_users'] as $t) {
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

        // The real migration, against real SQL.
        (require database_path('migrations/2026_09_10_000001_create_stock_ledger_tables.php'))->up();

        Schema::enableForeignKeyConstraints();
    }

    protected function tearDown(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (['stock_transactions', 'stock_items', 'admin_security_events', 'admin_users'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::enableForeignKeyConstraints();

        parent::tearDown();
    }

    private function admin(string $role = 'finance'): AdminUser
    {
        return AdminUser::create([
            'name' => 'Staff ' . (++$this->seq), 'email' => 's' . $this->seq . uniqid() . '@okelcor.test',
            'role' => $role, 'password' => Hash::make('secret-pass-123'),
            'is_active' => true, 'two_factor_confirmed_at' => now(),
        ]);
    }

    public function test_the_migration_applies_and_reapplies_against_real_sql(): void
    {
        (require database_path('migrations/2026_09_10_000001_create_stock_ledger_tables.php'))->up();

        $this->assertTrue(Schema::hasTable('stock_items'));
        $this->assertTrue(Schema::hasTable('stock_transactions'));
    }

    public function test_a_supplier_invoice_merges_matching_lines_and_keeps_the_latest_cost(): void
    {
        StockItem::create(['brand' => 'Bridgestone', 'size' => '265/65 R17', 'grade' => 'Grade A', 'qty' => 6, 'cost' => 45]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/stock/supplier-invoice', [
                'supplier_name' => 'Continental Wholesale Ltd',
                'invoice_no'    => 'SUP-INV-9920',
                'lines' => [
                    // Same brand+size+grade, case-insensitive: merges, cost updates
                    ['brand' => 'bridgestone', 'size' => '265/65 r17', 'tread' => '7.0 mm', 'grade' => 'Grade A', 'qty' => 4, 'unit_cost' => 48],
                    // New line: created
                    ['brand' => 'Michelin', 'size' => '235/60 R18', 'tread' => '7.5 mm', 'grade' => 'Grade B', 'qty' => 2, 'unit_cost' => 40],
                ],
            ])
            ->assertCreated();

        $merged = StockItem::where('brand', 'Bridgestone')->first();
        $this->assertSame(10, $merged->qty);
        $this->assertSame(48.0, (float) $merged->cost, 'the latest supplier cost wins');

        $new = StockItem::where('brand', 'Michelin')->first();
        $this->assertNotNull($new);
        $this->assertSame(2, $new->qty);

        $t = StockTransaction::first();
        $this->assertSame('supplier', $t->type);
        $this->assertSame(6, $t->total_qty);
        $this->assertSame(272.0, (float) $t->total_amount); // 4×48 + 2×40
        $this->assertCount(2, $t->lines);
    }

    public function test_a_customer_invoice_deducts_stock_and_records_revenue(): void
    {
        $item = StockItem::create(['brand' => 'Hankook', 'size' => '195/65 R15', 'grade' => 'Grade B', 'qty' => 8, 'cost' => 25]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/stock/customer-invoice', [
                'customer_name' => 'Kampala Transport Co.',
                'contact'       => '0700000000',
                'lines' => [
                    ['stock_item_id' => $item->id, 'qty' => 3, 'unit_price' => 31.25],
                ],
            ])
            ->assertCreated();

        $this->assertSame(5, $item->fresh()->qty);

        $meta = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/stock')
            ->json('meta');
        $this->assertSame(5, $meta['total_pcs']);
        $this->assertSame(93.75, (float) $meta['total_sales']);
    }

    public function test_a_shortage_refuses_the_whole_invoice_with_same_item_lines_summed(): void
    {
        $item = StockItem::create(['brand' => 'Hankook', 'size' => '195/65 R15', 'grade' => 'Grade B', 'qty' => 5, 'cost' => 25]);
        $other = StockItem::create(['brand' => 'Michelin', 'size' => '225/45 R17', 'grade' => 'Grade A', 'qty' => 10, 'cost' => 60]);

        // Two lines of the same item, 3 + 3 = 6 > 5 in stock: each line
        // alone would pass, summed they must not.
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/stock/customer-invoice', [
                'customer_name' => 'Overbooked GmbH',
                'lines' => [
                    ['stock_item_id' => $item->id, 'qty' => 3, 'unit_price' => 30],
                    ['stock_item_id' => $item->id, 'qty' => 3, 'unit_price' => 30],
                    ['stock_item_id' => $other->id, 'qty' => 1, 'unit_price' => 80],
                ],
            ])
            ->assertStatus(422);

        // NOTHING moved — the refusal covers the whole invoice.
        $this->assertSame(5, $item->fresh()->qty);
        $this->assertSame(10, $other->fresh()->qty);
        $this->assertSame(0, StockTransaction::count());
    }

    public function test_reads_need_finance_view_and_writes_need_finance_manage(): void
    {
        // order_manager holds finance.view but NOT finance.manage
        $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->getJson('/api/v1/admin/stock')
            ->assertOk();

        $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->postJson('/api/v1/admin/stock/supplier-invoice', [
                'supplier_name' => 'X', 'invoice_no' => 'Y',
                'lines' => [['brand' => 'A', 'size' => 'B', 'grade' => 'Grade A', 'qty' => 1, 'unit_cost' => 1]],
            ])
            ->assertForbidden();

        $this->actingAs($this->admin('marketing'), 'sanctum')
            ->getJson('/api/v1/admin/stock')
            ->assertForbidden();
    }
}
