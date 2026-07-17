<?php

namespace Tests\Feature;

use App\Services\EbayService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The size-extraction query builder used to only recognize passenger/truck
 * tyre sizes ("225/45R18"), so a search for a TBR product with a decimal
 * rim ("295/80R22.5") or an OTR product ("23.5R25") silently fell back to
 * sending eBay the raw, over-specific product name — returning few or no
 * results. Fixed to recognize both notations; this locks that in.
 *
 * Pure HTTP-client service — no database involved, so (unlike most of this
 * suite) this runs fine under the default sqlite testing environment.
 */
class EbaySupplierSearchQueryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.ebay.client_id'     => 'test-client',
            'services.ebay.client_secret' => 'test-secret',
            'services.ebay.environment'   => 'sandbox',
        ]);
    }

    private function fakeOAuthAnd(string $browseUrlPattern): void
    {
        Http::fake([
            'api.sandbox.ebay.com/identity/*' => Http::response(['access_token' => 'fake-token'], 200),
            $browseUrlPattern                  => Http::response(['itemSummaries' => []], 200),
        ]);
    }

    public function test_extracts_standard_pcr_size(): void
    {
        $this->fakeOAuthAnd('api.sandbox.ebay.com/buy/browse/*');

        (new EbayService())->searchTyres('YOKOHAMA 225/45R 18 95Y Tl Ad.Sp.V-105 Mo Summer', 20, 'pcr');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'buy/browse')
            && ($request['q'] ?? null) === 'YOKOHAMA 225/45R18');
    }

    public function test_extracts_tbr_size_with_decimal_rim(): void
    {
        $this->fakeOAuthAnd('api.sandbox.ebay.com/buy/browse/*');

        (new EbayService())->searchTyres('MICHELIN 295/80R22.5 XZE2+ Truck Tyre', 20, 'tbr');

        Http::assertSent(fn ($request) => ($request['q'] ?? null) === 'MICHELIN 295/80R22.5');
    }

    public function test_extracts_otr_size_r_notation(): void
    {
        $this->fakeOAuthAnd('api.sandbox.ebay.com/buy/browse/*');

        (new EbayService())->searchTyres('BRIDGESTONE 23.5R25 VSDL Loader Tyre', 20, 'otr');

        Http::assertSent(fn ($request) => ($request['q'] ?? null) === 'BRIDGESTONE 23.5R25');
    }

    public function test_extracts_otr_size_dash_notation(): void
    {
        $this->fakeOAuthAnd('api.sandbox.ebay.com/buy/browse/*');

        (new EbayService())->searchTyres('GOODYEAR 20.5-25 Earthmover', 20, 'otr');

        Http::assertSent(fn ($request) => ($request['q'] ?? null) === 'GOODYEAR 20.5R25');
    }

    public function test_falls_back_to_raw_query_when_no_size_recognized(): void
    {
        $this->fakeOAuthAnd('api.sandbox.ebay.com/buy/browse/*');

        (new EbayService())->searchTyres('some vague description with no size', 20, 'pcr');

        Http::assertSent(fn ($request) => ($request['q'] ?? null) === 'some vague description with no size');
    }
}
