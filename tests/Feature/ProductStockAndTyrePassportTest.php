<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Product;
use App\Models\SiteSetting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Covers the premium-UX backend pass:
 *
 *  1. `stock` is writable by an admin. It was previously settable ONLY by the
 *     Wix/Rapid importers, while the public payload exposed it all along —
 *     so a wrong quantity could not be corrected from the panel.
 *  2. `estimated_dispatch_days` is null until an admin sets a real number,
 *     and never shown for an out-of-stock product. The frontend renders this
 *     verbatim, so it must not carry an unapproved delivery promise.
 *  3. `tyre_batch` (the "tyre passport") round-trips, and stays null until
 *     someone actually enters data.
 *
 * Does NOT use RefreshDatabase: the full migration set includes a MySQL-only
 * legacy migration sqlite can't run. Creates only the tables this test
 * touches, same pattern as MediaLibraryTest / BulkEmailCampaignTest.
 */
class ProductStockAndTyrePassportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        Schema::disableForeignKeyConstraints();

        foreach (['product_images', 'products', 'brands', 'site_settings', 'admin_users'] as $table) {
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

        Schema::create('site_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->string('type', 20)->default('string');
            $table->string('group', 50)->default('general');
            $table->timestamp('updated_at')->useCurrent();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 50)->unique();
            $table->string('brand', 100);
            $table->string('name', 200);
            $table->string('size', 50);
            $table->string('spec', 50)->default('');
            $table->string('width', 10)->nullable();
            $table->string('height', 10)->nullable();
            $table->string('rim', 10)->nullable();
            $table->string('load_index', 10)->nullable();
            $table->string('speed_rating', 5)->nullable();
            $table->integer('stock')->nullable();
            $table->decimal('cost_price', 10, 2)->nullable();
            $table->string('season', 20);
            $table->string('type', 10);
            $table->decimal('price', 10, 2);
            $table->decimal('price_b2b', 10, 2)->nullable();
            $table->decimal('price_b2c', 10, 2)->nullable();
            $table->text('description');
            $table->string('primary_image', 500)->nullable();
            $table->tinyInteger('is_active')->default(1);
            $table->tinyInteger('in_stock')->default(1);
            $table->integer('sort_order')->default(0);
            $table->string('ean', 50)->nullable();
            $table->tinyInteger('ebay_listed')->default(0);
            $table->string('ebay_item_id', 100)->nullable();
            $table->string('ebay_offer_id', 100)->nullable();
            $table->string('ebay_status', 50)->nullable();
            $table->timestamp('ebay_last_synced_at')->nullable();
            $table->text('ebay_sync_error')->nullable();

            // The columns this session adds.
            $table->string('condition_grade', 10)->nullable();
            $table->decimal('tread_depth_mm', 4, 1)->nullable();
            $table->string('dot_code', 20)->nullable();
            $table->date('inspection_date')->nullable();
            $table->json('inspection_photos')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        // The public product payload joins brand logos.
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('logo', 500)->nullable();
            $table->integer('sort_order')->default(0);
            $table->tinyInteger('is_active')->default(1);
            $table->timestamps();
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('path', 500);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();
    }

    protected function tearDown(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach (['product_images', 'products', 'brands', 'site_settings', 'admin_users'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();

        parent::tearDown();
    }

    private function admin(string $role = 'editor'): AdminUser
    {
        return AdminUser::create([
            'name'                    => 'Test Admin',
            'email'                   => 'admin' . uniqid() . '@test.com',
            'role'                    => $role,
            'password'                => Hash::make('secret-pass-123'),
            'is_active'               => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function product(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'sku'         => 'SKU-' . uniqid(),
            'brand'       => 'Michelin',
            'name'        => 'Primacy 4',
            'size'        => '225/45R18',
            'spec'        => '95Y',
            'season'      => 'Summer',
            'type'        => 'PCR',
            'price'       => 120.00,
            'description' => 'A tyre.',
            'is_active'   => true,
            'in_stock'    => true,
            'stock'       => 24,
        ], $overrides));
    }

    // ------------------------------------------------------------------
    // 1. Stock is writable by an admin
    // ------------------------------------------------------------------

    public function test_admin_can_correct_stock_quantity_via_update(): void
    {
        $product = $this->product(['stock' => 24]);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/v1/admin/products/{$product->id}", ['stock' => 7])
            ->assertOk()
            ->assertJsonPath('data.stock', 7);

        $this->assertSame(7, $product->fresh()->stock);
    }

    public function test_admin_product_payload_exposes_stock(): void
    {
        $product = $this->product(['stock' => 13]);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/admin/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.stock', 13);
    }

    public function test_setting_stock_to_zero_clears_the_in_stock_flag(): void
    {
        $product = $this->product(['stock' => 5, 'in_stock' => true]);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/v1/admin/products/{$product->id}", ['stock' => 0])
            ->assertOk()
            ->assertJsonPath('data.in_stock', false);
    }

    public function test_explicit_in_stock_overrides_the_derived_value(): void
    {
        $product = $this->product(['stock' => 0, 'in_stock' => false]);

        // An admin can still deliberately show a product with no counted stock.
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/v1/admin/products/{$product->id}", [
                'stock'    => 0,
                'in_stock' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.in_stock', true);
    }

    public function test_bulk_stock_accepts_a_quantity(): void
    {
        $a = $this->product(['stock' => 1]);
        $b = $this->product(['stock' => 2]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/products/bulk-stock', [
                'all'   => false,
                'ids'   => [$a->id, $b->id],
                'stock' => 40,
            ])
            ->assertOk()
            ->assertJsonPath('affected', 2);

        $this->assertSame(40, $a->fresh()->stock);
        $this->assertSame(40, $b->fresh()->stock);
        $this->assertTrue((bool) $a->fresh()->in_stock);
    }

    public function test_bulk_stock_still_accepts_boolean_only_callers(): void
    {
        // Backward compatibility: the existing frontend sends in_stock alone.
        $product = $this->product(['stock' => 9, 'in_stock' => true]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/products/bulk-stock', [
                'all'      => false,
                'ids'      => [$product->id],
                'in_stock' => false,
            ])
            ->assertOk();

        $fresh = $product->fresh();
        $this->assertFalse((bool) $fresh->in_stock);
        $this->assertSame(9, $fresh->stock, 'Quantity must be left alone when only the flag is sent.');
    }

    public function test_bulk_stock_rejects_a_request_with_neither_field(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/products/bulk-stock', ['all' => true])
            ->assertStatus(422);
    }

    public function test_negative_stock_is_rejected(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/v1/admin/products/{$product->id}", ['stock' => -3])
            ->assertStatus(422)
            ->assertJsonValidationErrors('stock');
    }

    // ------------------------------------------------------------------
    // 2. estimated_dispatch_days
    // ------------------------------------------------------------------

    public function test_dispatch_days_is_null_when_the_setting_is_unset(): void
    {
        SiteSetting::create(['key' => 'estimated_dispatch_days', 'value' => '', 'type' => 'string', 'group' => 'shop']);
        $product = $this->product();

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.estimated_dispatch_days', null);
    }

    public function test_dispatch_days_is_null_when_the_setting_row_is_missing_entirely(): void
    {
        $product = $this->product();

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.estimated_dispatch_days', null);
    }

    public function test_dispatch_days_is_returned_once_an_admin_sets_it(): void
    {
        SiteSetting::create(['key' => 'estimated_dispatch_days', 'value' => '2', 'type' => 'string', 'group' => 'shop']);
        $product = $this->product();

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.estimated_dispatch_days', 2);
    }

    public function test_dispatch_days_is_withheld_for_an_out_of_stock_product(): void
    {
        SiteSetting::create(['key' => 'estimated_dispatch_days', 'value' => '2', 'type' => 'string', 'group' => 'shop']);
        $product = $this->product(['in_stock' => false, 'stock' => 0]);

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.estimated_dispatch_days', null);
    }

    public function test_public_listing_exposes_stock_and_dispatch_days(): void
    {
        SiteSetting::create(['key' => 'estimated_dispatch_days', 'value' => '3', 'type' => 'string', 'group' => 'shop']);
        $this->product(['stock' => 24]);

        // The listing intentionally returns nothing until a filter is applied.
        $this->getJson('/api/v1/products?type=PCR')
            ->assertOk()
            ->assertJsonPath('data.0.stock', 24)
            ->assertJsonPath('data.0.estimated_dispatch_days', 3);
    }

    // ------------------------------------------------------------------
    // 3. Tyre passport
    // ------------------------------------------------------------------

    public function test_tyre_batch_is_null_until_data_is_entered(): void
    {
        $product = $this->product();

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.tyre_batch', null);
    }

    public function test_admin_can_save_and_publicly_expose_a_tyre_passport(): void
    {
        $product = $this->product(['type' => 'Used']);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/v1/admin/products/{$product->id}", [
                'condition_grade' => 'A',
                'tread_depth_mm'  => 6.5,
                'dot_code'        => '2419',
                'inspection_date' => '2026-06-30',
            ])
            ->assertOk()
            ->assertJsonPath('data.tyre_batch.condition_grade', 'A')
            ->assertJsonPath('data.tyre_batch.dot_code', '2419');

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.tyre_batch.condition_grade', 'A')
            ->assertJsonPath('data.tyre_batch.tread_depth_mm', 6.5)
            ->assertJsonPath('data.tyre_batch.inspection_date', '2026-06-30');
    }

    public function test_inspection_photos_upload_and_are_returned_as_absolute_urls(): void
    {
        $product = $this->product();

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/products/{$product->id}/inspection-photos", [
                'photos' => [
                    UploadedFile::fake()->image('tread-1.jpg', 800, 600),
                    UploadedFile::fake()->image('tread-2.jpg', 800, 600),
                ],
            ])
            ->assertStatus(201);

        $urls = $response->json('data.tyre_batch.inspection_photos');
        $this->assertCount(2, $urls);
        $this->assertStringStartsWith('http', $urls[0]);

        // And they surface on the public payload for the passport card.
        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.tyre_batch.inspection_photos');
    }

    public function test_inspection_photo_can_be_deleted_by_index(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/products/{$product->id}/inspection-photos", [
                'photos' => [
                    UploadedFile::fake()->image('tread-1.jpg'),
                    UploadedFile::fake()->image('tread-2.jpg'),
                ],
            ])->assertStatus(201);

        $stored = $product->fresh()->inspection_photos;
        $this->assertCount(2, $stored);

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/v1/admin/products/{$product->id}/inspection-photos/0")
            ->assertOk()
            ->assertJsonCount(1, 'data.tyre_batch.inspection_photos');

        // The removed file is gone from disk, and the array is re-indexed.
        Storage::disk('public')->assertMissing($stored[0]);
        $this->assertSame([$stored[1]], $product->fresh()->inspection_photos);
    }

    public function test_deleting_an_out_of_range_inspection_photo_returns_404(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/v1/admin/products/{$product->id}/inspection-photos/5")
            ->assertStatus(404);
    }

    // ------------------------------------------------------------------
    // 4. Deploy-order safety
    // ------------------------------------------------------------------

    /**
     * The public payload must degrade to nulls — not 500 — if this code ever
     * reaches production ahead of its migration. Frontend renders these
     * fields already, so a code-before-migration window has to be harmless
     * rather than merely unlikely.
     */
    public function test_public_payload_survives_code_deployed_before_migration(): void
    {
        $product = $this->product(['stock' => 24]);

        // Simulate production without migration #24 applied.
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'condition_grade',
                'tread_depth_mm',
                'dot_code',
                'inspection_date',
                'inspection_photos',
            ]);
        });

        // ...and without migration #25's site_settings row.
        $this->assertNull(SiteSetting::where('key', 'estimated_dispatch_days')->first());

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.stock', 24)
            ->assertJsonPath('data.tyre_batch', null)
            ->assertJsonPath('data.estimated_dispatch_days', null);

        $this->getJson('/api/v1/products?type=PCR')
            ->assertOk()
            ->assertJsonPath('data.0.tyre_batch', null)
            ->assertJsonPath('data.0.estimated_dispatch_days', null);
    }

    public function test_tyre_passport_endpoints_require_products_edit_permission(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin('viewer'), 'sanctum')
            ->postJson("/api/v1/admin/products/{$product->id}/inspection-photos", [
                'photos' => [UploadedFile::fake()->image('tread.jpg')],
            ])
            ->assertStatus(403);
    }
}
