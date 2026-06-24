<?php

namespace Tests\Feature;

use Illuminate\Routing\Middleware\ThrottleRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Locale auto-detection (country -> language).
 *
 * Verifies GET /api/v1/i18n/locales and GET /api/v1/i18n/resolve: a visitor in
 * a country where a supported language is spoken auto-switches to it, and every
 * other country falls back to English. No database — pure config negotiation.
 *
 * Run with:
 *   php artisan test --filter=LocaleResolution
 */
class LocaleResolutionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_locales_endpoint_exposes_supported_default_and_map(): void
    {
        $res = $this->getJson('/api/v1/i18n/locales');

        $res->assertOk()
            ->assertJsonPath('data.default', 'en')
            ->assertJsonPath('data.country_locale.DE', 'de')
            ->assertJsonPath('data.country_locale.MX', 'es')
            ->assertJsonPath('data.country_locale.FR', 'fr');

        $this->assertEqualsCanonicalizing(
            ['en', 'de', 'fr', 'es'],
            $res->json('data.supported')
        );
    }

    #[DataProvider('countryLocaleProvider')]
    public function test_resolve_maps_country_to_locale(string $country, string $expected, bool $isDefault): void
    {
        $this->getJson('/api/v1/i18n/resolve?country=' . $country)
            ->assertOk()
            ->assertJsonPath('data.locale', $expected)
            ->assertJsonPath('data.country', strtoupper($country))
            ->assertJsonPath('data.is_default', $isDefault);
    }

    public static function countryLocaleProvider(): array
    {
        return [
            'Germany -> de'        => ['DE', 'de', false],
            'Austria -> de'        => ['AT', 'de', false],
            'France -> fr'         => ['FR', 'fr', false],
            'Belgium -> fr'        => ['BE', 'fr', false],
            'Spain -> es'          => ['ES', 'es', false],
            'Mexico -> es'         => ['MX', 'es', false],
            'lowercase de'         => ['de', 'de', false],
            'USA -> en (default)'  => ['US', 'en', true],
            'UK -> en (default)'   => ['GB', 'en', true],
            'Japan -> en (default)'=> ['JP', 'en', true],
        ];
    }

    public function test_explicit_locale_param_overrides_country(): void
    {
        $this->getJson('/api/v1/i18n/resolve?locale=es&country=DE')
            ->assertOk()
            ->assertJsonPath('data.locale', 'es')
            ->assertJsonPath('data.source', 'explicit');
    }

    public function test_cloudflare_geo_header_is_used_when_no_param(): void
    {
        $this->withHeader('CF-IPCountry', 'MX')
            ->getJson('/api/v1/i18n/resolve')
            ->assertOk()
            ->assertJsonPath('data.locale', 'es')
            ->assertJsonPath('data.country', 'MX')
            ->assertJsonPath('data.source', 'country');
    }

    public function test_anonymised_cloudflare_country_falls_back(): void
    {
        $this->withHeader('CF-IPCountry', 'XX')
            ->withHeader('Accept-Language', '')
            ->getJson('/api/v1/i18n/resolve')
            ->assertOk()
            ->assertJsonPath('data.locale', 'en')
            ->assertJsonPath('data.is_default', true);
    }

    public function test_accept_language_used_when_no_country(): void
    {
        $this->withHeader('Accept-Language', 'de-DE,de;q=0.9,en;q=0.8')
            ->getJson('/api/v1/i18n/resolve')
            ->assertOk()
            ->assertJsonPath('data.locale', 'de')
            ->assertJsonPath('data.source', 'accept_language');
    }
}
