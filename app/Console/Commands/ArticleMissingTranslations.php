<?php

namespace App\Console\Commands;

use App\Models\Article;
use Illuminate\Console\Command;

/**
 * Report articles that are missing translations for one or more locales.
 *
 * Usage:
 *   php artisan articles:missing-translations
 *   php artisan articles:missing-translations --locale=fr          (filter to one locale)
 *   php artisan articles:missing-translations --published-only
 *
 * This command is read-only — it NEVER writes or auto-translates content.
 * Missing translations must be filled by a human editor via the admin UI.
 */
class ArticleMissingTranslations extends Command
{
    protected $signature = 'articles:missing-translations
                            {--locale=        : Filter to a specific locale (en, de, fr, es)}
                            {--published-only : Only check published articles}';

    protected $description = 'Report articles that are missing translations for one or more locales';

    private const LOCALES = ['en', 'de', 'fr', 'es'];

    public function handle(): int
    {
        $filterLocale   = $this->option('locale');
        $publishedOnly  = $this->option('published-only');

        if ($filterLocale && ! in_array($filterLocale, self::LOCALES)) {
            $this->error("Invalid locale \"{$filterLocale}\". Accepted: " . implode(', ', self::LOCALES));
            return self::FAILURE;
        }

        $localesToCheck = $filterLocale ? [$filterLocale] : self::LOCALES;

        $query = Article::with('translations');
        if ($publishedOnly) {
            $query->where('is_published', true);
        }
        $articles = $query->orderByDesc('created_at')->get();

        $rows    = [];
        $missing = 0;

        foreach ($articles as $article) {
            $presentLocales = $article->translations->pluck('locale')->all();
            $gaps           = array_diff($localesToCheck, $presentLocales);

            if (empty($gaps)) {
                continue;
            }

            $enTranslation = $article->translations->firstWhere('locale', 'en');
            $title         = $enTranslation?->title ?? '(no EN title)';

            $rows[] = [
                $article->id,
                $article->slug,
                substr($title, 0, 55) . (strlen($title) > 55 ? '…' : ''),
                $article->is_published ? 'yes' : 'draft',
                implode(', ', array_values($gaps)),
            ];

            ++$missing;
        }

        if (empty($rows)) {
            $localeLabel = $filterLocale ? "[{$filterLocale}]" : 'all locales';
            $this->info("All articles have translations for {$localeLabel}.");
            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn("{$missing} article(s) have missing translations:");
        $this->newLine();

        $this->table(
            ['ID', 'Slug', 'EN Title', 'Published', 'Missing Locales'],
            $rows
        );

        $this->newLine();
        $this->comment('To add translations: open the article in the admin editor and fill the missing locale tabs.');
        $this->comment('Do NOT auto-translate — all translations require human approval.');

        return self::SUCCESS;
    }
}
