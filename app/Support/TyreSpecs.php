<?php

namespace App\Support;

use App\Models\Product;

/**
 * The tyre specification sheet — the single source for which attributes exist,
 * what each may hold, and where each lives.
 *
 * From the marketing team's product-optimization brief (Session 92), which is
 * the Artikelmerkmale block of a German tyre listing: EU-label classes, the
 * 3PMSF snowflake, EPREL registration, and the rest. Two constraints shaped
 * how it is stored:
 *
 * 1. **Half of these attributes already exist as real columns** — width,
 *    aspect ratio, rim, load/speed index, EAN, tread depth were added across
 *    Sessions 14–71 and the catalogue import writes them. Storing them a
 *    second time inside a JSON blob would create two places for one fact to
 *    live and eventually disagree. So every spec declares its `source`:
 *    `column` reads and writes the existing column, `json` lives in the new
 *    `products.specs` JSON, and `derived` is computed and not stored at all.
 *
 * 2. **The sheet will grow.** eBay adds attributes, the EU label changes,
 *    marketing finds another field. Served from here over the API (the
 *    upload-options pattern from Session 76), the admin form and the public
 *    spec table both render whatever this list says — adding a spec is one
 *    entry here and no frontend deploy.
 *
 * Labels are bilingual because the audience is: the brief is German, the
 * catalogue and admin panel are English, and the public site serves both.
 */
