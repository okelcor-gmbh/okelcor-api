<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Product;
use App\Services\EbaySellingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The eBay pricing audit — the tool built after the business discovered
 * eBay sales were loss-making. The margin math is the feature, so the
 * math is what gets tested.
 */
class EbayPricingAuditTest extends TestCase
{
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.ebay_sell.fee_percent'           => 10.0,
            'services.ebay_sell.fee_fixed'             => 0.35,
            'services.ebay_sell.thin_margin_percent'   => 8.0,
            'services.ebay_sell.target_margin_percent' => 15.0,
        ]);

        Schema::disableForeignKeyConstraints();
        foreach (['ebay_listing_logs', 'order_items', 'orders', 'products', 'admin_security_events', 'admin_users'] as $t) {
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

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->nullable();
            $table->string('brand')->nullable();
            $table->string('name');
            $table->string('size')->nullable();
            $table->string('type')->nullable();
            $table->string('season')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('price_b2b', 10, 2)->nullable();
            $table->decimal('price_b2c', 10, 2)->nullable();
            $table->decimal('cost_price', 10, 2)->nullable();
            $table->integer('stock')->default(0);
            $table->boolean('ebay_listed')->default(false);
            $table->string('ebay_item_id')->nullable();
            $table->string('ebay_offer_id')->nullable();
            $table->string('ebay_status')->nullable();
            $table->timestamp('ebay_last_synced_at')->nullable();
            $table->text('ebay_sync_error')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_ref')->nullable();
            $table->string('source', 20)->default('website');
            $table->decimal('total', 10, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('sku')->nullable();
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->integer('quantity')->default(1);
            $table->decimal('line_total', 10, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('ebay_listing_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('admin_user_id')->nullable();
            $table->string('sku')->nullable();
            $table->string('action', 40);
            $table->string('ebay_item_id')->nullable();
            $table->string('ebay_offer_id')->nullable();
            $table->string('status', 30)->nullable();
            $table->text('error_message')->nullable();
            $table->integer('response_code')->nullable();
            $table->json('payload_summary')->nullable();
            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();
    }

    protected function tearDown(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (['ebay_listing_logs', 'order_items', 'orders', 'products', 'admin_security_events', 'admin_users'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::enableForeignKeyConstraints();

        parent::tearDown();
    }

    private function admin(string $role = 'admin'): AdminUser
    {
        return AdminUser::create([
            'name' => 'Admin ' . (++$this->seq), 'email' => 'a' . $this->seq . uniqid() . '@okelcor.test',
            'role' => $role, 'password' => Hash::make('secret-pass-123'),
            'is_active' => true, 'two_factor_confirmed_at' => now(),
        ]);
    }

    private function listedProduct(array $attrs = []): Product
    {
        return Product::create(array_merge([
            'sku'   => 'SKU-' . uniqid(),
            'brand' => 'Michelin',
            'name'  => 'Pilot Sport',
            'price' => 100,
            'stock' => 4,
            'ebay_listed'  => true,
            'ebay_item_id' => '110123',
            'ebay_status'  => 'active',
        ], $attrs));
    }

    public function test_the_margin_math_finds_the_loss_makers(): void
    {
        // price 100, cost 95: fee = 10% + 0.35 = 10.35 → net = -5.35 → LOSS
        $loss = $this->listedProduct(['sku' => 'LOSS-1', 'price' => 100, 'cost_price' => 95]);
        // price 100, cost 85: net = 4.65 → 4.65% < 8% → THIN
        $this->listedProduct(['sku' => 'THIN-1', 'price' => 100, 'cost_price' => 85]);
        // price 100, cost 70: net = 19.65 → HEALTHY
        $this->listedProduct(['sku' => 'OK-1', 'price' => 100, 'cost_price' => 70]);
        // no cost price → MISSING_COST
        $this->listedProduct(['sku' => 'NOCOST-1', 'price' => 100, 'cost_price' => null]);
        // not listed on eBay → not on the board at all
        Product::create(['sku' => 'WEB-1', 'name' => 'Web only', 'price' => 50, 'ebay_listed' => false]);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/ebay/audit')
            ->assertOk();

        $rows = collect($response->json('data'))->keyBy('sku');

        $this->assertCount(4, $rows);
        $this->assertSame('loss', $rows['LOSS-1']['verdict']);
        $this->assertSame(-5.35, $rows['LOSS-1']['net_margin']);
        $this->assertSame('thin', $rows['THIN-1']['verdict']);
        $this->assertSame('healthy', $rows['OK-1']['verdict']);
        $this->assertSame('missing_cost', $rows['NOCOST-1']['verdict']);

        // Suggested price reaches target margin after fees:
        // (95 + 0.35) / (1 - 0.10 - 0.15) = 127.13
        $this->assertSame(127.13, $rows['LOSS-1']['suggested_price']);

        $meta = $response->json('meta');
        $this->assertSame(1, $meta['counts']['loss']);
        $this->assertSame(1, $meta['counts']['thin']);
        $this->assertSame(1, $meta['counts']['missing_cost']);
        $this->assertEquals(10.0, $meta['fee_model']['fee_percent']);
        $this->assertSame($loss->id, $rows['LOSS-1']['id']);
    }

    public function test_real_ebay_sales_evidence_joins_by_sku(): void
    {
        $this->listedProduct(['sku' => 'SOLD-1', 'price' => 100, 'cost_price' => 70]);

        $ebayOrder = DB::table('orders')->insertGetId([
            'source' => 'ebay', 'total' => 180, 'created_at' => now()->subDays(5), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            ['order_id' => $ebayOrder, 'sku' => 'SOLD-1', 'unit_price' => 90, 'quantity' => 2, 'line_total' => 180, 'created_at' => now(), 'updated_at' => now()],
        ]);
        // A website order for the same SKU must NOT count as eBay evidence.
        $webOrder = DB::table('orders')->insertGetId([
            'source' => 'website', 'total' => 500, 'created_at' => now()->subDays(2), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            ['order_id' => $webOrder, 'sku' => 'SOLD-1', 'unit_price' => 250, 'quantity' => 2, 'line_total' => 500, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $row = collect($this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/ebay/audit')
            ->json('data'))->firstWhere('sku', 'SOLD-1');

        $this->assertSame(2, $row['sold_90d']['units']);
        $this->assertSame(90.0, (float) $row['sold_90d']['avg_price']);
    }

    public function test_apply_price_updates_site_and_ebay_and_logs_the_correction(): void
    {
        $product = $this->listedProduct(['sku' => 'FIX-1', 'price' => 100, 'cost_price' => 95]);

        $this->mock(EbaySellingService::class)
            ->shouldReceive('updateListing')
            ->once()
            ->andReturn(['offer_id' => 'OFFER-9', 'listing_id' => '110123']);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/ebay/audit/{$product->id}/apply-price", ['price' => 127.13])
            ->assertOk()
            ->assertJsonPath('data.price', 127.13);

        $this->assertSame(127.13, (float) $product->fresh()->price);
        $this->assertDatabaseHas('ebay_listing_logs', [
            'sku'    => 'FIX-1',
            'action' => 'audit_price_change',
        ]);
    }

    public function test_a_rejected_ebay_update_rolls_the_price_back(): void
    {
        $product = $this->listedProduct(['sku' => 'ROLL-1', 'price' => 100, 'cost_price' => 80]);

        $this->mock(EbaySellingService::class)
            ->shouldReceive('updateListing')
            ->andThrow(new \RuntimeException('offer not found'));

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/ebay/audit/{$product->id}/apply-price", ['price' => 120])
            ->assertStatus(502)
            ->assertJsonPath('code', 'ebay_update_failed');

        $this->assertSame(100.0, (float) $product->fresh()->price, 'price must roll back when eBay refuses');
    }

    public function test_only_ebay_manage_roles_can_audit(): void
    {
        $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->getJson('/api/v1/admin/ebay/audit')
            ->assertForbidden();

        $this->actingAs($this->admin('marketing'), 'sanctum')
            ->getJson('/api/v1/admin/ebay/audit')
            ->assertForbidden();
    }
}
