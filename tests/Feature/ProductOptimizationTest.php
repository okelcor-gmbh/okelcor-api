<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Product;
use App\Models\SiteSetting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Product optimization (Session 92) — the marketing team's brief, three asks:
 *
 *  1. SEO URLs: /shop/brand+productName+season instead of /shop/41.
 *  2. Rich-text product descriptions, "like how you did with the blog post".
 *  3. The Artikelmerkmale sheet — EU-label classes, 3PMSF, EPREL and the rest
 *     — editable in the admin panel and printed on the product page.
 *
 * The design constraint the tests keep asserting: half of those attributes
 * already exist as real columns (width, rim, load index, EAN …), so the sheet
 * is assembled from ONE catalogue (TyreSpecs) that knows where each attribute
 * lives. Nothing is stored twice, so nothing can disagree with itself.
 *
 * Minimal-schema sqlite harness — the full migration set contains MySQL-only
 * legacy migrations, same as ProductStockAndTyrePassportTest.
 */
class ProductOptimizationTest extends TestCase
{
    private int $seq = 0;

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

        // The products table as production has it BEFORE this session's
        // migration — the migration itself adds the five new columns, and one
        // test below runs the real file to prove it.
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
            $table->string('condition_grade', 10)->nullable();
            $table->decimal('tread_depth_mm', 4, 1)->nullable();
            $table->string('dot_code', 20)->nullable();
            $table->date('inspection_date')->nullable();
            $table->json('inspection_photos')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

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

        $this->runOptimizationMigration();

        Product::flushSlugColumnCache();
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

    /** Run the real migration files — not re-implementations of them. */
    private function runOptimizationMigration(): void
    {
        $migration = require database_path('migrations/2026_08_18_000003_add_seo_and_specs_to_products_table.php');
        $migration->up();

        // Session 93 — brand-level content defaults.
        $migration = require database_path('migrations/2026_08_19_000001_add_content_defaults_to_brands_table.php');
        $migration->up();
    }

