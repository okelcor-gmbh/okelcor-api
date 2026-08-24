<?php

namespace App\Support;

/**
 * Turn however a country was written into one ISO 3166-1 alpha-2 code.
 *
 * This exists because the same market is stored three different ways across
 * this database, and market reporting is meaningless until they agree:
 *
 *   search_events.country      ISO-2, from the CDN's geo header  → "DE"
 *   orders.country             free text, typed or imported      → "Germany"
 *   quote_requests.country     free text, from a web form        → "germany"
 *   customers.country          free text                         → "Deutschland"
 *   marketing_contacts.country free text, from a spreadsheet     → "DE "
 *
 * Joined without normalising, Germany splits into four markets and every
 * figure comes out too low — silently, with no error anywhere. That is the
 * failure mode this class exists to prevent, and it is why `normalise()`
 * returns null rather than guessing: an unrecognised value must be REPORTED
 * (see MarketIntelligenceService's `unrecognised` block), not quietly folded
 * into a neighbour or dropped.
 *
 * Adding a country: put its canonical English name in NAMES, and every
 * spelling seen in real data in ALIASES. Both are keyed by the same slug
 * function, so casing, accents and punctuation are already handled.
 */
class CountryNormaliser
{
    /** ISO-2 → canonical English display name. */
    public const NAMES = [
        // ── EU ────────────────────────────────────────────────────────────
        'AT' => 'Austria',        'BE' => 'Belgium',        'BG' => 'Bulgaria',
        'CY' => 'Cyprus',         'CZ' => 'Czechia',        'DE' => 'Germany',
        'DK' => 'Denmark',        'EE' => 'Estonia',        'ES' => 'Spain',
        'FI' => 'Finland',        'FR' => 'France',         'GR' => 'Greece',
        'HR' => 'Croatia',        'HU' => 'Hungary',        'IE' => 'Ireland',
        'IT' => 'Italy',          'LT' => 'Lithuania',      'LU' => 'Luxembourg',
        'LV' => 'Latvia',         'MT' => 'Malta',          'NL' => 'Netherlands',
        'PL' => 'Poland',         'PT' => 'Portugal',       'RO' => 'Romania',
        'SE' => 'Sweden',         'SI' => 'Slovenia',       'SK' => 'Slovakia',

        // ── Rest of Europe ────────────────────────────────────────────────
        'AL' => 'Albania',        'BA' => 'Bosnia and Herzegovina',
        'BY' => 'Belarus',        'CH' => 'Switzerland',    'GB' => 'United Kingdom',
        'IS' => 'Iceland',        'MD' => 'Moldova',        'ME' => 'Montenegro',
        'MK' => 'North Macedonia','NO' => 'Norway',         'RS' => 'Serbia',
        'RU' => 'Russia',         'TR' => 'Türkiye',        'UA' => 'Ukraine',
        'XK' => 'Kosovo',

        // ── Africa ────────────────────────────────────────────────────────
        'AO' => 'Angola',         'BF' => 'Burkina Faso',   'BJ' => 'Benin',
        'CD' => 'DR Congo',       'CG' => 'Congo',          'CI' => "Côte d'Ivoire",
        'CM' => 'Cameroon',       'DZ' => 'Algeria',        'EG' => 'Egypt',
        'ET' => 'Ethiopia',       'GH' => 'Ghana',          'GM' => 'Gambia',
        'GN' => 'Guinea',         'KE' => 'Kenya',          'LR' => 'Liberia',
        'LY' => 'Libya',          'MA' => 'Morocco',        'ML' => 'Mali',
        'MZ' => 'Mozambique',     'NE' => 'Niger',          'NG' => 'Nigeria',
        'RW' => 'Rwanda',         'SN' => 'Senegal',        'SL' => 'Sierra Leone',
        'SD' => 'Sudan',          'TG' => 'Togo',           'TN' => 'Tunisia',
        'TZ' => 'Tanzania',       'UG' => 'Uganda',         'ZA' => 'South Africa',
        'ZM' => 'Zambia',         'ZW' => 'Zimbabwe',

        // ── Middle East ───────────────────────────────────────────────────
        'AE' => 'United Arab Emirates', 'IL' => 'Israel',   'IQ' => 'Iraq',
        'JO' => 'Jordan',         'KW' => 'Kuwait',         'LB' => 'Lebanon',
        'OM' => 'Oman',           'QA' => 'Qatar',          'SA' => 'Saudi Arabia',
        'SY' => 'Syria',          'YE' => 'Yemen',

        // ── Asia ──────────────────────────────────────────────────────────
        'AF' => 'Afghanistan',    'BD' => 'Bangladesh',     'CN' => 'China',
        'HK' => 'Hong Kong',      'ID' => 'Indonesia',      'IN' => 'India',
        'JP' => 'Japan',          'KR' => 'South Korea',    'KZ' => 'Kazakhstan',
        'LK' => 'Sri Lanka',      'MY' => 'Malaysia',       'PH' => 'Philippines',
        'PK' => 'Pakistan',       'SG' => 'Singapore',      'TH' => 'Thailand',
        'TW' => 'Taiwan',         'UZ' => 'Uzbekistan',     'VN' => 'Vietnam',

        // ── Americas & Oceania ────────────────────────────────────────────
        'AR' => 'Argentina',      'AU' => 'Australia',      'BR' => 'Brazil',
        'CA' => 'Canada',         'CL' => 'Chile',          'CO' => 'Colombia',
        'MX' => 'Mexico',         'NZ' => 'New Zealand',    'PE' => 'Peru',
        'US' => 'United States',  'UY' => 'Uruguay',
    ];

