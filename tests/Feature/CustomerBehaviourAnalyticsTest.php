<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\SearchEvent;
use App\Services\CustomerBehaviourService;
use App\Services\SearchEventRecorder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * What customers look for, as opposed to what they bought.
 *
 * The dashboard could already say what sold. It could not say what was searched
 * for and not found — which is the half that changes what gets stocked — because
 * nothing recorded a search.
 *
 * Minimal-schema sqlite harness, same pattern as BulkEmailCampaignTest.
 */
class CustomerBehaviourAnalyticsTest extends TestCase
{
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        SearchEventRecorder::forgetTableCache();

        Schema::disableForeignKeyConstraints();

        foreach (['search_events', 'saved_fitments', 'product_images', 'brands', 'site_settings', 'products', 'quote_requests', 'orders', 'personal_access_tokens', 'admin_users'] as $table) {
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

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->nullable();
            $table->string('brand')->nullable();
            $table->string('name')->nullable();
            $table->string('size')->nullable();
            $table->string('type')->nullable();
            $table->string('season')->nullable();
            $table->string('width')->nullable();
            $table->string('height')->nullable();
            $table->string('rim')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('price_b2b', 12, 2)->nullable();
            $table->decimal('price_b2c', 12, 2)->nullable();
            $table->integer('stock')->nullable();
            $table->string('primary_image')->nullable();
            $table->boolean('in_stock')->default(true);
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });

        // formatProduct() falls back to the brand's logo when a product has no
        // image of its own, so the catalogue needs this table to answer at all.
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('logo')->nullable();
            $table->timestamps();
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('image_path')->nullable();
            $table->timestamps();
        });

        // Read for estimated_dispatch_days on every in-stock product payload.
        Schema::create('site_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('group')->nullable();
            $table->timestamps();
        });

        Schema::create('saved_fitments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->string('size')->nullable();
            $table->string('brand')->nullable();
            $table->string('label')->nullable();
            $table->timestamps();
        });

        Schema::create('quote_requests', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });

        $this->runSearchEventsMigration();
    }

    /** Runs the real migration file, so these tests bind to the shipped schema. */
    private function runSearchEventsMigration(): void
    {
        $migration = require database_path('migrations/2026_08_12_000001_create_search_events_table.php');
        $migration->up();

        SearchEventRecorder::forgetTableCache();
    }

    // ── harness ───────────────────────────────────────────────────────────

    private function admin(string $role = 'admin'): AdminUser
    {
        $this->seq++;

        return AdminUser::create([
            'name'                    => 'Analyst ' . $this->seq,
            'email'                   => "analyst{$this->seq}@okelcor.com",
            'password'                => Hash::make('secret-password'),
            'role'                    => $role,
            'is_active'               => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function headers(?AdminUser $admin = null): array
    {
        $admin ??= $this->admin();

        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer ' . $admin->createToken('t')->plainTextToken];
    }

    private function search(array $attributes = []): SearchEvent
    {
        return SearchEvent::create(array_merge([
            'term'          => '225/45r17',
            'results_count' => 5,
            'has_results'   => true,
            'visitor_hash'  => 'visitor-a',
            'created_at'    => now()->subDay(),
        ], $attributes));
    }

    private function report(): array
    {
        return app(CustomerBehaviourService::class)
            ->report(Carbon::now()->subDays(30)->startOfDay(), Carbon::now());
    }

    // ── recording ─────────────────────────────────────────────────────────

    public function test_a_catalogue_search_is_recorded_with_its_result_count(): void
    {
        $this->getJson('/api/v1/products?q=Michelin&brand=Michelin&type=pcr')->assertOk();

        $event = SearchEvent::first();

        $this->assertNotNull($event, 'A filtered catalogue query should be recorded.');
        // Normalised for grouping, so three spellings are one row in a report.
        $this->assertSame('michelin', $event->term);
        $this->assertSame('Michelin', $event->raw_term);
        $this->assertSame('Michelin', $event->brand);
        $this->assertSame('pcr', $event->category);
        $this->assertSame(0, $event->results_count);
        $this->assertFalse($event->has_results);
    }

    public function test_browsing_without_searching_records_nothing(): void
    {
        // The catalogue's empty state returns nothing and means nothing.
        $this->getJson('/api/v1/products')->assertOk();

        $this->assertSame(0, SearchEvent::count());
    }

    public function test_paging_through_one_search_is_not_counted_again(): void
    {
        $this->getJson('/api/v1/products?q=michelin')->assertOk();
        $this->getJson('/api/v1/products?q=michelin&page=2')->assertOk();
        $this->getJson('/api/v1/products?q=michelin&page=3')->assertOk();

        // Otherwise a result someone had to scroll through looks more popular
        // than one they found immediately — the opposite of the truth.
        $this->assertSame(1, SearchEvent::count());
    }

    public function test_the_search_is_recorded_as_found_when_products_match(): void
    {
        \App\Models\Product::create([
            'sku' => 'X1', 'brand' => 'Michelin', 'name' => 'Primacy',
            'size' => '225/45R17', 'type' => 'pcr', 'season' => 'Summer',
            'in_stock' => true, 'is_active' => true,
        ]);

        $this->getJson('/api/v1/products?q=Michelin')->assertOk();

        $event = SearchEvent::first();

        $this->assertTrue($event->has_results);
        $this->assertSame(1, $event->results_count);
    }

    public function test_recording_never_breaks_the_catalogue(): void
    {
        // The reporting table is dropped mid-flight, which is exactly what a
        // code-before-migration deploy looks like.
        Schema::dropIfExists('search_events');
        SearchEventRecorder::forgetTableCache();

        $this->getJson('/api/v1/products?q=michelin')->assertOk();
    }

    // ── privacy ───────────────────────────────────────────────────────────

    public function test_no_personal_data_is_stored_with_a_search(): void
    {
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 SomeRealBrowser'])
            ->getJson('/api/v1/products?q=michelin')->assertOk();

        $row = (array) SearchEvent::first()->getAttributes();

        // The IP and user agent are used to derive the daily visitor digest and
        // then discarded. Neither may be reconstructible from a stored row.
        $this->assertArrayNotHasKey('ip_address', $row);
        $this->assertArrayNotHasKey('user_agent', $row);
        $this->assertStringNotContainsString('Mozilla', json_encode($row));
        $this->assertStringNotContainsString('127.0.0.1', json_encode($row));
    }

    public function test_the_visitor_digest_cannot_follow_someone_across_days(): void
    {
        $recorder = app(SearchEventRecorder::class);

        $make = function (string $day) use ($recorder) {
            Carbon::setTestNow(Carbon::parse($day . ' 12:00:00'));

            $request = \Illuminate\Http\Request::create('/api/v1/products', 'GET', ['q' => 'michelin']);
            $request->headers->set('X-Okelcor-Visitor', 'the-same-person');

            $recorder->record($request, [], 3);

            return SearchEvent::latest('id')->first()->visitor_hash;
        };

        $monday  = $make('2026-08-10');
        $tuesday = $make('2026-08-11');

        Carbon::setTestNow();

        // Same person, same id, different day — a different digest. So a day's
        // visitors are countable and a person's history is not assemblable.
        $this->assertNotSame($monday, $tuesday);
    }

    public function test_the_digest_is_hidden_from_serialisation(): void
    {
        $this->search();

        $this->assertArrayNotHasKey('visitor_hash', SearchEvent::first()->toArray());
    }

    // ── the questions this exists to answer ───────────────────────────────

    public function test_it_names_what_was_searched_for_and_never_found(): void
    {
        // The most actionable list in the report: each row is a product to
        // stock or a word the catalogue does not recognise.
        foreach (range(1, 4) as $i) {
            $this->search(['term' => '315/70r22.5', 'has_results' => false, 'results_count' => 0, 'visitor_hash' => "v{$i}"]);
        }

        $this->search(['term' => 'found this one', 'has_results' => true, 'results_count' => 9]);

        $unmet = $this->report()['unmet_demand'];

        $this->assertCount(1, $unmet);
        $this->assertSame('315/70r22.5', $unmet[0]['term']);
        $this->assertSame(4, $unmet[0]['searches']);
        $this->assertSame(4, $unmet[0]['visitors']);
    }

    public function test_a_single_search_is_not_reported_as_a_pattern(): void
    {
        $this->search(['term' => 'a one-off typo', 'has_results' => false, 'results_count' => 0]);

        // One person searching once is not a signal, and presenting it as one
        // sends someone off to stock a product nobody asked for.
        $this->assertSame([], $this->report()['unmet_demand']);
    }

    public function test_it_shows_demand_for_brands_that_cannot_be_bought(): void
    {
        \App\Models\Product::create(['sku' => 'A', 'brand' => 'Pirelli', 'in_stock' => false, 'is_active' => true]);
        \App\Models\Product::create(['sku' => 'B', 'brand' => 'Continental', 'in_stock' => true, 'is_active' => true]);

        foreach (range(1, 3) as $i) {
            $this->search(['brand' => 'Pirelli', 'term' => 'pirelli']);
            $this->search(['brand' => 'Continental', 'term' => 'continental']);
        }

        $this->search(['brand' => 'Nokian', 'term' => 'nokian']);

        $byBrand = collect($this->report()['demand_vs_stock'])->keyBy('brand');

        // Sales figures cannot show this: a product nobody could buy sold
        // nothing, which looks identical to a product nobody wanted.
        $this->assertSame('all_out_of_stock', $byBrand['Pirelli']['status']);
        $this->assertSame('available', $byBrand['Continental']['status']);
        $this->assertSame('not_stocked', $byBrand['Nokian']['status']);
    }

    public function test_the_no_result_rate_is_computed_from_real_rows(): void
    {
        foreach (range(1, 3) as $i) {
            $this->search(['has_results' => false, 'results_count' => 0]);
        }
        $this->search(['has_results' => true, 'results_count' => 4]);

        $summary = $this->report()['summary'];

        $this->assertSame(4, $summary['searches']);
        $this->assertSame(3, $summary['empty_searches']);
        $this->assertSame(75.0, $summary['empty_rate']);
    }

    public function test_the_daily_series_has_no_gaps(): void
    {
        $this->search(['created_at' => now()->subDays(3)]);

        $daily = $this->report()['daily'];

        // A gap in a chart reads as missing data, which is a different claim
        // from "nobody searched that day".
        $this->assertGreaterThan(29, count($daily));

        foreach ($daily as $day) {
            $this->assertArrayHasKey('searches', $day);
            $this->assertIsInt($day['searches']);
        }

        $this->assertSame(1, array_sum(array_column($daily, 'searches')));
    }

    public function test_rim_demand_is_ranked(): void
    {
        foreach (range(1, 5) as $i) {
            $this->search(['rim' => '22.5']);
        }
        $this->search(['rim' => '17']);

        $rims = $this->report()['size_demand']['rim'];

        $this->assertSame('22.5', $rims[0]['value']);
        $this->assertSame(5, $rims[0]['searches']);
    }

    public function test_saved_fitments_count_as_demand(): void
    {
        \App\Models\SavedFitment::create(['customer_id' => 1, 'size' => '295/80R22.5']);
        \App\Models\SavedFitment::create(['customer_id' => 2, 'size' => '295/80R22.5']);

        $fitments = $this->report()['saved_fitments'];

        $this->assertSame('295/80R22.5', $fitments[0]['size']);
        $this->assertSame(2, $fitments[0]['saves']);
        $this->assertSame(2, $fitments[0]['customers']);
    }

    public function test_the_funnel_says_it_is_not_one_persons_journey(): void
    {
        $this->search();
        \App\Models\QuoteRequest::create([]);

        $funnel = $this->report()['funnel'];

        $this->assertSame(1, $funnel['searches']);
        $this->assertSame(1, $funnel['inquiries']);
        // Searches are anonymous and orders are not, so nothing is joined. A
        // funnel implying individual progression would be a confident lie.
        $this->assertStringContainsString('not', strtolower($funnel['note']));
    }

    // ── the endpoint ──────────────────────────────────────────────────────

    public function test_the_endpoint_returns_a_chartable_report(): void
    {
        $this->search();

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/v1/admin/analytics/behaviour?days=7')
            ->assertOk();

        $response->assertJsonStructure([
            'data' => [
                'range', 'available', 'summary', 'daily', 'top_searches',
                'unmet_demand', 'demand_vs_stock', 'brand_demand',
                'size_demand' => ['rim', 'width', 'height'],
                'saved_fitments', 'funnel', 'signed_in_share',
            ],
            'meta' => ['generated_at', 'covers', 'not_covered'],
        ]);

        // The limits are stated in the payload, not only in a document — a
        // dashboard that silently omits a class of behaviour invites someone to
        // conclude it isn't happening.
        $this->assertStringContainsString('Page views', $response->json('meta.not_covered'));
    }

    public function test_the_report_says_so_when_recording_is_not_live_yet(): void
    {
        Schema::dropIfExists('search_events');

        $data = $this->withHeaders($this->headers())
            ->getJson('/api/v1/admin/analytics/behaviour')
            ->assertOk()
            ->json('data');

        // Not the same claim as "nobody searched for anything".
        $this->assertFalse($data['available']);
        $this->assertStringContainsString('not active yet', $data['reason']);
    }

    public function test_the_endpoint_is_permission_gated(): void
    {
        $this->withHeaders($this->headers($this->admin('order_manager')))
            ->getJson('/api/v1/admin/analytics/behaviour')
            ->assertOk();
    }

    public function test_the_range_cannot_be_stretched_past_the_cap(): void
    {
        $this->withHeaders($this->headers())
            ->getJson('/api/v1/admin/analytics/behaviour?days=5000')
            ->assertStatus(422);
    }

    // ── the AI snapshot ───────────────────────────────────────────────────

    public function test_the_insight_snapshot_carries_facts_not_estimates(): void
    {
        foreach (range(1, 3) as $i) {
            $this->search(['term' => '315/70r22.5', 'has_results' => false, 'results_count' => 0, 'visitor_hash' => "v{$i}"]);
        }

        $snapshot = app(CustomerBehaviourService::class)->snapshotForInsights(30);

        $this->assertTrue($snapshot['available']);
        $this->assertSame(3, $snapshot['searches']);
        $this->assertSame(100.0, $snapshot['no_result_rate_pct']);
        $this->assertSame('315/70r22.5', $snapshot['searched_but_never_found'][0]['term']);
    }

    public function test_the_insight_snapshot_is_absent_before_recording_is_live(): void
    {
        Schema::dropIfExists('search_events');

        // The model must not be asked to comment on a period it has no data
        // for — silence and "no demand" are different statements.
        $this->assertFalse(app(CustomerBehaviourService::class)->snapshotForInsights()['available']);
    }
}
