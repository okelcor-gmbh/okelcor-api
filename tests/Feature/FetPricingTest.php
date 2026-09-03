<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\FetEngine;
use App\Models\FetPrice;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * FET pricing (Session 112).
 *
 * Finance supplied a SUPPLIER price list — what we pay, not what we charge.
 * The whole design turns on keeping those two apart: `GET /api/v1/fet/engines`
 * is unauthenticated and returns models wholesale, so a cost column in the
 * wrong table publishes our buying prices to the open internet.
 */
class FetPricingTest extends TestCase
{
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::disableForeignKeyConstraints();
        foreach (['fet_prices', 'fet_engines', 'admin_users'] as $t) {
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

        Schema::create('fet_engines', function (Blueprint $table) {
            $table->id();
            $table->string('category', 20);
            $table->string('manufacturer', 100);
            $table->string('model_series', 150);
            $table->string('engine_code', 50)->nullable();
            $table->string('displacement', 30)->nullable();
            $table->string('fuel_type', 10);
            $table->string('fet_model', 100);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        $this->runMigration('2026_09_03_000001_create_fet_prices_table');

        Schema::enableForeignKeyConstraints();
        FetPrice::forgetAvailableCheck();
    }

    protected function tearDown(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (['fet_prices', 'fet_engines', 'admin_users'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::enableForeignKeyConstraints();
        FetPrice::forgetAvailableCheck();
        parent::tearDown();
    }

    private function runMigration(string $name): void
    {
        (require database_path("migrations/{$name}.php"))->up();
    }

    private function admin(string $role): AdminUser
    {
        return AdminUser::create([
            'name'                    => 'Staff ' . (++$this->seq),
            'email'                   => 'fp' . $this->seq . uniqid() . '@okelcor.test',
            'role'                    => $role,
            'password'                => Hash::make('secret-pass-123'),
            'is_active'               => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function engine(string $fetModel): FetEngine
    {
        return FetEngine::create([
            'category'     => 'cars_suv',
            'manufacturer' => 'Compact class',
            'model_series' => 'Golf, A3',
            'displacement' => '1.4-1.5',
            'fuel_type'    => 'petrol',
            'fet_model'    => $fetModel,
        ]);
    }

    // ── the tier resolver ─────────────────────────────────────────────────

    public function test_every_real_fet_model_string_resolves_to_its_tier(): void
    {
        // The seven strings that actually exist on production. The SAE size is
        // part of the string and has nothing to do with price.
        $cases = [
            'FET-PRO-FI (SAE 5/16")'              => 'PRO_FI',
            'FET-PRO-FI (SAE 5/16" or SAE 1/2")'  => 'PRO_FI',
            'FET-PRO-FII (SAE 1/2")'              => 'PRO_FII',
            'FET-PRO-FII (SAE 5/16" or SAE 1/2")' => 'PRO_FII',
            'FET-PRO-FIII (SAE 1/2")'             => 'PRO_FIII',
            'FET-PRO-FIII (SAE 1/2" or 5/8")'     => 'PRO_FIII',
            'FET-PRO-FIV (SAE 5/8" or 3/4")'      => 'PRO_FIV',
        ];

        foreach ($cases as $string => $tier) {
            $this->assertSame($tier, FetPrice::tierFor($string), $string);
        }
    }

    public function test_the_longest_tier_wins_so_a_1450_unit_is_never_priced_at_250(): void
    {
        // If the alternation matched `I` before `III`, every tier would resolve
        // to PRO_FI and the most expensive unit in the range would be sold at
        // the cheapest price. This is the assertion that pins that.
        $this->assertNotSame('PRO_FI', FetPrice::tierFor('FET-PRO-FIII (SAE 1/2")'));
        $this->assertNotSame('PRO_FI', FetPrice::tierFor('FET-PRO-FIV (SAE 5/8" or 3/4")'));
        $this->assertNotSame('PRO_FII', FetPrice::tierFor('FET-PRO-FIII (SAE 1/2")'));

        $this->assertNull(FetPrice::tierFor('something else entirely'));
        $this->assertNull(FetPrice::tierFor(null));
    }

    // ── the money that must not leak ──────────────────────────────────────

    public function test_the_public_endpoint_never_serves_our_supplier_cost(): void
    {
        $this->engine('FET-PRO-FII (SAE 1/2")');

        FetPrice::where('tier', 'PRO_FII')->update(['price' => 649.00]);

        $response = $this->getJson('/api/v1/fet/engines')->assertOk();

        // The retail figure is served...
        $this->assertSame('649.00', $response->json('data.0.price'));
        $this->assertSame('PRO_FII', $response->json('data.0.fet_tier'));

        // ...and the supplier price appears nowhere in the payload, under any
        // key. Asserted against the raw body rather than a field, because the
        // risk is a column arriving through some future `toArray()`.
        $body = $response->getContent();
        $this->assertStringNotContainsString('cost_price', $body);
        $this->assertStringNotContainsString('450.00', $body);
        $this->assertArrayNotHasKey('cost_price', $response->json('data.0'));
    }

    public function test_a_tier_with_no_retail_price_shows_no_price(): void
    {
        // The seeded state: cost known, retail not yet set by finance. The
        // page must show nothing rather than fall back to what we paid.
        $this->engine('FET-PRO-FI (SAE 5/16")');

        $response = $this->getJson('/api/v1/fet/engines')->assertOk();

        $this->assertNull($response->json('data.0.price'));
        $this->assertSame('PRO_FI', $response->json('data.0.fet_tier'));
        $this->assertStringNotContainsString('250.00', $response->getContent());
    }

    public function test_the_seed_carries_the_supplier_list_and_no_retail_price(): void
    {
        $this->assertSame(4, FetPrice::count());

        $expected = ['PRO_FI' => '250.00', 'PRO_FII' => '450.00', 'PRO_FIII' => '750.00', 'PRO_FIV' => '1450.00'];

        foreach ($expected as $tier => $cost) {
            $row = FetPrice::where('tier', $tier)->firstOrFail();
            $this->assertSame($cost, (string) $row->cost_price, $tier);
            $this->assertNull($row->price, $tier . ' must have no retail price until finance sets one');
        }
    }

    public function test_re_running_the_migration_does_not_overwrite_a_price_finance_set(): void
    {
        FetPrice::where('tier', 'PRO_FI')->update(['price' => 399.00, 'cost_price' => 260.00]);

        $this->runMigration('2026_09_03_000001_create_fet_prices_table');

        $row = FetPrice::where('tier', 'PRO_FI')->firstOrFail();
        $this->assertSame('399.00', (string) $row->price);
        $this->assertSame('260.00', (string) $row->cost_price);
        $this->assertSame(4, FetPrice::count());
    }

    // ── who may set it ────────────────────────────────────────────────────

    public function test_finance_can_read_and_set_the_prices(): void
    {
        // The whole reason this has its own permission: finance holds no
        // products.* key, and the FET engine routes live behind products.edit.
        $finance = $this->admin('finance');

        $this->actingAs($finance, 'sanctum')
            ->getJson('/api/v1/admin/fet/prices')
            ->assertOk()
            ->assertJsonPath('data.0.tier', 'PRO_FI')
            ->assertJsonPath('data.0.cost_price', '250.00')
            ->assertJsonPath('data.0.price', null);

        $this->actingAs($finance, 'sanctum')
            ->putJson('/api/v1/admin/fet/prices', [
                'rows' => [
                    ['tier' => 'PRO_FI',   'price' => 399.00],
                    ['tier' => 'PRO_FIV',  'price' => 1990.00],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.0.price', '399.00')
            ->assertJsonPath('data.0.margin', '149.00');
    }

    public function test_an_editor_cannot_touch_the_prices(): void
    {
        // `editor` holds products.edit and therefore the FET ENGINE routes.
        // Pricing is a different question and a different key.
        $editor = $this->admin('editor');

        $this->actingAs($editor, 'sanctum')
            ->getJson('/api/v1/admin/fet/prices')
            ->assertStatus(403);

        $this->actingAs($editor, 'sanctum')
            ->putJson('/api/v1/admin/fet/prices', [
                'rows' => [['tier' => 'PRO_FI', 'price' => 1.00]],
            ])
            ->assertStatus(403);
    }

    public function test_selling_below_cost_is_allowed_but_says_so(): void
    {
        // Not refused — a loss-leader is finance's call. But it is worth
        // hearing at the moment of saving rather than at month end.
        $finance = $this->admin('finance');

        $response = $this->actingAs($finance, 'sanctum')
            ->putJson('/api/v1/admin/fet/prices', [
                'rows' => [['tier' => 'PRO_FIII', 'price' => 500.00]],
            ])
            ->assertOk();

        $this->assertSame('500.00', $response->json('data.2.price'));
        $this->assertStringContainsString('below cost', $response->json('meta.warnings.0'));
    }

    public function test_the_lookup_still_works_before_the_pricing_migration_runs(): void
    {
        // Deploy-order safety: the /fet page is live and must not break
        // between the code deploying and the table existing.
        Schema::dropIfExists('fet_prices');
        FetPrice::forgetAvailableCheck();

        $this->engine('FET-PRO-FII (SAE 1/2")');

        $this->getJson('/api/v1/fet/engines')
            ->assertOk()
            ->assertJsonPath('data.0.price', null)
            ->assertJsonPath('data.0.fet_model', 'FET-PRO-FII (SAE 1/2")');
    }
}