    private function admin(string $role = 'editor'): AdminUser
    {
        $this->seq++;

        return AdminUser::create([
            'name'                    => 'Editor ' . $this->seq,
            'email'                   => "editor{$this->seq}@okelcor.test",
            'password'                => Hash::make('secret-password'),
            'role'                    => $role,
            'is_active'               => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function product(array $overrides = []): Product
    {
        $this->seq++;

        return Product::create(array_merge([
            'sku'         => 'SKU-' . $this->seq,
            'brand'       => 'Continental',
            'name'        => 'EcoContact 6',
            'size'        => '205/55 R16',
            'season'      => 'Summer',
            'type'        => 'PCR',
            'price'       => 89.90,
            'description' => 'A summer tyre.',
            'is_active'   => true,
        ], $overrides));
    }

    // ── 1. the migration itself ───────────────────────────────────────────

    public function test_the_migration_applies_against_real_sql_and_is_idempotent(): void
    {
        foreach (['slug', 'description_html', 'specs', 'shipping_info', 'returns_info'] as $column) {
            $this->assertTrue(Schema::hasColumn('products', $column), "products.{$column} missing");
        }

        // Re-run: guarded columns, insertOrIgnore settings, only-NULL backfill.
        $this->runOptimizationMigration();

        $this->assertSame(1, SiteSetting::where('key', 'product_shipping_info')->count());
    }

    public function test_the_backfill_slugs_every_existing_product_uniquely(): void
    {
        // Pre-migration rows: created with no slug, as production has them.
        $a = $this->product();
        $b = $this->product(); // same brand+name+season — must not collide
        Product::withoutTimestamps(fn () => Product::whereIn('id', [$a->id, $b->id])->update(['slug' => null]));

        $this->runOptimizationMigration();

        $this->assertSame('continental-ecocontact-6-summer', $a->fresh()->slug);
        $this->assertSame('continental-ecocontact-6-summer-2', $b->fresh()->slug);
    }

    public function test_the_backfill_never_renames_a_product_that_already_has_a_slug(): void
    {
        $product = $this->product();
        $product->update(['slug' => 'hand-chosen-url']);

        $this->runOptimizationMigration();

        $this->assertSame('hand-chosen-url', $product->fresh()->slug);
    }

    // ── 2. SEO URLs ───────────────────────────────────────────────────────

    public function test_a_new_product_is_born_with_a_brand_name_season_slug(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/products', [
                'sku'         => 'NEW-1',
                'brand'       => 'Michelin',
                'name'        => 'CrossClimate 2',
                'size'        => '225/45 R17',
                'season'      => 'All Season',
                'type'        => 'PCR',
                'price'       => 120,
                'description' => 'An all-season tyre.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'michelin-crossclimate-2-all-season');
    }

    public function test_the_public_api_resolves_a_product_by_slug_and_still_by_id(): void
    {
        $product = $this->product();

        $this->getJson('/api/v1/products/' . $product->slug)
            ->assertOk()
            ->assertJsonPath('data.id', $product->id);

        // Every id URL already indexed or bookmarked keeps working.
        $this->getJson('/api/v1/products/' . $product->id)
            ->assertOk()
            ->assertJsonPath('data.slug', $product->slug);
    }

    public function test_renaming_a_product_does_not_move_its_url(): void
    {
        // The slug is in Google's index and in sent campaign e-mails. A rename
        // changes the product's label, not its address — moving the address is
        // a separate, deliberate act through the slug field.
        $product = $this->product();
        $slug    = $product->slug;

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/v1/admin/products/{$product->id}", ['name' => 'EcoContact 7'])
            ->assertOk();

        $this->assertSame($slug, $product->fresh()->slug);
    }

    public function test_an_explicit_slug_change_is_normalized_and_deduplicated(): void
    {
        $taken   = $this->product();
        $product = $this->product(['name' => 'Different Tyre']);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/v1/admin/products/{$product->id}", [
                'slug' => 'Continental EcoContact 6 Summer', // hand-typed, collides with $taken
            ])
            ->assertOk()
            ->assertJsonPath('data.slug', 'continental-ecocontact-6-summer-2');

        $this->assertSame('continental-ecocontact-6-summer', $taken->fresh()->slug);
    }

    // ── 3. rich description, like the blog post ───────────────────────────

    public function test_the_rich_description_is_sanitized_like_an_article_body(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/v1/admin/products/{$product->id}", [
                'description_html' => '<h2>Grip</h2><p>Wet <strong>and</strong> dry.</p><script>alert(1)</script>',
            ])
            ->assertOk();

        $html = $product->fresh()->description_html;

        $this->assertStringContainsString('<h2>Grip</h2>', $html);
        $this->assertStringContainsString('<strong>and</strong>', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function test_the_plain_description_is_untouched_and_still_served(): void
    {
        // It feeds the meta description and every client that predates the
        // rich field. The rich version is an addition, not a replacement.
        $product = $this->product();

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/v1/admin/products/{$product->id}", [
                'description_html' => '<p>Rich.</p>',
            ])
            ->assertOk();

        $fresh = $product->fresh();
        $this->assertSame('A summer tyre.', $fresh->description);

