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
        foreach (['ebay_live_listings', 'ebay_listing_logs', 'order_items', 'orders', 'products', 'admin_security_events', 'admin_users'] as $t) {
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

        Schema::create('ebay_live_listings', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('title')->nullable();
            $table->string('offer_id')->nullable();
            $table->string('listing_id')->nullable();
            $table->string('status', 30)->default('unknown');
            $table->decimal('price', 10, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->integer('quantity')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->timestamp('fetched_at');
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
        foreach (['ebay_live_listings', 'ebay_listing_logs', 'order_items', 'orders', 'products', 'admin_security_events', 'admin_users'] as $t) {
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

    public function test_the_live_snapshot_reconciles_ebay_reality_against_the_catalogue(): void
    {
        // Our DB says 100 €; eBay is actually showing 79.90 → drift, and the
        // margin must be judged on what buyers see.
        $this->listedProduct(['sku' => 'DRIFT-1', 'price' => 100, 'cost_price' => 78]);
        // Marked listed in the DB, absent from the live snapshot → phantom.
        $this->listedProduct(['sku' => 'GHOST-1', 'price' => 50, 'cost_price' => 30]);

        DB::table('ebay_live_listings')->insert([
            ['sku' => 'DRIFT-1', 'status' => 'published', 'price' => 79.90, 'currency' => 'EUR',
             'quantity' => 4, 'fetched_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            // On eBay, unknown to the catalogue entirely.
            ['sku' => 'MYSTERY-9', 'status' => 'published', 'price' => 60, 'currency' => 'EUR',
             'quantity' => 1, 'fetched_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/ebay/audit')
            ->assertOk();

        $rows = collect($response->json('data'))->keyBy('sku');

        // Drift detected, margin computed on the LIVE price:
        // fee = 79.90*10% + 0.35 = 8.34 → net = 79.90 - 8.34 - 78 = -6.44 → LOSS
        $this->assertEquals(-20.10, $rows['DRIFT-1']['price_drift']);
        $this->assertEquals(79.90, $rows['DRIFT-1']['ebay_price']);
        $this->assertEquals(100, $rows['DRIFT-1']['db_price']);
        $this->assertSame('loss', $rows['DRIFT-1']['verdict']);

        $this->assertTrue($rows['GHOST-1']['live_missing']);

        $meta = $response->json('meta');
        $this->assertSame(1, $meta['counts']['price_drift']);
        $this->assertSame(1, $meta['counts']['live_missing']);
        $this->assertSame(1, $meta['counts']['unmatched']);
        $this->assertSame('MYSTERY-9', $meta['unmatched_listings'][0]['sku']);
        $this->assertSame(2, $meta['live']['total_on_ebay']);
        $this->assertNotNull($meta['live']['fetched_at']);
    }

    public function test_sync_live_dispatches_the_snapshot_job(): void
    {
        config(['queue.default' => 'sync']);
        \Illuminate\Support\Facades\Bus::fake([\App\Jobs\SyncEbayLiveListingsJob::class]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/ebay/audit/sync-live')
            ->assertStatus(202);

        \Illuminate\Support\Facades\Bus::assertDispatchedAfterResponse(\App\Jobs\SyncEbayLiveListingsJob::class);
    }

    public function test_the_snapshot_job_stores_what_ebay_returns(): void
    {
        $product = $this->listedProduct(['sku' => 'REAL-1', 'price' => 100, 'cost_price' => 70]);

        $this->mock(\App\Services\EbaySellingService::class)
            ->shouldReceive('fetchAllLiveListings')
            ->once()
            ->andReturn([
                ['sku' => 'REAL-1', 'offer_id' => 'O-1', 'listing_id' => '110', 'status' => 'published',
                 'price' => 88.5, 'currency' => 'EUR', 'quantity' => 3],
                ['sku' => 'ALIEN-1', 'offer_id' => 'O-2', 'listing_id' => '111', 'status' => 'published',
                 'price' => 42.0, 'currency' => 'EUR', 'quantity' => 1],
            ]);

        (new \App\Jobs\SyncEbayLiveListingsJob())->handle(app(\App\Services\EbaySellingService::class));

        $this->assertDatabaseHas('ebay_live_listings', [
            'sku' => 'REAL-1', 'product_id' => $product->id, 'price' => 88.5,
        ]);
        $this->assertDatabaseHas('ebay_live_listings', [
            'sku' => 'ALIEN-1', 'product_id' => null,
        ]);
    }

    public function test_the_snapshot_merges_classic_ebay_listings_the_inventory_api_cannot_see(): void
    {
        // The real-world failure this guards: the team lists by hand on
        // ebay.de (classic system), the Inventory API returns only the
        // panel-created listings, and the "audit" saw 2 of a whole store.
        config(['services.ebay.environment' => 'production']);
        \Illuminate\Support\Facades\Cache::put('ebay_sell_user_token_production', 'test-token', 300);

        $tradingXml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<GetMyeBaySellingResponse xmlns="urn:ebay:apis:eBLBaseComponents">
  <Ack>Success</Ack>
  <ActiveList>
    <ItemArray>
      <Item>
        <ItemID>555001</ItemID>
        <Title>Michelin Pilot Sport 225/45 R17 gebraucht</Title>
        <SellingStatus><CurrentPrice currencyID="EUR">64.90</CurrentPrice></SellingStatus>
        <QuantityAvailable>2</QuantityAvailable>
      </Item>
      <Item>
        <ItemID>110999</ItemID>
        <Title>Duplicate of an inventory listing</Title>
        <SKU>INV-1</SKU>
        <SellingStatus><CurrentPrice currencyID="EUR">80.00</CurrentPrice></SellingStatus>
        <QuantityAvailable>1</QuantityAvailable>
      </Item>
    </ItemArray>
    <PaginationResult><TotalNumberOfPages>1</TotalNumberOfPages></PaginationResult>
  </ActiveList>
</GetMyeBaySellingResponse>
XML;

        \Illuminate\Support\Facades\Http::fake([
            'api.ebay.com/sell/inventory/v1/inventory_item*' => \Illuminate\Support\Facades\Http::response([
                'inventoryItems' => [['sku' => 'INV-1']],
                'total'          => 1,
            ]),
            'api.ebay.com/sell/inventory/v1/offer*' => \Illuminate\Support\Facades\Http::response([
                'offers' => [[
                    'offerId' => 'O-1', 'status' => 'PUBLISHED',
                    'listing' => ['listingId' => '110999'],
                    'pricingSummary' => ['price' => ['value' => '80.00', 'currency' => 'EUR']],
                    'availableQuantity' => 1,
                ]],
            ]),
            'api.ebay.com/ws/api.dll' => \Illuminate\Support\Facades\Http::response($tradingXml),
        ]);

        $rows = collect(app(EbaySellingService::class)->fetchAllLiveListings());

        $this->assertCount(2, $rows, 'the duplicate classic listing must be merged, not doubled');

        $manual = $rows->firstWhere('listing_id', '555001');
        $this->assertNotNull($manual, 'the hand-made ebay.de listing must be in the snapshot');
        $this->assertSame('EBAY-555001', $manual['sku'], 'a SKU-less listing keys by its eBay item id');
        $this->assertSame(64.90, $manual['price']);
        $this->assertSame('Michelin Pilot Sport 225/45 R17 gebraucht', $manual['title']);
        $this->assertSame(2, $manual['quantity']);

        $this->assertSame('O-1', $rows->firstWhere('sku', 'INV-1')['offer_id']);
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
