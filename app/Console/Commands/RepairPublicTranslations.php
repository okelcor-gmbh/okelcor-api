<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Models\HeroSlide;
use App\Models\HeroSlideTranslation;
use Illuminate\Console\Command;

/**
 * Fill missing hero-slide and category translations from approved seed data.
 *
 * Modes:
 *   --audit    Dump all active slides/categories with their current translation state.
 *              Use this on production to identify slides without seed data.
 *   --dry-run  Show what WOULD be filled without writing anything.
 *   (default)  Write missing translations.
 *
 * Usage:
 *   php artisan translations:repair-public-content --audit
 *   php artisan translations:repair-public-content --dry-run
 *   php artisan translations:repair-public-content
 */
class RepairPublicTranslations extends Command
{
    protected $signature = 'translations:repair-public-content
                            {--audit   : Dump all slides/categories with translation state (read-only)}
                            {--dry-run : Show what would be filled without writing to the database}';

    protected $description = 'Fill missing hero-slide and category translations from approved seed data';

    // -------------------------------------------------------------------------
    // Approved translation data
    // Keyed by the canonical EN title stored in hero_slide_translations.
    // Add new entries here when additional slides require approved translations.
    // -------------------------------------------------------------------------

    private array $heroTranslations = [
        // ── Seeded slides ─────────────────────────────────────────────────────
        'Your Global Tyre Partner' => [
            'de' => [
                'title'         => 'Ihr globaler Reifenpartner',
                'subtitle'      => 'PKW-, LKW-, OTR- und Gebrauchreifen in über 40 Länder geliefert. Wettbewerbsfähige Preise, zuverlässige Lieferung.',
                'cta_primary'   => 'Katalog ansehen',
                'cta_secondary' => 'Angebot anfordern',
            ],
            'fr' => [
                'title'         => 'Votre Partenaire Mondial en Pneumatiques',
                'subtitle'      => 'Pneus PCR, TBR, OTR et d\'occasion livrés dans plus de 40 pays. Prix compétitifs, livraison fiable.',
                'cta_primary'   => 'Voir le Catalogue',
                'cta_secondary' => 'Obtenir un Devis',
            ],
            'es' => [
                'title'         => 'Su Socio Global en Neumáticos',
                'subtitle'      => 'Neumáticos PCR, TBR, OTR y de ocasión suministrados a más de 40 países. Precios competitivos, entrega fiable.',
                'cta_primary'   => 'Ver Catálogo',
                'cta_secondary' => 'Solicitar Presupuesto',
            ],
        ],
        'Premium Brands, Wholesale Prices' => [
            'de' => [
                'title'         => 'Premium-Marken, Großhandelspreise',
                'subtitle'      => 'Michelin, Bridgestone, Continental und mehr – direkt bezogen, für B2B-Käufer kalkuliert.',
                'cta_primary'   => 'Produkte entdecken',
                'cta_secondary' => 'Kontakt aufnehmen',
            ],
            'fr' => [
                'title'         => 'Marques Premium, Prix Grossiste',
                'subtitle'      => 'Michelin, Bridgestone, Continental et plus encore — approvisionnement direct, tarifs B2B.',
                'cta_primary'   => 'Parcourir les Produits',
                'cta_secondary' => 'Nous Contacter',
            ],
            'es' => [
                'title'         => 'Marcas Premium, Precios de Mayorista',
                'subtitle'      => 'Michelin, Bridgestone, Continental y más — suministro directo, precios para compradores B2B.',
                'cta_primary'   => 'Ver Productos',
                'cta_secondary' => 'Contáctenos',
            ],
        ],
        'Fast Quotes, Reliable Supply' => [
            'de' => [
                'title'         => 'Schnelle Angebote, Verlässliche Versorgung',
                'subtitle'      => 'Senden Sie Ihren Reifenbedarf und erhalten Sie innerhalb eines Werktages ein wettbewerbsfähiges Angebot.',
                'cta_primary'   => 'Angebot anfordern',
                'cta_secondary' => 'Mehr erfahren',
            ],
            'fr' => [
                'title'         => 'Devis Rapides, Approvisionnement Fiable',
                'subtitle'      => 'Soumettez vos besoins en pneumatiques et recevez un devis compétitif dans un délai d\'un jour ouvrable.',
                'cta_primary'   => 'Demander un Devis',
                'cta_secondary' => 'En Savoir Plus',
            ],
            'es' => [
                'title'         => 'Presupuestos Rápidos, Suministro Fiable',
                'subtitle'      => 'Envíe sus necesidades de neumáticos y reciba un presupuesto competitivo en 1 día hábil.',
                'cta_primary'   => 'Solicitar Presupuesto',
                'cta_secondary' => 'Saber Más',
            ],
        ],

        // ── Admin-created slides — add entries below once EN content is confirmed ──
        // Run --audit on production to get the EN title/subtitle/cta values,
        // then add a new entry here following the same structure as above.
        //
        // Example (replace with actual content from --audit output):
        //
        // 'Slide Title Here' => [
        //     'de' => ['title' => '', 'subtitle' => '', 'cta_primary' => '', 'cta_secondary' => ''],
        //     'fr' => ['title' => '', 'subtitle' => '', 'cta_primary' => '', 'cta_secondary' => ''],
        //     'es' => ['title' => '', 'subtitle' => '', 'cta_primary' => '', 'cta_secondary' => ''],
        // ],
    ];

