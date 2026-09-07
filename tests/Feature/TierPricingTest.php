<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Product;
use App\Services\EbaySellingService;
use App\Services\TierPricingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The tier pricing tool — the model agreed with the order manager:
 * Tyre100 cost × tier margin (premium 15 / midrange 20 / budget 30),
 * website adds the Stripe 3%, eBay swaps that for the 9.5% uplift.
 * The formula IS the feature, so the formula is what gets tested.
 */
class TierPricingTest extends TestCase
{
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.pricing.premium_margin_percent'  => 15.0,
            'services.pricing.midrange_margin_percent' => 20.0,
            'services.pricing.budget_margin_percent'   => 30.0,
            'services.pricing.stripe_fee_percent'      => 3.0,
            'services.pricing.ebay_uplift_percent'     => 9.5,
        ]);

        Schema::disableForeignKeyConstraints();
        foreach (['products', 'admin_security_events', 'admin_users'] as $t) {
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

        $this->createProductsTable(withTierColumn: true);

        Schema::enableForeignKeyConstraints();
    }

    private function createProductsTable(bool $withTierColumn): void
    {
        Schema::dropIfExists('products');
        Schema::create('products', function (Blueprint $table) use ($withTierColumn) {
            $table->id();
            $table->string('sku')->nullable();
            $table->string('brand')->nullable();
            $table->string('name');
            $table->string('size')->nullable();
            $table->string('type')->nullable();
            $table->string('season')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('cost_price', 10, 2)->nullable();
            if ($withTierColumn) {
                $table->string('price_tier', 20)->nullable();
            }
            $table->integer('stock')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('ebay_listed')->default(false);
            $table->string('ebay_item_id')->nullable();
            $table->string('ebay_offer_id')->nullable();
            $table->string('ebay_status')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (['products', 'admin_security_events', 'admin_users'] as $t) {
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

    private function product(array $attrs = []): Product
    {
        $this->seq++;

        return Product::create(array_merge([
            'sku'   => 'SKU-' . $this->seq . uniqid(),
            'brand' => 'Michelin',
            'name'  => 'Pilot Sport',
            'price' => 100,
            'stock' => 4,
        ], $attrs));
    }

    public function test_the_formula_prices_all_three_tiers(): void
    {
        $svc = app(TierPricingService::class);

        // premium: 100 × 1.15 = 115 → website 118.45, eBay 125.93 (115 × 1.095 = 125.925 → .93)
        $this->assertSame(118.45, $svc->websitePrice(100, 'premium'));
        $this->assertSame(125.93, $svc->ebayPrice(100, 'premium'));

        // midrange: 80 × 1.20 = 96 → website 98.88, eBay 105.12
        $this->assertSame(98.88, $svc->websitePrice(80, 'midrange'));
        $this->assertSame(105.12, $svc->ebayPrice(80, 'midrange'));

        // budget: 50 × 1.30 = 65 → website 66.95, eBay 71.18 (65 × 1.095 = 71.175 → .18)
        $this->assertSame(66.95, $svc->websitePrice(50, 'budget'));
        $this->assertSame(71.18, $svc->ebayPrice(50, 'budget'));

        // Unknown tier prices nothing — never a silent guess.
        $this->assertNull($svc->websitePrice(100, 'luxury'));
    }

    public function test_the_preview_shows_both_channel_prices_and_what_is_not_ready(): void
    {
        $ready    = $this->product(['sku' => 'RDY-1', 'price' => 100, 'cost_price' => 100, 'price_tier' => 'premium']);
        $noTier   = $this->product(['sku' => 'NT-1', 'price' => 80, 'cost_price' => 60]);
        $noCost   = $this->product(['sku' => 'NC-1', 'price' => 70, 'price_tier' => 'budget']);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/pricing/preview')
            ->assertOk();

        $rows = collect($response->json('data'))->keyBy('sku');

        $this->assertSame(118.45, $rows['RDY-1']['website_price']);
        $this->assertSame(125.93, $rows['RDY-1']['ebay_price']);
        $this->assertSame(18.45, $rows['RDY-1']['price_change']);
        $this->assertNull($rows['NT-1']['website_price']);
        $this->assertNull($rows['NC-1']['website_price']);

        $meta = $response->json('meta');
        $this->assertSame(3, $meta['counts']['total']);
        $this->assertSame(1, $meta['counts']['missing_tier']);
        $this->assertSame(1, $meta['counts']['missing_cost']);
        $this->assertSame(1, $meta['counts']['ready']);
        $this->assertSame(1, $meta['counts']['would_change']);
        $this->assertEquals(15.0, $meta['pricing_model']['margins']['premium']);
        $this->assertEquals(9.5, $meta['pricing_model']['ebay_uplift_percent']);
        $this->assertSame($ready->id, $rows['RDY-1']['id']);
        $this->assertNotNull($noTier->id);
        $this->assertNotNull($noCost->id);
    }

    public function test_tiers_can_be_assigned_by_brand_and_by_ids(): void
    {
        $m1 = $this->product(['brand' => 'Michelin']);
        $m2 = $this->product(['brand' => 'Michelin']);
        $r1 = $this->product(['brand' => 'Rapid']);

        // The brand sweep — Michelin is premium everywhere.
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/pricing/set-tier', ['tier' => 'premium', 'brand' => 'Michelin'])
            ->assertOk()
            ->assertJsonPath('data.updated', 2);

        $this->assertSame('premium', $m1->fresh()->price_tier);
        $this->assertSame('premium', $m2->fresh()->price_tier);
        $this->assertNull($r1->fresh()->price_tier);

        // Ticked ids.
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/pricing/set-tier', ['tier' => 'budget', 'ids' => [$r1->id]])
            ->assertOk()
            ->assertJsonPath('data.updated', 1);

        $this->assertSame('budget', $r1->fresh()->price_tier);

        // A made-up tier is refused, not stored.
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/pricing/set-tier', ['tier' => 'luxury', 'ids' => [$r1->id]])
            ->assertStatus(422);
    }

    public function test_apply_reprices_ready_products_and_reports_the_rest(): void
    {
        $ready  = $this->product(['sku' => 'AP-1', 'price' => 100, 'cost_price' => 100, 'price_tier' => 'premium']);
        $noTier = $this->product(['sku' => 'AP-2', 'price' => 80, 'cost_price' => 60]);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/pricing/apply', ['ids' => [$ready->id, $noTier->id]])
            ->assertOk();

        $this->assertSame(1, $response->json('meta.updated_count'));
        $this->assertSame(1, $response->json('meta.skipped_count'));
        $this->assertSame('AP-2', $response->json('data.skipped.0.sku'));
        $this->assertSame(118.45, (float) $ready->fresh()->price);
        $this->assertSame(80.0, (float) $noTier->fresh()->price);
    }

    public function test_apply_all_sweeps_only_what_the_formula_can_price(): void
    {
        $premium = $this->product(['sku' => 'ALL-1', 'price' => 100, 'cost_price' => 100, 'price_tier' => 'premium']);
        $budget  = $this->product(['sku' => 'ALL-2', 'price' => 40, 'cost_price' => 50, 'price_tier' => 'budget']);
        $noTier  = $this->product(['sku' => 'ALL-3', 'price' => 80, 'cost_price' => 60]);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/pricing/apply', ['all' => true])
            ->assertOk();

        $this->assertSame(2, $response->json('meta.updated_count'));
        $this->assertSame(118.45, (float) $premium->fresh()->price);
        $this->assertSame(66.95, (float) $budget->fresh()->price);
        $this->assertSame(80.0, (float) $noTier->fresh()->price, 'a tier-less product must never be repriced');
    }

    public function test_the_ebay_offer_carries_the_formula_price_with_fallback(): void
    {
        $svc = app(TierPricingService::class);

        $tiered = $this->product(['price' => 118.45, 'cost_price' => 100, 'price_tier' => 'premium']);
        $this->assertSame(125.93, $svc->ebayOfferPriceFor($tiered));

        // Outside the tier system, the offer keeps the plain website price.
        $plain = $this->product(['price' => 89.90]);
        $this->assertSame(89.90, $svc->ebayOfferPriceFor($plain));

        // And buildOfferBody — the body actually PUT to eBay — agrees.
        $method = new \ReflectionMethod(EbaySellingService::class, 'buildOfferBody');
        $body = $method->invoke(app(EbaySellingService::class), $tiered->fresh());
        $this->assertSame('125.93', $body['pricingSummary']['price']['value']);
    }

    public function test_pre_migration_state_degrades_to_a_clear_503(): void
    {
        $this->createProductsTable(withTierColumn: false);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/pricing/preview')
            ->assertStatus(503);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/pricing/apply', ['all' => true])
            ->assertStatus(503);
    }

    public function test_only_pricing_manage_roles_can_use_the_tool(): void
    {
        foreach (['order_manager', 'marketing', 'editor'] as $role) {
            $this->actingAs($this->admin($role), 'sanctum')
                ->getJson('/api/v1/admin/pricing/preview')
                ->assertForbidden();

            $this->actingAs($this->admin($role), 'sanctum')
                ->postJson('/api/v1/admin/pricing/apply', ['all' => true])
                ->assertForbidden();
        }
    }
}
