<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Resolves the best content locale for a visitor.
 *
 * Resolution priority (first match wins):
 *   1. Explicit ?locale= override (must be a supported locale)
 *   2. Country -> locale map (?country= param, then geo headers)
 *   3. Accept-Language header (best-effort)
 *   4. Default locale (English)
 *
 * Backed entirely by config/i18n.php so there is a single source of truth.
 */
class LocaleResolver
{
    /** @return list<string> */
    public static function supported(): array
    {
        return (array) config('i18n.supported', ['en']);
    }

    public static function default(): string
    {
        return (string) config('i18n.default', 'en');
    }

    public static function isSupported(?string $locale): bool
    {
        return $locale !== null && in_array($locale, self::supported(), true);
    }

    /**
     * Map an ISO 3166-1 alpha-2 country code to a supported locale.
     * Returns the default locale for unknown / unmapped countries.
     */
    public static function localeForCountry(?string $country): string
    {
        if (! $country) {
            return self::default();
        }

        $map     = (array) config('i18n.country_locale', []);
        $country = strtoupper(trim($country));

        $locale = $map[$country] ?? self::default();

        return self::isSupported($locale) ? $locale : self::default();
    }

    /**
     * Full resolution for a request, returning the locale and how it was chosen.
     *
     * @return array{locale:string, country:?string, source:string}
     */
    public static function resolve(Request $request): array
    {
        // 1. Explicit locale override.
        $explicit = $request->query('locale');
        if (self::isSupported($explicit)) {
            return ['locale' => $explicit, 'country' => null, 'source' => 'explicit'];
        }

        // 2. Country -> locale (param first, then CDN geo headers).
        $country = self::countryFromRequest($request);
        if ($country) {
            $map = (array) config('i18n.country_locale', []);
            $key = strtoupper($country);

            if (isset($map[$key]) && self::isSupported($map[$key])) {
                return ['locale' => $map[$key], 'country' => $key, 'source' => 'country'];
            }

            // Known country, but not a supported language -> default.
            return ['locale' => self::default(), 'country' => $key, 'source' => 'country_default'];
        }

        // 3. Accept-Language header (best effort).
        $fromHeader = self::localeFromAcceptLanguage($request);
        if ($fromHeader) {
            return ['locale' => $fromHeader, 'country' => null, 'source' => 'accept_language'];
        }

        // 4. Fallback.
        return ['locale' => self::default(), 'country' => null, 'source' => 'default'];
    }

    /**
     * Extract a country code from the explicit ?country= param or a CDN geo header.
     */
    public static function countryFromRequest(Request $request): ?string
    {
        $param = $request->query('country');
        if (is_string($param) && strlen(trim($param)) === 2) {
            return strtoupper(trim($param));
        }

        foreach ((array) config('i18n.geo_headers', []) as $header) {
            $value = $request->header($header);
            if (is_string($value) && strlen(trim($value)) === 2) {
                $code = strtoupper(trim($value));
                // Cloudflare uses "XX" for unknown/anonymised IPs.
                if ($code !== 'XX' && ctype_alpha($code)) {
                    return $code;
                }
            }
        }

        return null;
    }

    /**
     * Pick the first supported locale from the Accept-Language header.
     */
    public static function localeFromAcceptLanguage(Request $request): ?string
    {
        $header = $request->header('Accept-Language');
        if (! is_string($header) || $header === '') {
            return null;
        }

        // e.g. "de-DE,de;q=0.9,en;q=0.8" -> ordered list of base language tags.
        $tags = [];
        foreach (explode(',', $header) as $part) {
            $lang = strtolower(trim(explode(';', $part)[0]));
            $base = explode('-', $lang)[0];
            if ($base !== '' && ! in_array($base, $tags, true)) {
                $tags[] = $base;
            }
        }

        foreach ($tags as $base) {
            if (self::isSupported($base)) {
                return $base;
            }
        }

        return null;
    }
}
