<?php

namespace Tests\Feature;

use App\Models\AdminInsight;
use App\Models\AdminUser;
use App\Services\AdminInsightsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AdminInsightsService::generate() + GET /admin/insights.
 *
 * Run with:
 *   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=okelcor_cms_test \
 *   DB_USERNAME=root DB_PASSWORD= php artisan test --filter=AdminInsightsService
 */
class AdminInsightsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $connection = getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? 'sqlite');
        if ($connection !== 'mysql') {
            $this->markTestSkipped('These tests require a MySQL connection (legacy migrations are MySQL-only).');
        }

        parent::setUp();

        config([
            'services.gemini.api_key'  => 'test-key',
            'services.gemini.model'    => 'gemini-2.0-flash',
            'services.gemini.base_url' => 'https://generativelanguage.test/v1beta',
        ]);
    }

    private function admin(): AdminUser
    {
        return AdminUser::create([
            'name'                    => 'Ops ' . uniqid(),
            'email'                   => 'ops' . uniqid() . '@okelcor.test',
            'role'                    => 'admin',
            'password'                => Hash::make('secret-pass-123'),
            'is_active'               => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function fakeGeminiResponse(array $insights): void
    {
        Http::fake([
            'generativelanguage.test/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => json_encode(['insights' => $insights])]]],
                ]],
            ], 200),
        ]);
    }

    public function test_does_nothing_when_gemini_api_key_missing(): void
    {
        config(['services.gemini.api_key' => null]);

        app(AdminInsightsService::class)->generate();

        $this->assertDatabaseCount('admin_insights', 0);
    }

    public function test_generate_persists_ranked_insights_from_gemini(): void
    {
        $this->fakeGeminiResponse([
            ['category' => 'revenue', 'severity' => 'positive', 'headline' => 'Revenue up today', 'detail' => 'Revenue rose versus yesterday.'],
            ['category' => 'inventory', 'severity' => 'warning', 'headline' => 'Stock running low', 'detail' => 'One SKU is close to stockout.', 'action_url' => '/admin/products/42'],
        ]);

        app(AdminInsightsService::class)->generate();

        $this->assertDatabaseCount('admin_insights', 2);

        $first = AdminInsight::orderBy('rank')->first();
        $this->assertSame('revenue', $first->category);
        $this->assertSame(0, $first->rank);
        $this->assertStringStartsWith('ins_', $first->external_id);

        $second = AdminInsight::orderBy('rank')->skip(1)->first();
        $this->assertSame('inventory', $second->category);
        $this->assertSame('/admin/products/42', $second->action_url);
    }

    public function test_generate_discards_insights_with_invalid_category_or_severity(): void
    {
        $this->fakeGeminiResponse([
            ['category' => 'not_a_real_category', 'severity' => 'positive', 'headline' => 'Bad', 'detail' => 'Bad.'],
            ['category' => 'revenue', 'severity' => 'not_a_real_severity', 'headline' => 'Also bad', 'detail' => 'Also bad.'],
            ['category' => 'revenue', 'severity' => 'info', 'headline' => 'Good one', 'detail' => 'This one is valid.'],
        ]);

        app(AdminInsightsService::class)->generate();

        $this->assertDatabaseCount('admin_insights', 1);
        $this->assertSame('Good one', AdminInsight::first()->headline);
    }

    public function test_generate_caps_at_four_insights(): void
    {
        $this->fakeGeminiResponse(array_fill(0, 8, [
            'category' => 'revenue', 'severity' => 'info', 'headline' => 'Repeated headline', 'detail' => 'Repeated detail.',
        ]));

        app(AdminInsightsService::class)->generate();

        $this->assertDatabaseCount('admin_insights', 4);
    }

    public function test_generate_keeps_previous_batch_when_gemini_call_fails(): void
    {
        $this->fakeGeminiResponse([
            ['category' => 'revenue', 'severity' => 'info', 'headline' => 'First batch', 'detail' => 'From the first successful run.'],
        ]);
        app(AdminInsightsService::class)->generate();
        $this->assertDatabaseCount('admin_insights', 1);

        Http::fake(['generativelanguage.test/*' => Http::response('Server Error', 500)]);
        app(AdminInsightsService::class)->generate();

        $this->assertDatabaseCount('admin_insights', 1);
        $this->assertSame('First batch', AdminInsight::first()->headline);
    }

    public function test_endpoint_returns_only_the_latest_batch(): void
    {
        AdminInsight::create([
            'external_id' => 'ins_old_01', 'category' => 'revenue', 'severity' => 'info',
            'headline' => 'Old insight', 'detail' => 'Old.', 'rank' => 0,
            'generated_at' => now()->subHour(),
        ]);
        AdminInsight::create([
            'external_id' => 'ins_new_01', 'category' => 'security', 'severity' => 'warning',
            'headline' => 'New insight', 'detail' => 'New.', 'rank' => 0,
            'generated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin(), 'sanctum')->getJson('/api/v1/admin/insights');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.headline', 'New insight');
    }

    public function test_endpoint_returns_empty_when_nothing_generated_yet(): void
    {
        $response = $this->actingAs($this->admin(), 'sanctum')->getJson('/api/v1/admin/insights');

        $response->assertOk();
        $response->assertJsonPath('data', []);
        $response->assertJsonPath('generated_at', null);
    }
}
