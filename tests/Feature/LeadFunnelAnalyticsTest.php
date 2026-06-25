<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\AdminLeadFunnelController;
use App\Models\QuoteRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Lead → quote → order funnel analytics (backend).
 *
 * Mirrors the CRM-3B harness: runs the full migration set on MySQL and drives
 * the controller directly. Verifies the funnel stages, conversion rates, source
 * breakdown, and UTM attribution derived from the qualification pipeline.
 *
 * Run with:
 *   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=<test_db> \
 *   DB_USERNAME=root DB_PASSWORD= php artisan test --filter=LeadFunnel
 */
class LeadFunnelAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $connection = getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? 'sqlite');
        if ($connection !== 'mysql') {
            $this->markTestSkipped('Funnel tests require a MySQL connection (legacy migrations are MySQL-only).');
        }

        parent::setUp();
    }

    private function quote(array $overrides = []): QuoteRequest
    {
        return QuoteRequest::create(array_merge([
            'ref_number'           => 'QR-' . uniqid(),
            'full_name'            => 'Acme Buyer',
            'company_name'         => 'Acme Tyres GmbH',
            'email'                => 'buyer' . uniqid() . '@acme-tyres.com',
            'country'              => 'DE',
            'tyre_category'        => 'pcr',
            'quantity'             => '100',
            'delivery_location'    => 'Berlin, DE',
            'notes'                => 'Test inquiry',
            'status'               => 'new',
            'qualification_status' => 'new',
        ], $overrides));
    }

    private function funnel(array $query = []): array
    {
        $response = (new AdminLeadFunnelController())->index(
            Request::create('/api/v1/admin/quote-requests/funnel', 'GET', $query)
        );

        return $response->getData(true)['data'];
    }

    public function test_funnel_stages_and_conversion_rates(): void
    {
        // Source A: 1 converted, 1 qualified. Source B: 1 new. Plus 1 spam.
        $this->quote(['lead_source' => 'website_quote', 'qualification_status' => 'converted']);
        $this->quote(['lead_source' => 'website_quote', 'qualification_status' => 'qualified']);
        $this->quote(['lead_source' => 'tyre_wholesaler_landing', 'qualification_status' => 'new']);
        $this->quote(['lead_source' => 'website_quote', 'qualification_status' => 'spam']);

        $data = $this->funnel();

        // 3 real leads (spam excluded), 2 qualified+, 1 converted.
        $this->assertSame(3, $data['totals']['total_leads']);
        $this->assertSame(1, $data['totals']['spam']);
        $this->assertSame(2, $data['totals']['qualified']);
        $this->assertSame(1, $data['totals']['converted']);
        // Rates go through JSON, so compare loosely (33.3 stays float; whole numbers may decode as int).
        $this->assertEquals(33.3, $data['totals']['overall_conversion_rate']);

        // Funnel array is ordered leads → qualified → proposal_sent → converted.
        $this->assertSame('leads', $data['funnel'][0]['stage']);
        $this->assertSame(3, $data['funnel'][0]['count']);
        $this->assertSame('converted', $data['funnel'][3]['stage']);
        $this->assertSame(1, $data['funnel'][3]['count']);
    }

    public function test_breakdown_by_source(): void
    {
        $this->quote(['lead_source' => 'website_quote', 'qualification_status' => 'converted']);
        $this->quote(['lead_source' => 'website_quote', 'qualification_status' => 'new']);
        $this->quote(['lead_source' => 'tyre_wholesaler_landing', 'qualification_status' => 'qualified']);

        $bySource = collect($this->funnel()['by_source']);

        $web = $bySource->firstWhere('source', 'website_quote');
        $this->assertSame(2, $web['leads']);
        $this->assertSame(1, $web['converted']);
        $this->assertEquals(50.0, $web['conversion_rate']);

        // Sorted by lead count descending — website_quote (2) before landing (1).
        $this->assertSame('website_quote', $bySource->first()['source']);
    }

    public function test_utm_attribution_from_lead_metadata(): void
    {
        $this->quote([
            'lead_source'          => 'tyre_wholesaler_landing',
            'qualification_status' => 'converted',
            'lead_metadata'        => ['utm_source' => 'google', 'utm_campaign' => 'tyres-de'],
        ]);
        $this->quote([
            'lead_source'          => 'tyre_wholesaler_landing',
            'qualification_status' => 'new',
            'lead_metadata'        => ['utm_source' => 'google', 'utm_campaign' => 'tyres-de'],
        ]);

        $attribution = $this->funnel()['by_attribution'];

        $google = collect($attribution['utm_source'])->firstWhere('value', 'google');
        $this->assertSame(2, $google['leads']);
        $this->assertSame(1, $google['converted']);
    }

    public function test_empty_range_is_safe(): void
    {
        $data = $this->funnel(['from' => '2000-01-01', 'to' => '2000-01-31']);

        $this->assertSame(0, $data['totals']['total_leads']);
        $this->assertEquals(0.0, $data['totals']['overall_conversion_rate']);
    }
}