class TyreSpecs
{
    /** EU tyre-label classes, best to worst. */
    public const LABEL_CLASSES = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];

    /**
     * Every spec, in the order the sheet displays them.
     *
     * `input`: how the admin form edits it — `select` (from `options`),
     * `boolean` (rendered Ja/Nein), `text`, or `none` (derived, read-only).
     * `source`: `column` (+ which), `json`, or `derived`.
     */
    public const SHEET = [
        ['key' => 'reifenzustand',                'label_de' => 'Reifenzustand',                    'label_en' => 'Condition',                  'input' => 'none',    'source' => 'derived'],
        ['key' => 'schneeflocken_symbol',         'label_de' => 'Schneeflocken-Symbol (3PMSF)',     'label_en' => 'Snowflake symbol (3PMSF)',   'input' => 'boolean', 'source' => 'json'],
        ['key' => 'reifenbreite',                 'label_de' => 'Reifenbreite',                     'label_en' => 'Tyre width',                 'input' => 'text',    'source' => 'column', 'column' => 'width'],
        ['key' => 'zollgroesse',                  'label_de' => 'Zollgröße',                        'label_en' => 'Rim diameter (inches)',      'input' => 'text',    'source' => 'column', 'column' => 'rim'],
        ['key' => 'reifenquerschnitt',            'label_de' => 'Reifenquerschnitt',                'label_en' => 'Aspect ratio',               'input' => 'text',    'source' => 'column', 'column' => 'height'],
        ['key' => 'nasshaftungseigenschaften',    'label_de' => 'Nasshaftungseigenschaften',        'label_en' => 'Wet grip class',             'input' => 'select',  'source' => 'json', 'options' => self::LABEL_CLASSES],
        ['key' => 'reifenkraftstoffeffizienz',    'label_de' => 'Reifenkraftstoffeffizienz',        'label_en' => 'Fuel efficiency class',      'input' => 'select',  'source' => 'json', 'options' => self::LABEL_CLASSES],
        ['key' => 'externes_rollgeraeusch_klasse', 'label_de' => 'Externes Rollgeräusch (Klasse)',  'label_en' => 'External rolling noise (class)', 'input' => 'select', 'source' => 'json', 'options' => self::LABEL_CLASSES],
        ['key' => 'externes_rollgeraeusch_db',    'label_de' => 'Externes Rollgeräusch (dB)',       'label_en' => 'External rolling noise (dB)', 'input' => 'text',   'source' => 'json'],
        ['key' => 'eisgriffigkeit',               'label_de' => 'Eisgriffigkeit',                   'label_en' => 'Ice grip',                   'input' => 'boolean', 'source' => 'json'],
        ['key' => 'fahrzeugtyp',                  'label_de' => 'Fahrzeugtyp',                      'label_en' => 'Vehicle type',               'input' => 'text',    'source' => 'json'],
        ['key' => 'produktlinie',                 'label_de' => 'Produktlinie',                     'label_en' => 'Product line',               'input' => 'text',    'source' => 'json'],
        ['key' => 'profiltiefe',                  'label_de' => 'Profiltiefe (mm)',                 'label_en' => 'Tread depth (mm)',           'input' => 'text',    'source' => 'column', 'column' => 'tread_depth_mm'],
        ['key' => 'lastbereich',                  'label_de' => 'Lastbereich',                      'label_en' => 'Load range',                 'input' => 'text',    'source' => 'json'],
        ['key' => 'modell',                       'label_de' => 'Modell',                           'label_en' => 'Model',                      'input' => 'text',    'source' => 'column', 'column' => 'name'],
        ['key' => 'gesamtdurchmesser',            'label_de' => 'Gesamtdurchmesser',                'label_en' => 'Overall diameter',           'input' => 'text',    'source' => 'json'],
        ['key' => 'zusaetzliche_kennzeichnungen', 'label_de' => 'Zusätzliche Kennzeichnungen',      'label_en' => 'Additional markings',        'input' => 'text',    'source' => 'json'],
        ['key' => 'reifenbauart',                 'label_de' => 'Reifenbauart',                     'label_en' => 'Tyre construction',          'input' => 'text',    'source' => 'json'],
        ['key' => 'reifenspezifikation',          'label_de' => 'Reifenspezifikation',              'label_en' => 'Tyre specification',         'input' => 'text',    'source' => 'column', 'column' => 'spec'],
        ['key' => 'geschwindigkeitsindex',        'label_de' => 'Geschwindigkeitsindex',            'label_en' => 'Speed index',                'input' => 'text',    'source' => 'column', 'column' => 'speed_rating'],
        ['key' => 'tragfaehigkeitsindex',         'label_de' => 'Tragfähigkeitsindex',              'label_en' => 'Load index',                 'input' => 'text',    'source' => 'column', 'column' => 'load_index'],
        ['key' => 'ean_gtin',                     'label_de' => 'EAN/GTIN',                         'label_en' => 'EAN/GTIN',                   'input' => 'text',    'source' => 'column', 'column' => 'ean'],
        ['key' => 'mpn',                          'label_de' => 'MPN/Herstellernummer',             'label_en' => 'MPN',                        'input' => 'text',    'source' => 'json'],
        ['key' => 'hersteller',                   'label_de' => 'Hersteller',                       'label_en' => 'Manufacturer',               'input' => 'text',    'source' => 'column', 'column' => 'brand'],
        ['key' => 'eprel_registrierungsnummer',   'label_de' => 'EPREL-Registrierungsnummer',       'label_en' => 'EPREL registration number',  'input' => 'text',    'source' => 'json'],
    ];

    /** The keys stored in `products.specs` — what the admin payload may contain. */
    public static function jsonKeys(): array
    {
        return array_column(
            array_filter(self::SHEET, fn ($s) => $s['source'] === 'json'),
            'key'
        );
    }

    /** Validation rules for the `specs` payload object, keyed `specs.<key>`. */
    public static function validationRules(): array
    {
        $rules = ['specs' => ['sometimes', 'nullable', 'array']];

        foreach (self::SHEET as $spec) {
            if ($spec['source'] !== 'json') {
                continue;
            }

            $rules["specs.{$spec['key']}"] = match ($spec['input']) {
                'select'  => ['sometimes', 'nullable', 'string', 'in:' . implode(',', $spec['options'])],
                'boolean' => ['sometimes', 'nullable', 'boolean'],
                default   => ['sometimes', 'nullable', 'string', 'max:100'],
            };
        }

        return $rules;
    }

    /**
     * Keep only known keys and drop blanks, so the JSON column never
     * accumulates junk a removed form field once wrote.
     */
    public static function cleanForStorage(?array $specs): ?array
    {
        if (! $specs) {
            return null;
        }

        $known = array_flip(self::jsonKeys());

        $clean = array_filter(
            array_intersect_key($specs, $known),
            fn ($v) => $v !== null && $v !== ''
        );

        return $clean ?: null;
    }

    /**
     * The rendered sheet for one product: label + value per spec, empties
     * skipped. This is what the public product page prints, assembled here so
     * the page cannot drift from the catalogue.
     *
     * `reifenzustand` is derived from `type` rather than stored — a second
     * condition field could disagree with the one the whole catalogue already
     * filters on, and "Used" is already the answer.
     *
     * `$brandDefaults` (Session 93) fills json-backed specs the product left
     * empty — entered once per brand instead of 15,000 times per catalogue.
     * Only json rows fall back: column rows are per-tyre physical facts
     * (width, EAN, load index) that a brand cannot meaningfully default.
     *
     * @param  array<string, mixed>|null  $brandDefaults  the brand's `specs`
     * @return array<int, array{key: string, label_de: string, label_en: string, value: string}>
     */
    public static function sheetFor(Product $product, ?array $brandDefaults = null): array
    {
        $stored = $product->specs ?? [];
        $brand  = $brandDefaults ?? [];
        $rows   = [];

        foreach (self::SHEET as $spec) {
            $value = match ($spec['source']) {
                'derived' => $product->type === 'Used' ? 'Gebraucht' : 'Neu',
                'column'  => $product->{$spec['column']},
                'json'    => $stored[$spec['key']] ?? $brand[$spec['key']] ?? null,
            };

            if ($spec['input'] === 'boolean' && $value !== null && $value !== '') {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'Ja' : 'Nein';
            }

            if ($value === null || $value === '') {
                continue;
            }

            $rows[] = [
                'key'      => $spec['key'],
                'label_de' => $spec['label_de'],
                'label_en' => $spec['label_en'],
                'value'    => (string) $value,
            ];
        }

        return $rows;
    }
}
