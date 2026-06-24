<?php

/*
|--------------------------------------------------------------------------
| Internationalisation / Locale Negotiation
|--------------------------------------------------------------------------
|
| Single source of truth for the public site's supported content locales
| and the country -> locale auto-detection map.
|
| The frontend knows the visitor's country (Vercel/Cloudflare geo). It can
| either fetch this whole map once (GET /api/v1/i18n/locales) and resolve
| client-side, or send the country to GET /api/v1/i18n/resolve and let the
| backend pick. Either way the rule is the same:
|
|   - Visitor is in a country where a supported language is spoken
|     -> auto-switch to that language.
|   - Any other country -> stay on the default (English).
|
| Keep this in sync with the locale ENUM on the *_translations tables
| (en, de, fr, es). Adding a new language means: add it to `supported`,
| extend `country_locale`, and widen the ENUM via a migration.
|
*/

return [

    // Locales the CMS actually stores translations for.
    'supported' => ['en', 'de', 'fr', 'es'],

    // Fallback used for any country not in the map below, and whenever a
    // requested locale is unknown. English is the catch-all.
    'default' => 'en',

    /*
    |--------------------------------------------------------------------------
    | Country (ISO 3166-1 alpha-2) -> locale
    |--------------------------------------------------------------------------
    |
    | Only countries where one of our supported languages is an official or
    | widely-spoken business language are listed. Everything else falls back
    | to `default` (English) automatically — do NOT list English-speaking
    | countries here, that is what the fallback is for.
    |
    | Keys MUST be uppercase ISO alpha-2 codes.
    |
    */
    'country_locale' => [

        // ---- German (de) ----
        'DE' => 'de', // Germany
        'AT' => 'de', // Austria
        'CH' => 'de', // Switzerland (German is the largest language group)
        'LI' => 'de', // Liechtenstein

        // ---- French (fr) ----
        'FR' => 'fr', // France
        'BE' => 'fr', // Belgium (French is the common cross-community business language)
        'LU' => 'fr', // Luxembourg
        'MC' => 'fr', // Monaco
        'CI' => 'fr', // Côte d'Ivoire
        'SN' => 'fr', // Senegal
        'CM' => 'fr', // Cameroon
        'CD' => 'fr', // DR Congo
        'CG' => 'fr', // Congo
        'GA' => 'fr', // Gabon
        'ML' => 'fr', // Mali
        'BF' => 'fr', // Burkina Faso
        'NE' => 'fr', // Niger
        'TG' => 'fr', // Togo
        'BJ' => 'fr', // Benin
        'GN' => 'fr', // Guinea
        'MG' => 'fr', // Madagascar
        'TN' => 'fr', // Tunisia
        'DZ' => 'fr', // Algeria
        'MA' => 'fr', // Morocco

        // ---- Spanish (es) ----
        'ES' => 'es', // Spain
        'MX' => 'es', // Mexico
        'AR' => 'es', // Argentina
        'CO' => 'es', // Colombia
        'CL' => 'es', // Chile
        'PE' => 'es', // Peru
        'VE' => 'es', // Venezuela
        'EC' => 'es', // Ecuador
        'GT' => 'es', // Guatemala
        'CU' => 'es', // Cuba
        'BO' => 'es', // Bolivia
        'DO' => 'es', // Dominican Republic
        'HN' => 'es', // Honduras
        'PY' => 'es', // Paraguay
        'SV' => 'es', // El Salvador
        'NI' => 'es', // Nicaragua
        'CR' => 'es', // Costa Rica
        'PA' => 'es', // Panama
        'UY' => 'es', // Uruguay
        'PR' => 'es', // Puerto Rico
    ],

    /*
    | Request headers, in priority order, that may carry the visitor's
    | ISO country code when the request reaches this API directly behind a
    | CDN. Best-effort only — the frontend passing ?country=XX is canonical.
    */
    'geo_headers' => [
        'CF-IPCountry',          // Cloudflare
        'X-Vercel-IP-Country',   // Vercel
        'X-Country-Code',        // generic / custom proxy
        'X-Geo-Country',
    ],
];