        $this->getJson('/api/v1/products/' . $product->id)
            ->assertOk()
            ->assertJsonPath('data.description', 'A summer tyre.')
            ->assertJsonPath('data.description_html', '<p>Rich.</p>');
    }

    // ── 4. the Artikelmerkmale sheet ──────────────────────────────────────

    public function test_the_sheet_reads_column_backed_specs_from_their_columns(): void
    {
        // Width already lives in products.width — the sheet must read it from
        // there, not from a second copy that could disagree.
        $product = $this->product(['width' => '205', 'load_index' => '91', 'ean' => '4019238004557']);

        $sheet = $this->getJson('/api/v1/products/' . $product->id)
            ->assertOk()
            ->json('data.specifications');

        $byKey = collect($sheet)->keyBy('key');

        $this->assertSame('205', $byKey['reifenbreite']['value']);
        $this->assertSame('Reifenbreite', $byKey['reifenbreite']['label_de']);
        $this->assertSame('91', $byKey['tragfaehigkeitsindex']['value']);
        $this->assertSame('4019238004557', $byKey['ean_gtin']['value']);
        $this->assertSame('Continental', $byKey['hersteller']['value']);
    }

    public function test_json_specs_are_saved_validated_and_printed(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/v1/admin/products/{$product->id}", [
                'specs' => [
                    'nasshaftungseigenschaften'  => 'B',
                    'reifenkraftstoffeffizienz'  => 'A',
                    'schneeflocken_symbol'       => true,
                    'eprel_registrierungsnummer' => '381583',
                ],
            ])
            ->assertOk();

        $sheet = collect($this->getJson('/api/v1/products/' . $product->id)->json('data.specifications'))->keyBy('key');

        $this->assertSame('B', $sheet['nasshaftungseigenschaften']['value']);
        $this->assertSame('A', $sheet['reifenkraftstoffeffizienz']['value']);
        $this->assertSame('Ja', $sheet['schneeflocken_symbol']['value']);
        $this->assertSame('381583', $sheet['eprel_registrierungsnummer']['value']);
    }

    public function test_an_eu_label_class_outside_a_to_g_is_refused(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/v1/admin/products/{$product->id}", [
                'specs' => ['nasshaftungseigenschaften' => 'H'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('specs.nasshaftungseigenschaften');
    }

    public function test_unknown_keys_and_blanks_never_reach_storage(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/v1/admin/products/{$product->id}", [
                'specs' => [
                    'produktlinie'   => 'EcoContact',
                    'fahrzeugtyp'    => '',            // blank — dropped
                    'made_up_field'  => 'junk',        // unknown — dropped
                ],
            ])
            ->assertOk();

        $this->assertSame(['produktlinie' => 'EcoContact'], $product->fresh()->specs);
    }

    public function test_sending_the_sheet_replaces_it_so_a_cleared_field_stays_cleared(): void
    {
        $product = $this->product(['specs' => ['produktlinie' => 'Old', 'lastbereich' => 'XL']]);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/v1/admin/products/{$product->id}", [
                'specs' => ['produktlinie' => 'New'],
            ])
            ->assertOk();

        $this->assertSame(['produktlinie' => 'New'], $product->fresh()->specs);
    }

    public function test_condition_is_derived_from_type_not_stored(): void
    {
        // "Used" is already the answer — a second stored condition field could
        // only ever agree with it or be wrong.
        $new  = $this->product(['type' => 'PCR']);
        $used = $this->product(['type' => 'Used']);

        $sheetNew  = collect($this->getJson('/api/v1/products/' . $new->id)->json('data.specifications'))->keyBy('key');
        $sheetUsed = collect($this->getJson('/api/v1/products/' . $used->id)->json('data.specifications'))->keyBy('key');

        $this->assertSame('Neu', $sheetNew['reifenzustand']['value']);
        $this->assertSame('Gebraucht', $sheetUsed['reifenzustand']['value']);
    }

    public function test_empty_specs_are_skipped_rather_than_printed_blank(): void
    {
        $product = $this->product(); // no width, no EAN, no JSON specs

        $keys = collect($this->getJson('/api/v1/products/' . $product->id)->json('data.specifications'))
            ->pluck('key');

        $this->assertFalse($keys->contains('reifenbreite'));
        $this->assertFalse($keys->contains('eprel_registrierungsnummer'));
        $this->assertTrue($keys->contains('reifenzustand'));   // derived, always present
        $this->assertTrue($keys->contains('hersteller'));       // brand is required
    }

    public function test_the_admin_form_is_fed_the_sheet_by_the_api(): void
    {
        $sheet = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/products/spec-options')
            ->assertOk()
            ->json('data.sheet');

        $byKey = collect($sheet)->keyBy('key');

        $this->assertSame(['A', 'B', 'C', 'D', 'E', 'F', 'G'], $byKey['nasshaftungseigenschaften']['options']);
        $this->assertSame('column', $byKey['reifenbreite']['source']);
        $this->assertSame('width', $byKey['reifenbreite']['column']);
        $this->assertSame('json', $byKey['eprel_registrierungsnummer']['source']);
    }

    public function test_a_role_without_product_editing_cannot_read_the_admin_sheet(): void
    {
        $this->actingAs($this->admin('viewer'), 'sanctum')
            ->getJson('/api/v1/admin/products/spec-options')
            ->assertStatus(403);
    }

    public function test_the_marketing_role_can_do_the_work_it_was_created_for(): void
    {
        // Session 94: the marketer's role, end to end against a real route —
        // he reported "no access to products" from the editor seat, and this
        // is the assertion that the new seat actually reaches them.
        $product = $this->product();

        $this->actingAs($this->admin('marketing'), 'sanctum')
            ->putJson("/api/v1/admin/products/{$product->id}", [
                'description_html' => '<p>Written by marketing.</p>',
                'specs'            => ['nasshaftungseigenschaften' => 'A'],
            ])
            ->assertOk();

        $this->actingAs($this->admin('marketing'), 'sanctum')
            ->getJson('/api/v1/admin/products/spec-options')
            ->assertOk();

        $this->assertSame('<p>Written by marketing.</p>', $product->fresh()->description_html);
    }

    // ── 5. shipping & returns ─────────────────────────────────────────────

    public function test_shipping_and_returns_fall_back_to_the_site_wide_setting(): void
    {
        SiteSetting::where('key', 'product_returns_info')
            ->update(['value' => '30 Tage Rückgabe.']);

        $product = $this->product();

        $this->getJson('/api/v1/products/' . $product->id)
            ->assertOk()
            // Shipping default was seeded by the migration from the brief.
            ->assertJsonPath('data.shipping_info', "Versand: Kostenlos – Deutsche Post Brief.\nStandort: Munich, Deutschland")
            ->assertJsonPath('data.returns_info', '30 Tage Rückgabe.');
    }

    public function test_a_per_product_override_wins_over_the_setting(): void
    {
        $product = $this->product(['shipping_info' => 'Spedition, Lieferzeit auf Anfrage.']);

        $this->getJson('/api/v1/products/' . $product->id)
            ->assertOk()
            ->assertJsonPath('data.shipping_info', 'Spedition, Lieferzeit auf Anfrage.');
    }

    public function test_an_empty_setting_yields_null_so_the_page_can_hide_the_block(): void
    {
        // Returns was deliberately seeded EMPTY: the brief's text is copied
        // from an eBay listing and would be wrong on okelcor.com. Until the
        // marketer words the site version, the block must not render.
        $product = $this->product();

        $this->getJson('/api/v1/products/' . $product->id)
            ->assertOk()
            ->assertJsonPath('data.returns_info', null);
    }

    // ── 6. brand-level defaults (Session 93) ──────────────────────────────
    //
    // The marketer's follow-up: with ~15,000 products, entering this content
    // product by product is not a workflow. Entered once per brand, inherited
    // by every product without its own value — resolved at read time, never
    // copied, so a brand edit is instant everywhere and a product's own value
    // always wins.

    private function brand(array $overrides = []): \App\Models\Brand
    {
        $this->seq++;

        return \App\Models\Brand::create(array_merge([
            'name'      => 'Continental',
            'is_active' => true,
        ], $overrides));
    }

    public function test_the_brand_migration_applies_against_real_sql_and_is_idempotent(): void
    {
        foreach (['description_html', 'specs', 'shipping_info', 'returns_info'] as $column) {
            $this->assertTrue(Schema::hasColumn('brands', $column), "brands.{$column} missing");
        }

        $this->runOptimizationMigration(); // guarded — re-run is a no-op
        $this->assertTrue(Schema::hasColumn('brands', 'specs'));
    }

    public function test_a_brand_spec_default_fills_every_product_that_left_it_empty(): void
    {
        $this->brand(['specs' => ['reifenbauart' => 'Radial', 'nasshaftungseigenschaften' => 'B']]);

        // Two products, no specs of their own — one brand entry covers both.
        $a = $this->product();
        $b = $this->product();

        foreach ([$a, $b] as $product) {
            $sheet = collect($this->getJson('/api/v1/products/' . $product->id)->json('data.specifications'))->keyBy('key');

            $this->assertSame('Radial', $sheet['reifenbauart']['value']);
            $this->assertSame('B', $sheet['nasshaftungseigenschaften']['value']);
        }
    }

    public function test_a_products_own_value_always_beats_the_brand_default(): void
    {
        $this->brand(['specs' => ['nasshaftungseigenschaften' => 'C']]);

        $product = $this->product(['specs' => ['nasshaftungseigenschaften' => 'A']]);

        $sheet = collect($this->getJson('/api/v1/products/' . $product->id)->json('data.specifications'))->keyBy('key');

        $this->assertSame('A', $sheet['nasshaftungseigenschaften']['value']);
    }

    public function test_the_brand_description_shows_on_products_without_their_own(): void
    {
        $this->brand(['description_html' => '<h2>Continental</h2><p>German engineering since 1871.</p>']);

        $bare    = $this->product();
        $written = $this->product(['description_html' => '<p>This specific tyre.</p>']);

        $this->getJson('/api/v1/products/' . $bare->id)
            ->assertJsonPath('data.description_html', '<h2>Continental</h2><p>German engineering since 1871.</p>');

        $this->getJson('/api/v1/products/' . $written->id)
            ->assertJsonPath('data.description_html', '<p>This specific tyre.</p>');
    }

    public function test_shipping_resolves_product_then_brand_then_setting(): void
    {
        $this->brand(['shipping_info' => 'Continental: Spedition ab Werk.']);

        $own      = $this->product(['shipping_info' => 'Only this one ships free.']);
        $branded  = $this->product();
        $orphan   = $this->product(['brand' => 'NoSuchBrand']);

        // Product's own text wins over its brand's.
        $this->getJson('/api/v1/products/' . $own->id)
            ->assertJsonPath('data.shipping_info', 'Only this one ships free.');

        // No own text → the brand's.
        $this->getJson('/api/v1/products/' . $branded->id)
            ->assertJsonPath('data.shipping_info', 'Continental: Spedition ab Werk.');

        // No brand row at all → the site-wide setting seeded by migration #42.
        $this->getJson('/api/v1/products/' . $orphan->id)
            ->assertJsonPath('data.shipping_info', "Versand: Kostenlos – Deutsche Post Brief.\nStandort: Munich, Deutschland");
    }

    public function test_brand_matching_ignores_case(): void
    {
        // products.brand is free text from three import sources; "CONTINENTAL"
        // and "Continental" are the same company and must inherit the same
        // defaults — same rule the brand-logo lookup has always applied.
        $this->brand(['name' => 'Continental', 'specs' => ['reifenbauart' => 'Radial']]);

        $product = $this->product(['brand' => 'CONTINENTAL']);

        $sheet = collect($this->getJson('/api/v1/products/' . $product->id)->json('data.specifications'))->keyBy('key');

        $this->assertSame('Radial', $sheet['reifenbauart']['value']);
    }

    public function test_an_inactive_brand_lends_no_defaults(): void
    {
        $this->brand(['is_active' => false, 'specs' => ['reifenbauart' => 'Radial']]);

        $product = $this->product();

        $keys = collect($this->getJson('/api/v1/products/' . $product->id)->json('data.specifications'))->pluck('key');

        $this->assertFalse($keys->contains('reifenbauart'));
    }

    public function test_the_admin_brand_form_saves_defaults_under_the_same_rules_as_products(): void
    {
        $brand = $this->brand();

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/v1/admin/brands/{$brand->id}", [
                'name'             => 'Continental',
                'description_html' => '<p>Real content.</p><script>alert(1)</script>',
                'specs'            => [
                    'reifenbauart' => 'Radial',
                    'junk_key'     => 'dropped',
                    'fahrzeugtyp'  => '',
                ],
                'shipping_info'    => 'Continental shipping.',
            ])
            ->assertOk();

        $fresh = $brand->fresh();

        $this->assertStringNotContainsString('<script', (string) $fresh->description_html);
        $this->assertStringContainsString('Real content', (string) $fresh->description_html);
        $this->assertSame(['reifenbauart' => 'Radial'], $fresh->specs);
        $this->assertSame('Continental shipping.', $fresh->shipping_info);
    }

    public function test_a_brand_default_label_class_is_validated_like_a_products(): void
    {
        $brand = $this->brand();

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/v1/admin/brands/{$brand->id}", [
                'name'  => 'Continental',
                'specs' => ['nasshaftungseigenschaften' => 'X'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('specs.nasshaftungseigenschaften');
    }
}
