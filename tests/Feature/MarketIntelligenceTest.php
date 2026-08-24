<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\MarketReferenceStat;
use App\Support\CountryNormaliser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Market intelligence — the per-country scorecard and its CSV export.
 *
 * Minimal-schema sqlite harness rather than RefreshDatabase, same as
 * PartnerSalesLogTest, so these execute instead of skipping behind the
 * MySQL gate.
 */
class MarketIntelligenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::disableForeignKeyConstraints();

        foreach ([
            'market_reference_stats', 'search_events', 'marketing_contact_markets',
            'marketing_contacts', 'customers', 'quote_requests', 'orders',
            'personal_access_tokens', 'admin_users',
        ] as $table) {
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

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('search_events', function (Blueprint $table) {
            $table->id();
            $table->string('term', 150)->nullable();
            $table->string('country', 2)->nullable();
            $table->string('visitor_hash', 64)->nullable();
            $table->boolean('has_results')->default(true);
            $table->unsignedInteger('results_count')->default(0);
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('country', 100)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('status', 30)->nullable();
            $table->decimal('total', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('quote_requests', function (Blueprint $table) {
            $table->id();
            $table->string('country', 100)->nullable();
            $table->string('qualification_status', 30)->nullable();
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('country')->nullable();
            $table->timestamps();
        });

        Schema::create('marketing_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('country', 100)->nullable();
            $table->string('status', 30)->nullable();
            $table->timestamps();
        });

        Schema::create('marketing_contact_markets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('contact_id');
            $table->string('market', 50);
            $table->timestamps();
        });

        Schema::create('market_reference_stats', function (Blueprint $table) {
            $table->id();
            $table->string('country_code', 2);
            $table->string('metric', 80);
            $table->decimal('value', 20, 4);
            $table->string('unit', 30)->nullable();
            $table->string('period', 20)->nullable();
            $table->string('source', 190)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function headers(string $role = 'admin'): array
    {
        static $seq = 0;
        $seq++;

        $admin = AdminUser::create([
            'name'                    => "Ops {$seq}",
            'email'                   => "ops{$seq}@okelcor.com",
            'password'                => Hash::make('secret-password'),
            'role'                    => $role,
            'is_active'               => true,
            'two_factor_confirmed_at' => now(),
        ]);

        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer ' . $admin->createToken('t')->plainTextToken];
    }

    private function search(string $country, int $times, bool $found = true, string $term = 'winter tyres'): void
    {
        for ($i = 0; $i < $times; $i++) {
            DB::table('search_events')->insert([
                'term'         => $term,
                'country'      => $country,
                'visitor_hash' => 'v' . $country . $i,
                'has_results'  => $found,
                'created_at'   => now()->subDays(3),
            ]);
        }
    }

    private function order(string $country, float $total, string $currency = 'EUR', ?string $status = 'confirmed'): void
    {
        DB::table('orders')->insert([
            'country' => $country, 'total' => $total, 'currency' => $currency,
            'status' => $status, 'created_at' => now()->subDays(2), 'updated_at' => now(),
        ]);
    }

    private function quote(string $country, ?string $status = null): void
    {
        DB::table('quote_requests')->insert([
            'country' => $country, 'qualification_status' => $status,
            'created_at' => now()->subDays(2), 'updated_at' => now(),
        ]);
    }

    private function contacts(string $country, int $count, ?string $market = null): void
    {
        for ($i = 0; $i < $count; $i++) {
            $id = DB::table('marketing_contacts')->insertGetId([
                'country' => $country, 'status' => 'subscribed',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($market) {
                DB::table('marketing_contact_markets')->insert([
                    'contact_id' => $id, 'market' => $market,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    private function report(): array
    {
        $res = $this->getJson('/api/v1/admin/analytics/markets', $this->headers());
        $res->assertOk();

        return $res->json('data');
    }

    private function market(array $report, string $code): ?array
    {
        return collect($report['markets'])->firstWhere('country_code', $code);
    }

    // ── the normaliser, which everything else depends on ──────────────────

    public function test_the_same_country_written_five_ways_is_one_market(): void
    {
        foreach (['Germany', 'germany', 'DE', 'Deutschland', ' GERMANY '] as $spelling) {
            $this->assertSame('DE', CountryNormaliser::normalise($spelling), "Failed on: {$spelling}");
        }
    }

    public function test_accents_and_punctuation_do_not_split_a_market(): void
    {
        $this->assertSame('CI', CountryNormaliser::normalise("Côte d'Ivoire"));
        $this->assertSame('CI', CountryNormaliser::normalise('cote d ivoire'));
        $this->assertSame('TR', CountryNormaliser::normalise('Türkiye'));
        $this->assertSame('TR', CountryNormaliser::normalise('Turkey'));
    }

    public function test_an_unknown_country_is_null_not_a_guess(): void
    {
        // Guessing would file real business under the wrong flag, which is
        // worse than leaving it out, because it looks like evidence.
        $this->assertNull(CountryNormaliser::normalise('Wakanda'));
        $this->assertNull(CountryNormaliser::normalise(''));
        $this->assertNull(CountryNormaliser::normalise(null));
    }

    public function test_a_region_is_not_treated_as_a_country(): void
    {
        $this->assertNull(CountryNormaliser::normalise('asia'));
        $this->assertNull(CountryNormaliser::normalise('Europe'));
    }

    public function test_the_five_spellings_actually_merge_in_the_report(): void
    {
        // The whole point, end to end: if this splits, every figure in the
        // report is too low and nothing errors.
        $this->search('DE', 12);
        $this->order('Germany', 5000);
        $this->order('germany', 3000);
        $this->quote('Deutschland');
        $this->quote('DE');

        $report = $this->report();
        $germany = $this->market($report, 'DE');

        $this->assertNotNull($germany);
        $this->assertSame(1, collect($report['markets'])->where('country_code', 'DE')->count());
        $this->assertSame(2, $germany['commercial']['orders']);
        $this->assertSame(2, $germany['pipeline']['quotes']);
        // Cast: JSON drops a trailing .0, so 8000.0 arrives as 8000. The
        // value is what matters, not the wire type.
        $this->assertSame(8000.0, (float) $germany['commercial']['revenue_by_currency']['EUR']);
    }

    // ── signals ───────────────────────────────────────────────────────────

    public function test_demand_with_no_contacts_is_the_penetrate_signal(): void
    {
        $this->search('PL', 40);
        $this->quote('Poland');
        $this->quote('Poland');

        $poland = $this->market($this->report(), 'PL');

        $this->assertSame('interest_no_reach', $poland['signal']);
        $this->assertStringContainsString('build a contact list', strtolower($poland['recommended_action']));
    }

    public function test_demand_that_finds_nothing_is_a_stock_gap_not_a_marketing_one(): void
    {
        $this->search('ES', 30, found: false, term: 'otr 29.5r25');
        $this->contacts('Spain', 20);

        $spain = $this->market($this->report(), 'ES');

        $this->assertSame('demand_not_served', $spain['signal']);
        $this->assertSame('otr 29.5r25', $spain['demand']['top_unmet_terms'][0]['term']);
        $this->assertSame(30, $spain['demand']['unmet_searches']);
    }

    public function test_a_market_with_a_list_and_demand_but_no_orders_stalls(): void
    {
        $this->search('IT', 40);
        $this->quote('Italy');
        $this->quote('Italy');
        $this->contacts('Italy', 30);

        $italy = $this->market($this->report(), 'IT');

        $this->assertSame('demand_not_converting', $italy['signal']);
    }

    public function test_a_market_with_everything_is_proven(): void
    {
        $this->search('FR', 40);
        $this->quote('France', 'converted');
        $this->quote('France');
        $this->order('France', 12000);
        $this->contacts('France', 50, 'france');

        $france = $this->market($this->report(), 'FR');

        $this->assertSame('proven', $france['signal']);
        $this->assertSame(0.5, $france['rates']['quote_to_order']);
        $this->assertSame(0.5, $france['rates']['quote_win_rate']);
        $this->assertContains('france', $france['reach']['market_slugs']);
    }

    public function test_a_list_that_produces_nothing_is_called_out(): void
    {
        $this->contacts('Czechia', 300, 'czech');

        $czechia = $this->market($this->report(), 'CZ');

        $this->assertSame('reach_no_interest', $czechia['signal']);
        $this->assertSame(300, $czechia['reach']['contacts']);
    }

    public function test_one_search_is_not_a_market(): void
    {
        $this->search('PT', 1);

        $portugal = $this->market($this->report(), 'PT');

        $this->assertSame('emerging', $portugal['signal']);
    }

    public function test_proven_markets_rank_above_speculative_ones(): void
    {
        $this->search('PT', 1);                                   // emerging
        $this->search('FR', 40);
        $this->order('France', 9000);                             // proven

        $codes = collect($this->report()['markets'])->pluck('country_code')->all();

        $this->assertSame('FR', $codes[0]);
        $this->assertSame('PT', $codes[array_key_last($codes)]);
    }

    // ── honesty about what it cannot see ──────────────────────────────────

    public function test_an_unrecognised_country_is_reported_not_silently_dropped(): void
    {
        $this->order('Wakanda', 5000);

        $report = $this->report();

        $this->assertNull($this->market($report, 'WK'));
        $this->assertSame('Wakanda', $report['unrecognised'][0]['value']);
        $this->assertSame('orders', $report['unrecognised'][0]['source']);
        $this->assertSame(1, $report['unrecognised'][0]['rows']);
    }

    public function test_a_market_with_outside_data_but_no_traffic_is_unmeasured_not_zero(): void
    {
        MarketReferenceStat::create([
            'country_code' => 'RO', 'metric' => 'tyre_import_volume_usd',
            'value' => 412000000, 'unit' => 'USD', 'period' => '2024', 'source' => 'UN Comtrade',
        ]);

        $report = $this->report();

        // Must NOT appear in the ranked table with zeros — that reads as
        // "no demand" when it means "never measured".
        $this->assertNull($this->market($report, 'RO'));

        $this->assertSame('RO', $report['unmeasured'][0]['country_code']);
        $this->assertSame('Romania', $report['unmeasured'][0]['country']);
        $this->assertSame('UN Comtrade', $report['unmeasured'][0]['reference'][0]['source']);
    }

    public function test_reference_data_attaches_to_an_observed_market(): void
    {
        $this->search('FR', 40);
        MarketReferenceStat::create([
            'country_code' => 'FR', 'metric' => 'vehicle_fleet_size',
            'value' => 39000000, 'unit' => 'units', 'period' => '2024',
        ]);

        $france = $this->market($this->report(), 'FR');

        $this->assertSame('vehicle_fleet_size', $france['reference'][0]['metric']);
        $this->assertNotContains('FR', collect($this->report()['unmeasured'])->pluck('country_code')->all());
    }

    public function test_the_report_says_what_it_cannot_see(): void
    {
        $report = $this->report();

        $joined = implode(' ', $report['meta']['not_covered']);
        $this->assertStringContainsString('exchange rate', $joined);
        $this->assertStringContainsString('Page views', $joined);
    }

    public function test_revenue_is_kept_per_currency_and_never_blended(): void
    {
        // Converting a historical order at today's rate would not be the
        // money that was received.
        $this->order('Ghana', 5000, 'USD');
        $this->order('Ghana', 2000, 'EUR');

        $ghana = $this->market($this->report(), 'GH');

        $this->assertSame(5000.0, (float) $ghana['commercial']['revenue_by_currency']['USD']);
        $this->assertSame(2000.0, (float) $ghana['commercial']['revenue_by_currency']['EUR']);
    }

    public function test_a_cancelled_order_is_not_revenue(): void
    {
        $this->order('France', 9000, 'EUR', 'cancelled');

        $this->assertNull($this->market($this->report(), 'FR'));
    }

    public function test_zero_quotes_gives_a_null_rate_not_a_zero_percent(): void
    {
        $this->search('NL', 40);

        $netherlands = $this->market($this->report(), 'NL');

        // "0% converted" on a market nobody asked about reads as failure.
        $this->assertNull($netherlands['rates']['quote_to_order']);
    }

    public function test_search_recording_being_absent_is_stated_not_reported_as_zero_demand(): void
    {
        Schema::dropIfExists('search_events');
        $this->order('France', 9000);

        $report = $this->report();

        $this->assertFalse($report['meta']['search_recording']);
        $this->assertNull($this->market($report, 'FR')['demand']);
        $this->assertStringContainsString(
            'not being recorded',
            implode(' ', $report['meta']['not_covered'])
        );
    }

    // ── access + export ───────────────────────────────────────────────────

    public function test_the_marketing_role_can_open_it(): void
    {
        // The whole point is that marketing uses this, not just developers.
        $this->getJson('/api/v1/admin/analytics/markets', $this->headers('marketing'))->assertOk();
    }

    public function test_a_role_without_analytics_view_cannot(): void
    {
        $this->getJson('/api/v1/admin/analytics/markets', $this->headers('support'))->assertStatus(403);
    }

    public function test_the_export_is_a_csv_carrying_the_reasoning_not_just_numbers(): void
    {
        $this->search('PL', 40);
        $this->quote('Poland');
        $this->quote('Poland');
        $this->order('Wakanda', 100);

        $res = $this->get('/api/v1/admin/analytics/markets/export', $this->headers());
        $res->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));

        $csv = $res->streamedContent();

        $this->assertStringContainsString('Poland', $csv);
        $this->assertStringContainsString('Interest, no list', $csv);
        $this->assertStringContainsString('build a contact list', $csv);
        // The caveats have to travel with the file — a spreadsheet gets forwarded.
        $this->assertStringContainsString('UNRECOGNISED COUNTRY VALUES', $csv);
        $this->assertStringContainsString('Wakanda', $csv);
        $this->assertStringContainsString('NOTE', $csv);
    }

    // ── the reference-data import ─────────────────────────────────────────

    private function csv(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mktref') . '.csv';
        file_put_contents($path, $body);

        return $path;
    }

    public function test_the_import_surveys_without_writing_unless_told_to(): void
    {
        $path = $this->csv(<<<CSV
        country,metric,value,unit,period,source
        Romania,tyre_import_volume_usd,412000000,USD,2024,UN Comtrade
        CSV);

        $this->artisan("markets:import-reference {$path}")
            ->expectsOutputToContain('1 row(s) ready')
            ->expectsOutputToContain('Survey only')
            ->assertSuccessful();

        $this->assertSame(0, MarketReferenceStat::count());

        $this->artisan("markets:import-reference {$path} --fix")->assertSuccessful();

        $this->assertSame(1, MarketReferenceStat::count());
        $this->assertSame('RO', MarketReferenceStat::first()->country_code);
    }

    public function test_the_import_normalises_the_country_and_strips_thousands_separators(): void
    {
        $path = $this->csv(<<<CSV
        country,metric,value,unit,period,source
        Deutschland,tyre_import_volume_usd,"3,900,000,000",USD,2024,UN Comtrade
        CSV);

        $this->artisan("markets:import-reference {$path} --fix")->assertSuccessful();

        $stat = MarketReferenceStat::first();
        $this->assertSame('DE', $stat->country_code);
        $this->assertSame(3900000000.0, (float) $stat->value);
    }

    public function test_the_import_rejects_rather_than_guesses(): void
    {
        // A statistic filed under the wrong country is worse than one that is
        // absent, because it looks like evidence.
        $path = $this->csv(<<<CSV
        country,metric,value,unit,period,source
        Wakanda,tyre_import_volume_usd,999,USD,2024,Made up
        Serbia,tyre_import_volume_usd,not-a-number,USD,2024,UN Comtrade
        Poland,,500,units,2024,Eurostat
        CSV);

        $this->artisan("markets:import-reference {$path} --fix")
            ->expectsOutputToContain('0 row(s) ready, 3 rejected')
            ->assertSuccessful();

        $this->assertSame(0, MarketReferenceStat::count());
    }

    public function test_reimporting_a_corrected_figure_replaces_it_rather_than_doubling(): void
    {
        $first = $this->csv("country,metric,value,period\nRomania,tyre_import_volume_usd,400000000,2024\n");
        $fixed = $this->csv("country,metric,value,period\nRomania,tyre_import_volume_usd,412000000,2024\n");

        $this->artisan("markets:import-reference {$first} --fix")->assertSuccessful();
        $this->artisan("markets:import-reference {$fixed} --fix")->assertSuccessful();

        $this->assertSame(1, MarketReferenceStat::count());
        $this->assertSame(412000000.0, (float) MarketReferenceStat::first()->value);
    }

    public function test_the_import_survives_the_bom_excel_writes(): void
    {
        // Excel prefixes a UTF-8 BOM, which turns "country" into a column
        // name nothing matches — a failure that reads as a malformed file.
        $path = $this->csv("\xEF\xBB\xBFcountry,metric,value,period\nRomania,tyre_import_volume_usd,412000000,2024\n");

        $this->artisan("markets:import-reference {$path} --fix")->assertSuccessful();

        $this->assertSame(1, MarketReferenceStat::count());
    }

    public function test_a_file_missing_a_required_column_fails_clearly(): void
    {
        $path = $this->csv("nation,metric,value\nRomania,x,1\n");

        $this->artisan("markets:import-reference {$path}")
            ->expectsOutputToContain("Missing required column 'country'")
            ->assertFailed();
    }

    public function test_the_migration_applies_against_real_sql_and_is_idempotent(): void
    {
        Schema::dropIfExists('market_reference_stats');

        $migration = require database_path('migrations/2026_08_24_000002_create_market_reference_stats_table.php');

        $migration->up();
        $this->assertTrue(Schema::hasTable('market_reference_stats'));
        $this->assertTrue(Schema::hasColumn('market_reference_stats', 'period'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('market_reference_stats'));
    }
}