    /**
     * Alternative spellings → ISO-2, keyed by slug().
     *
     * Only what is actually plausible in this data: native names for the
     * European markets Okelcor sells into, the handful of English variants
     * that differ from NAMES, and the market slugs already used by
     * marketing_contact_markets.
     */
    private const ALIASES = [
        'deutschland' => 'DE', 'germania' => 'DE', 'allemagne' => 'DE', 'ger' => 'DE',
        'osterreich' => 'AT', 'oesterreich' => 'AT', 'austria' => 'AT',
        'hrvatska' => 'HR', 'kroatien' => 'HR', 'croatie' => 'HR',
        'cesko' => 'CZ', 'ceska republika' => 'CZ', 'czech' => 'CZ',
        'czech republic' => 'CZ', 'tschechien' => 'CZ',
        'france metropolitaine' => 'FR', 'frankreich' => 'FR', 'francia' => 'FR',
        'nederland' => 'NL', 'holland' => 'NL', 'the netherlands' => 'NL',
        'belgie' => 'BE', 'belgique' => 'BE', 'belgien' => 'BE',
        'espana' => 'ES', 'spanien' => 'ES', 'espagne' => 'ES',
        'italia' => 'IT', 'italien' => 'IT', 'italie' => 'IT',
        'polska' => 'PL', 'polen' => 'PL', 'pologne' => 'PL',
        'sverige' => 'SE', 'schweden' => 'SE',
        'danmark' => 'DK', 'danemark' => 'DK',
        'suomi' => 'FI', 'finnland' => 'FI',
        'norge' => 'NO', 'norwegen' => 'NO',
        'schweiz' => 'CH', 'suisse' => 'CH', 'svizzera' => 'CH',
        'magyarorszag' => 'HU', 'ungarn' => 'HU',
        'romania' => 'RO', 'rumanien' => 'RO',
        'ellada' => 'GR', 'griechenland' => 'GR', 'hellas' => 'GR',
        'portugal' => 'PT', 'slovensko' => 'SK', 'slovenija' => 'SI',
        'eesti' => 'EE', 'latvija' => 'LV', 'lietuva' => 'LT',
        'balgariya' => 'BG', 'bulgarien' => 'BG',
        'eire' => 'IE', 'republic of ireland' => 'IE',

        'uk' => 'GB', 'u k' => 'GB', 'great britain' => 'GB', 'england' => 'GB',
        'scotland' => 'GB', 'wales' => 'GB', 'northern ireland' => 'GB',
        'united kingdom of great britain and northern ireland' => 'GB',
        'grossbritannien' => 'GB', 'britain' => 'GB',

        'usa' => 'US', 'u s a' => 'US', 'united states of america' => 'US',
        'america' => 'US', 'us' => 'US',
        'uae' => 'AE', 'u a e' => 'AE', 'emirates' => 'AE',
        'turkey' => 'TR', 'turkiye' => 'TR', 'tuerkiye' => 'TR',
        'south korea' => 'KR', 'republic of korea' => 'KR', 'korea' => 'KR',
        'ivory coast' => 'CI', 'cote divoire' => 'CI', "cote d ivoire" => 'CI',
        'congo kinshasa' => 'CD', 'democratic republic of the congo' => 'CD',
        'drc' => 'CD',
        'macedonia' => 'MK', 'bosnia' => 'BA', 'herzegovina' => 'BA',
        'russian federation' => 'RU', 'russland' => 'RU',
        'viet nam' => 'VN', 'srpska' => 'RS', 'serbien' => 'RS',
        'south africa' => 'ZA', 'sudafrika' => 'ZA',
        'nigeria' => 'NG', 'ghana' => 'GH',
    ];

    /**
     * Market slugs used by marketing_contact_markets that name a REGION
     * rather than a country. Mapped deliberately to null: "asia" is not a
     * country and pretending otherwise would put every Asian contact under
     * one flag on a per-country report.
     */
    public const REGION_SLUGS = ['asia', 'europe', 'africa', 'middle east', 'americas', 'test'];

    /**
     * @return string|null ISO-2, or null when the value cannot be resolved.
     */
    public static function normalise(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $slug = self::slug($value);

        if ($slug === '') {
            return null;
        }

        // A region name is a known non-answer, not an unrecognised value.
        if (in_array($slug, self::REGION_SLUGS, true)) {
            return null;
        }

        // Already an ISO-2 code.
        $upper = strtoupper($slug);
        if (strlen($upper) === 2 && isset(self::NAMES[$upper])) {
            return $upper;
        }

        // Canonical English name.
        foreach (self::NAMES as $code => $name) {
            if (self::slug($name) === $slug) {
                return $code;
            }
        }

        return self::ALIASES[$slug] ?? null;
    }

    /** Display name for a code, falling back to the code itself. */
    public static function name(?string $code): string
    {
        if ($code === null) {
            return 'Unknown';
        }

        return self::NAMES[strtoupper($code)] ?? strtoupper($code);
    }

    public static function isKnown(?string $code): bool
    {
        return $code !== null && isset(self::NAMES[strtoupper($code)]);
    }

    /**
     * Lowercase, strip accents and punctuation, collapse whitespace.
     * "  Côte d'Ivoire " and "cote d ivoire" both become "cote d ivoire".
     */
    private static function slug(string $value): string
    {
        $value = trim($value);

        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        if ($transliterated !== false) {
            $value = $transliterated;
        }

        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }
}