    private array $categoryTranslations = [
        'pcr' => [
            'en' => ['title' => 'PCR Tyres',            'label' => 'PCR',       'subtitle' => 'Passenger Car Radial tyres from leading global brands. Perfect for sedans, SUVs and hatchbacks.'],
            'de' => ['title' => 'PKW-Reifen',            'label' => 'PKW',       'subtitle' => 'Pkw-Radialreifen von führenden globalen Marken. Ideal für Limousinen, SUVs und Schrägheckfahrzeuge.'],
            'fr' => ['title' => 'Pneus PCR',             'label' => 'PCR',       'subtitle' => 'Pneus radiaux pour voitures particulières des meilleures marques mondiales. Idéaux pour berlines, SUV et citadines.'],
            'es' => ['title' => 'Neumáticos PCR',        'label' => 'PCR',       'subtitle' => 'Neumáticos radiales para turismos de las principales marcas mundiales. Perfectos para sedanes, SUV y utilitarios.'],
        ],
        'tbr' => [
            'en' => ['title' => 'TBR Tyres',            'label' => 'TBR',       'subtitle' => 'Truck and Bus Radial tyres engineered for long-haul transport, heavy loads and high mileage.'],
            'de' => ['title' => 'LKW-Reifen',            'label' => 'LKW',       'subtitle' => 'Radialreifen für Lkw und Busse, entwickelt für den Fernverkehr, schwere Lasten und hohe Laufleistungen.'],
            'fr' => ['title' => 'Pneus TBR',             'label' => 'TBR',       'subtitle' => 'Pneus radiaux pour camions et autobus conçus pour le transport longue distance, les charges lourdes et les grands kilométrages.'],
            'es' => ['title' => 'Neumáticos TBR',        'label' => 'TBR',       'subtitle' => 'Neumáticos radiales para camiones y autobuses diseñados para transporte de larga distancia, cargas pesadas y alto kilometraje.'],
        ],
        'used' => [
            'en' => ['title' => 'Used Tyres',           'label' => 'Used',      'subtitle' => 'Quality-inspected used tyres offering excellent value. Sourced from European markets with verified tread depth.'],
            'de' => ['title' => 'Gebrauchtreifen',       'label' => 'Gebraucht', 'subtitle' => 'Qualitätsgeprüfte Gebrauchreifen mit ausgezeichnetem Preis-Leistungs-Verhältnis. Aus europäischen Märkten mit verifizierten Profiltiefenwerten.'],
            'fr' => ['title' => 'Pneus Occasion',        'label' => 'Occasion',  'subtitle' => 'Pneus d\'occasion contrôlés offrant un excellent rapport qualité-prix. Provenant des marchés européens avec profondeur de sculpture vérifiée.'],
            'es' => ['title' => 'Neumáticos de Ocasión', 'label' => 'Ocasión',   'subtitle' => 'Neumáticos usados inspeccionados con calidad garantizada y excelente relación calidad-precio. Procedentes de mercados europeos con profundidad de dibujo verificada.'],
        ],
        'otr' => [
            'en' => ['title' => 'OTR Tyres',            'label' => 'OTR',       'subtitle' => 'Off-the-Road tyres for construction, mining and agricultural equipment. Built for extreme terrain and heavy duty use.'],
            'de' => ['title' => 'OTR-Reifen',            'label' => 'OTR',       'subtitle' => 'Geländereifen für Bau-, Bergbau- und Landwirtschaftsgeräte. Konzipiert für extremes Gelände und schwere Einsätze.'],
            'fr' => ['title' => 'Pneus OTR',             'label' => 'OTR',       'subtitle' => 'Pneus tout-terrain pour engins de construction, mines et agriculture. Conçus pour les terrains extrêmes et une utilisation intensive.'],
            'es' => ['title' => 'Neumáticos OTR',        'label' => 'OTR',       'subtitle' => 'Neumáticos todoterreno para maquinaria de construcción, minería y agricultura. Fabricados para terrenos extremos y uso de alta exigencia.'],
        ],
    ];

