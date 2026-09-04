<?php

namespace Tests\Feature;

use App\Mail\CustomerEmailVerification;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * One platform, two audiences (Session 116).
 *
 * The register fork: a private buyer gets an account on the spot, gated only
 * on e-mail verification, the way every retail tyre platform works. The
 * trade path keeps its human review, because wholesale terms are exactly
 * what a human should approve.
 *
 * The catalogue fork: `audience` makes listing intent explicit, and the
 * `segment` parameter the shop has ALWAYS sent finally does something — the
 * backend read `customer_type` instead, so its tier filter never fired from
 * the storefront.
 */
class DualAudienceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::disableForeignKeyConstraints();
        foreach ([
            'products', 'product_images', 'brands', 'site_settings', 'search_events', 'admin_notifications',
            'security_events', 'customers', 'admin_users', 'personal_access_tokens',
        ] as $t) {
            Schema::dropIfExists($t);
        }

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('customer_type', 10)->default('b2b');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->unique();
            $table->string('password');
            $table->string('phone')->nullable();
            $table->string('country')->nullable();
            $table->string('company_name')->nullable();
            $table->string('vat_number')->nullable();
            $table->boolean('vat_verified')->default(false);
            $table->string('industry')->nullable();
            $table->string('preferred_language', 5)->nullable();
            $table->string('status', 20)->default('active');
            $table->boolean('is_active')->default(true);
            $table->string('onboarding_status', 30)->default('active');
            $table->timestamp('email_verified_at')->nullable();
            $table->boolean('must_reset_password')->default(false);
            $table->integer('failed_login_count')->default(0);
            $table->string('access_level', 30)->nullable();
            $table->boolean('approved_for_quotes')->default(false);
            $table->boolean('approved_for_checkout')->default(false);
            $table->boolean('approved_for_documents')->default(false);
            $table->boolean('approved_for_wholesale_pricing')->default(false);
            $table->timestamps();
        });

        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->string('type', 60);
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('description')->nullable();
            $table->string('severity', 20)->default('info');
            $table->timestamps();
        });

        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('admin_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_user_id');
            $table->string('type', 100);
            $table->string('severity', 20)->default('info');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('action_url')->nullable();
            $table->string('related_type', 100)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('sku')->nullable();
            $table->string('brand')->nullable();
            $table->string('type', 10)->nullable();
            $table->string('audience', 8)->default('both');
            $table->string('season', 20)->nullable();
            $table->string('size', 40)->nullable();
            $table->string('spec', 60)->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('price_b2b', 10, 2)->nullable();
            $table->decimal('price_b2c', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('in_stock')->default(true);
            $table->integer('stock')->default(10);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('site_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type', 20)->default('string');
            $table->string('group', 40)->nullable();
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('logo')->nullable();
            $table->timestamps();
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('path')->nullable();
            $table->timestamps();
        });

        Schema::create('search_events', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 30)->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();
        Product::forgetAudienceCheck();
    }

    protected function tearDown(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach ([
            'products', 'product_images', 'brands', 'site_settings', 'search_events', 'admin_notifications',
            'security_events', 'customers', 'admin_users', 'personal_access_tokens',
        ] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::enableForeignKeyConstraints();
        Product::forgetAudienceCheck();
        parent::tearDown();
    }

    private function registerPayload(string $type): array
    {
        return [
            'customer_type'         => $type,
            'first_name'            => 'Ana',
            'last_name'             => 'Weber',
            'email'                 => $type . uniqid() . '@example.test',
            'password'              => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
            'company_name'          => $type === 'b2b' ? 'Weber Reifen GmbH' : null,
        ];
    }

    // ── the register fork ─────────────────────────────────────────────────

    public function test_a_private_buyer_gets_an_account_on_the_spot(): void
    {
        Mail::fake();

        $res = $this->postJson('/api/v1/auth/register', $this->registerPayload('b2c'))
            ->assertCreated()
            ->assertJsonPath('onboarding_status', 'active')
            ->assertJsonPath('email_verification_required', true);

        $this->assertStringNotContainsString('review', strtolower($res->json('message')));

        $c = Customer::firstOrFail();
        $this->assertTrue((bool) $c->is_active);
        $this->assertSame('active', $c->onboarding_status);
        // Retail access exactly: buy yes, wholesale terms no.
        $this->assertTrue((bool) $c->approved_for_checkout);
        $this->assertFalse((bool) $c->approved_for_wholesale_pricing);

        // The activation step actually goes out — registering used to send
        // nothing at all, leaving the account with no link to click.
        Mail::assertSent(CustomerEmailVerification::class);
    }

    public function test_the_trade_path_still_waits_for_a_human(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/auth/register', $this->registerPayload('b2b'))
            ->assertCreated()
            ->assertJsonPath('onboarding_status', 'pending_review');

        $c = Customer::firstOrFail();
        $this->assertFalse((bool) $c->is_active);
        $this->assertFalse((bool) $c->approved_for_checkout);
    }

    public function test_the_unverified_b2c_account_cannot_sign_in_yet(): void
    {
        Mail::fake();
        $payload = $this->registerPayload('b2c');
        $this->postJson('/api/v1/auth/register', $payload)->assertCreated();

        // Active, but the e-mail link has not been clicked: refused with the
        // verification message, not the trade review message.
        $this->postJson('/api/v1/auth/login', [
            'email' => $payload['email'], 'password' => 'secret-pass-123',
        ])
            ->assertStatus(403)
            ->assertJsonPath('email_verified', false);
    }

    // ── the catalogue fork ────────────────────────────────────────────────

    private function seedAudiences(): void
    {
        Product::create(['name' => 'Everyman 205/55R16', 'brand' => 'Rapid', 'type' => 'PCR', 'audience' => 'both', 'price' => 60, 'price_b2b' => 48, 'price_b2c' => 60]);
        Product::create(['name' => 'Container lot 315/80R22.5', 'brand' => 'Windpower', 'type' => 'TBR', 'audience' => 'b2b', 'price' => 210, 'price_b2b' => 210]);
        Product::create(['name' => 'Weekend promo 195/65R15', 'brand' => 'Rapid', 'type' => 'PCR', 'audience' => 'b2c', 'price' => 45, 'price_b2c' => 45]);
    }

    public function test_each_segment_sees_its_own_catalogue(): void
    {
        $this->seedAudiences();

        $b2c = collect($this->getJson('/api/v1/products?in_stock=1&segment=b2c')->assertOk()->json('data'))->pluck('name');
        $this->assertTrue($b2c->contains('Everyman 205/55R16'));
        $this->assertTrue($b2c->contains('Weekend promo 195/65R15'));
        $this->assertFalse($b2c->contains('Container lot 315/80R22.5'), 'a trade-only lot must never reach a retail view');

        $b2b = collect($this->getJson('/api/v1/products?in_stock=1&segment=b2b')->assertOk()->json('data'))->pluck('name');
        $this->assertTrue($b2b->contains('Container lot 315/80R22.5'));
        $this->assertFalse($b2b->contains('Weekend promo 195/65R15'), 'a retail-only offer must not appear in the trade view');
    }

    public function test_a_guest_browses_the_retail_view(): void
    {
        $this->seedAudiences();

        $names = collect($this->getJson('/api/v1/products?in_stock=1')->assertOk()->json('data'))->pluck('name');
        $this->assertFalse($names->contains('Container lot 315/80R22.5'));
        $this->assertTrue($names->contains('Everyman 205/55R16'));
    }

    public function test_the_segment_parameter_the_shop_always_sent_finally_works(): void
    {
        // The storefront has sent ?segment= since the shop shipped; the
        // backend read ?customer_type=, so the tier price filter silently
        // never ran server-side. Both names are accepted now, and this pins
        // the one the shop actually uses.
        $this->seedAudiences();
        Product::create(['name' => 'No b2b price 175/65R14', 'brand' => 'Rapid', 'type' => 'PCR', 'audience' => 'both', 'price' => 40, 'price_b2c' => 40]);

        $b2b = collect($this->getJson('/api/v1/products?in_stock=1&segment=b2b')->assertOk()->json('data'))->pluck('name');
        $this->assertFalse($b2b->contains('No b2b price 175/65R14'), 'segment=b2b must exclude products with no trade price');
    }

    public function test_the_catalogue_survives_the_audience_column_arriving_later(): void
    {
        // Deploy-order safety: code before migration #64. Everything lists.
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('audience'));
        Product::forgetAudienceCheck();

        Product::create(['name' => 'Legacy 205/55R16', 'brand' => 'Rapid', 'type' => 'PCR', 'price' => 60, 'price_b2c' => 60]);

        $this->getJson('/api/v1/products?in_stock=1&segment=b2c')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Legacy 205/55R16');
    }
}
