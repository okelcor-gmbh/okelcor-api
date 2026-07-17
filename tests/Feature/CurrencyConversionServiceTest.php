<?php

namespace Tests\Feature;

use App\Services\CurrencyConversionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pure HTTP-client service — no database involved, so (unlike most of this
 * suite) this runs fine under the default sqlite testing environment.
 */
class CurrencyConversionServiceTest extends TestCase
{
    public function test_returns_rate_one_when_currencies_match(): void
    {
        $result = (new CurrencyConversionService())->getRate('EUR', 'EUR');

        $this->assertSame(1.0, $result['rate']);
    }

    public function test_fetches_and_returns_rate_from_frankfurter(): void
    {
        Http::fake([
            'api.frankfurter.app/*' => Http::response(['amount' => 1.0, 'base' => 'EUR', 'date' => '2026-07-17', 'rates' => ['USD' => 1.0842]], 200),
        ]);

        $result = (new CurrencyConversionService())->getRate('EUR', 'USD');

        $this->assertSame(1.0842, $result['rate']);
        $this->assertSame('2026-07-17', $result['date']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'from=EUR') && str_contains($request->url(), 'to=USD'));
    }

    public function test_caches_rate_for_the_rest_of_the_day(): void
    {
        Cache::flush();
        Http::fake([
            'api.frankfurter.app/*' => Http::response(['date' => '2026-07-17', 'rates' => ['USD' => 1.05]], 200),
        ]);

        (new CurrencyConversionService())->getRate('EUR', 'USD');
        (new CurrencyConversionService())->getRate('EUR', 'USD');

        Http::assertSentCount(1);
    }

    public function test_throws_when_api_call_fails(): void
    {
        Http::fake(['api.frankfurter.app/*' => Http::response('Server Error', 500)]);

        $this->expectException(\RuntimeException::class);
        (new CurrencyConversionService())->getRate('EUR', 'USD');
    }

    public function test_throws_when_target_currency_missing_from_response(): void
    {
        Http::fake(['api.frankfurter.app/*' => Http::response(['date' => '2026-07-17', 'rates' => []], 200)]);

        $this->expectException(\RuntimeException::class);
        (new CurrencyConversionService())->getRate('EUR', 'USD');
    }
}