    // -------------------------------------------------------------------------

    public function handle(): int
    {
        if ($this->option('audit')) {
            return $this->runAudit();
        }

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY-RUN mode — no database writes will occur.');
            $this->newLine();
        }

        $heroResult = $this->repairHeroSlides($dryRun);
        $catResult  = $this->repairCategories($dryRun);

        $this->newLine();
        $this->info('Done.');
        $this->table(
            ['Type', 'Filled', 'Skipped (exists)', 'No seed data (needs --audit)'],
            [
                ['Hero slides', $heroResult['filled'], $heroResult['skipped'], $heroResult['unknown']],
                ['Categories',  $catResult['filled'],  $catResult['skipped'],  $catResult['unknown']],
            ]
        );

        if ($heroResult['unknown'] > 0) {
            $this->newLine();
            $this->warn("{$heroResult['unknown']} slide(s) have no approved seed data.");
            $this->comment('Run --audit to see their EN content, then add entries to the $heroTranslations map.');
        }

        if ($dryRun && ($heroResult['filled'] + $catResult['filled']) > 0) {
            $this->newLine();
            $this->comment('Re-run without --dry-run to apply these changes.');
        }

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------

    private function runAudit(): int
    {
        $this->info('=== HERO SLIDES AUDIT ===');
        $this->newLine();

        $slides = HeroSlide::with('translations')->orderBy('sort_order')->get();

        foreach ($slides as $slide) {
            $enRow    = $slide->translations->firstWhere('locale', 'en');
            $enTitle  = $enRow?->title    ?? $slide->title;
            $enSub    = $enRow?->subtitle ?? $slide->subtitle;
            $enPrimary   = $enRow?->cta_primary   ?? $slide->cta_primary_label;
            $enSecondary = $enRow?->cta_secondary  ?? $slide->cta_secondary_label;

            $presentLocales = $slide->translations->pluck('locale')->sort()->values()->implode(', ');
            $missingLocales = implode(', ', array_diff(['en', 'de', 'fr', 'es'], $slide->translations->pluck('locale')->all()));
            $inSeedMap      = isset($this->heroTranslations[$enTitle]) ? 'yes' : '⚠ NO — add to seed map';
            $activeFlag     = $slide->is_active ? 'active' : 'inactive';

            $this->line("┌─ Slide ID {$slide->id} | sort_order={$slide->sort_order} | {$activeFlag}");
            $this->line("│  EN title:    {$enTitle}");
            $this->line("│  EN subtitle: " . substr((string) $enSub, 0, 80));
            $this->line("│  EN CTAs:     [{$enPrimary}] / [{$enSecondary}]");
            $this->line("│  Locales:     present=[{$presentLocales}]  missing=[{$missingLocales}]");
            $this->line("│  In seed map: {$inSeedMap}");
            $this->line('└─');
            $this->newLine();
        }

        $this->info('=== CATEGORIES AUDIT ===');
        $this->newLine();

        $categories = Category::with('translations')->orderBy('sort_order')->get();

        $rows = [];
        foreach ($categories as $c) {
            $presentLocales = $c->translations->pluck('locale')->sort()->values()->implode(', ');
            $missingLocales = implode(', ', array_diff(['en', 'de', 'fr', 'es'], $c->translations->pluck('locale')->all()));
            $inSeedMap      = isset($this->categoryTranslations[$c->slug]) ? 'yes' : '⚠ NO';
            $rows[]         = [$c->id, $c->slug, $presentLocales ?: '—', $missingLocales ?: '✓ all', $inSeedMap];
        }

        $this->table(['ID', 'Slug', 'Present', 'Missing', 'In seed map'], $rows);

        $this->newLine();
        $this->comment('Slides/categories marked "⚠ NO" cannot be auto-filled. Add their translations to');
        $this->comment('$heroTranslations in RepairPublicTranslations.php, then re-run without --audit.');

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------

    private function repairHeroSlides(bool $dryRun): array
    {
        $filled = $skipped = $unknown = 0;

        $this->line('<info>Hero Slides</info>');

        $slides = HeroSlide::with('translations')->where('is_active', true)->orderBy('sort_order')->get();

        foreach ($slides as $slide) {
            $enTranslation = $slide->translations->firstWhere('locale', 'en');
            $enTitle       = $enTranslation?->title ?? $slide->title;

            $seedData = $this->heroTranslations[$enTitle] ?? null;

            if ($seedData === null) {
                $this->warn("  slide {$slide->id} \"{$enTitle}\" — no seed data (run --audit to inspect).");
                ++$unknown;
                continue;
            }

            $existingLocales = $slide->translations->pluck('locale')->all();

            foreach (['de', 'fr', 'es'] as $locale) {
                if (in_array($locale, $existingLocales)) {
                    $this->line("  slide {$slide->id} [{$locale}] — exists, skipping.");
                    ++$skipped;
                    continue;
                }

                if (! isset($seedData[$locale])) {
                    continue;
                }

                $this->line("  slide {$slide->id} [{$locale}] \"{$enTitle}\" — " . ($dryRun ? 'WOULD fill' : 'filling') . '.');

                if (! $dryRun) {
                    HeroSlideTranslation::create(array_merge(
                        ['slide_id' => $slide->id, 'locale' => $locale],
                        $seedData[$locale]
                    ));
                }

                ++$filled;
            }
        }

        return compact('filled', 'skipped', 'unknown');
    }

    private function repairCategories(bool $dryRun): array
    {
        $filled = $skipped = $unknown = 0;

        $this->newLine();
        $this->line('<info>Categories</info>');

        $categories = Category::with('translations')->orderBy('sort_order')->get();

        foreach ($categories as $category) {
            $seedData = $this->categoryTranslations[$category->slug] ?? null;

            if ($seedData === null) {
                $this->warn("  category \"{$category->slug}\" — no seed data, skipping.");
                ++$unknown;
                continue;
            }

            $existingLocales = $category->translations->pluck('locale')->all();

            foreach (['en', 'de', 'fr', 'es'] as $locale) {
                if (in_array($locale, $existingLocales)) {
                    $this->line("  category [{$category->slug}] [{$locale}] — exists, skipping.");
                    ++$skipped;
                    continue;
                }

                if (! isset($seedData[$locale])) {
                    continue;
                }

                $this->line("  category [{$category->slug}] [{$locale}] — " . ($dryRun ? 'WOULD fill' : 'filling') . '.');

                if (! $dryRun) {
                    CategoryTranslation::create(array_merge(
                        ['category_id' => $category->id, 'locale' => $locale],
                        $seedData[$locale]
                    ));
                }

                ++$filled;
            }
        }

        return compact('filled', 'skipped', 'unknown');
    }
}
