<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Promotion insight — the marketing team's two questions: are we selling
 * used or new tyres, and which sizes do satisfied (repeat) buyers keep
 * coming back for, in which countries?
 */
class ProductMixInsightTest extends TestCase
{
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::disableForeignKeyConstraints();
        foreach (['order_items', 'orders', 'products', 'admin_security_events', 'admin_users'] as $t) {
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
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('cost_price', 10, 2)->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('customer_email')->nullable();
            $table->string('country', 100)->nullable();
            $table->string('status', 40)->default('pending');
            $table->string('source', 20)->default('website');
            $table->decimal('total', 10, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('sku')->nullable();
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->integer('quantity')->default(1);
            $table->decimal('line_total', 10, 2)->default(0);
            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();
    }

    protected function tearDown(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (['order_items', 'orders', 'products', 'admin_security_events', 'admin_users'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::enableForeignKeyConstraints();

        parent::tearDown();
    }

    private function admin(string $role = 'marketing'): AdminUser
    {
        return AdminUser::create([
            'name' => 'A' . (++$this->seq), 'email' => 'a' . $this->seq . uniqid() . '@okelcor.test',
            'role' => $role, 'password' => Hash::make('secret-pass-123'),
            'is_active' => true, 'two_factor_confirmed_at' => now(),
        ]);
    }

    private function order(string $email, string $country, array $items, string $source = 'website'): void
    {
        $orderId = DB::table('orders')->insertGetId([
            'customer_email' => $email, 'country' => $country, 'status' => 'delivered',
            'source' => $source, 'total' => 0, 'created_at' => now()->subDays(5), 'updated_at' => now(),
        ]);
        foreach ($items as [$productId, $sku, $price, $qty]) {
            DB::table('order_items')->insert([
                'order_id' => $orderId, 'product_id' => $productId, 'sku' => $sku,
                'unit_price' => $price, 'quantity' => $qty, 'line_total' => $price * $qty,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function test_condition_split_and_repeat_buyer_bundles(): void
    {
        $used = DB::table('products')->insertGetId([
            'sku' => 'U-1', 'name' => 'Used tyre', 'type' => 'used', 'size' => '205/55 R16',
            'price' => 40, 'cost_price' => 20, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $new = DB::table('products')->insertGetId([
            'sku' => 'N-1', 'name' => 'New tyre', 'type' => 'pcr', 'size' => '195/65 R15',
            'price' => 90, 'cost_price' => 60, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Ana (Croatia) orders used 205/55 R16 twice → repeat buyer → bundle evidence.
        $this->order('ana@zagreb.hr', 'Croatia', [[$used, 'U-1', 40, 4]]);
        $this->order('ana@zagreb.hr', 'Croatia', [[$used, 'U-1', 40, 4]]);
        // Ben (Croatia) also repeats on the same used size.
        $this->order('ben@split.hr', 'Croatia', [[$used, 'U-1', 40, 2]]);
        $this->order('ben@split.hr', 'Croatia', [[$used, 'U-1', 40, 2]]);
        // One-off German buyer of new tyres via eBay; matched by sku only.
        $this->order('carl@berlin.de', 'Germany', [[null, 'N-1', 90, 2]], 'ebay');
        // A line no product matches → counted as unknown, never guessed.
        $this->order('dora@wien.at', 'Austria', [[null, 'MYSTERY', 50, 1]]);
        // Cancelled orders must not count.
        DB::table('orders')->insertGetId([
            'customer_email' => 'x@y.z', 'country' => 'Croatia', 'status' => 'cancelled',
            'source' => 'website', 'total' => 999, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin('marketing'), 'sanctum')
            ->getJson('/api/v1/admin/analytics/product-mix?days=90')
            ->assertOk();

        $byCondition = collect($response->json('data.by_condition'))->keyBy('condition');

        $this->assertSame(12, $byCondition['used']['units']);
        $this->assertEquals(480, $byCondition['used']['revenue']);
        $this->assertEquals(240, $byCondition['used']['est_margin']);   // (40-20)*12
        $this->assertSame(2, $byCondition['new']['units']);
        $this->assertSame(2, $byCondition['new']['channels']['ebay']);
        $this->assertSame(1, $byCondition['unknown']['units']);

        $topSize = $response->json('data.top_sizes.0');
        $this->assertSame('205/55 R16', $topSize['size']);
        $this->assertSame('used', $topSize['condition']);
        $this->assertSame(2, $topSize['repeat_customers']);
        $this->assertSame('Croatia', $topSize['countries'][0]['country']);

        $bundle = $response->json('data.bundles.0');
        $this->assertSame('Croatia', $bundle['country']);
        $this->assertStringContainsString('205/55 R16', $bundle['suggestion']);
        $this->assertStringContainsString('Croatia', $bundle['suggestion']);

        $this->assertSame(1, $response->json('meta.unknown_lines'));
    }

    public function test_marketing_can_view_and_support_cannot(): void
    {
        $this->actingAs($this->admin('marketing'), 'sanctum')
            ->getJson('/api/v1/admin/analytics/product-mix')
            ->assertOk();

        $this->actingAs($this->admin('support'), 'sanctum')
            ->getJson('/api/v1/admin/analytics/product-mix')
            ->assertForbidden();
    }
}
