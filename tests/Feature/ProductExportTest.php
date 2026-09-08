<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Product;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The product CSV export — now brand-scopable for the scripted-edit
 * round-trip (export one brand, edit descriptions in Python, import the
 * same file back by SKU).
 */
class ProductExportTest extends TestCase
{
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

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

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->nullable();
            $table->string('brand')->nullable();
            $table->string('name');
            $table->string('size')->nullable();
            $table->string('spec')->nullable();
            $table->string('season')->nullable();
            $table->string('type')->nullable();
            $table->string('width')->nullable();
            $table->string('height')->nullable();
            $table->string('rim')->nullable();
            $table->string('load_index')->nullable();
            $table->string('speed_rating')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('price_b2b', 10, 2)->nullable();
            $table->decimal('price_b2c', 10, 2)->nullable();
            $table->decimal('cost_price', 10, 2)->nullable();
            $table->text('description')->nullable();
            $table->integer('stock')->default(0);
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();
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

    public function test_brand_filter_exports_only_that_brand(): void
    {
        Product::create(['sku' => 'MICH-1', 'brand' => 'MICHELIN', 'name' => 'Pilot Sport', 'price' => 120]);
        Product::create(['sku' => 'MICH-2', 'brand' => 'MICHELIN', 'name' => 'Primacy 4', 'price' => 100]);
        Product::create(['sku' => 'RAP-1', 'brand' => 'Rapid', 'name' => 'P309', 'price' => 55]);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->get('/api/v1/admin/products/export?brand=MICHELIN');

        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('MICH-1', $csv);
        $this->assertStringContainsString('MICH-2', $csv);
        $this->assertStringNotContainsString('RAP-1', $csv);
        $this->assertStringContainsString('michelin', $response->headers->get('content-disposition'));
    }

    public function test_no_brand_exports_the_whole_catalogue(): void
    {
        Product::create(['sku' => 'MICH-1', 'brand' => 'MICHELIN', 'name' => 'Pilot Sport', 'price' => 120]);
        Product::create(['sku' => 'RAP-1', 'brand' => 'Rapid', 'name' => 'P309', 'price' => 55]);

        $csv = $this->actingAs($this->admin(), 'sanctum')
            ->get('/api/v1/admin/products/export')
            ->streamedContent();

        $this->assertStringContainsString('MICH-1', $csv);
        $this->assertStringContainsString('RAP-1', $csv);
    }

    public function test_export_requires_products_import_permission(): void
    {
        $this->actingAs($this->admin('marketing'), 'sanctum')
            ->get('/api/v1/admin/products/export?brand=MICHELIN')
            ->assertForbidden();
    }
}
