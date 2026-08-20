<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Product search, in one place (Session 95).
 *
 * Reported by the marketing manager: searching "SUV" found nothing. He was
 * right in a bigger way than the report says — the shop, the navbar and the
 * admin panel each carried their own copy of the search, all three matched
 * only brand/name/size/sku, and none of them knew about anything the last
 * three sessions built. "SUV" lives in the description, in the product's
 * Fahrzeugtyp spec (Session 92), or in the brand's default spec (Session 93);
 * none of those columns were searched anywhere.
 *
 * Two changes, applied to all three copies by replacing them with this:
 *
 * 1. **Every word must match somewhere, each word may match anywhere.**
 *    The term is split into words; a product matches when each word hits at
 *    least one field. "continental suv 205" narrows the way a person expects,
 *    instead of being one literal string nothing contains.
 *
 * 2. **The searched fields are the fields a person can see.** Brand, name,
 *    size, spec, SKU, EAN, slug, season, type, the description, the product's
 *    own spec sheet — and, because Session 93 resolves specs through the
 *    brand, the brand's default specs too. A Continental whose Fahrzeugtyp
 *    comes from the brand default *displays* SUV on its page, so a search for
 *    SUV must find it; matching only what is stored on the product row would
 *    make search disagree with the page.
 *
 * Brand descriptions are deliberately NOT searched: every word of a brand
 * story would match every product of the brand, which is noise, not recall.
 */
class ProductSearch
{
    /** Direct product columns every token is tried against. */
    private const COLUMNS = [
        'brand', 'name', 'size', 'spec', 'sku', 'ean',
        'season', 'type', 'description',
    ];

    /** More words than this is not a search, it is a pasted paragraph. */
    private const MAX_TOKENS = 6;

    /**
     * Narrow $query to products matching the term. No-op on a blank term.
     */
    public static function apply(Builder $query, ?string $term): Builder
    {
        $tokens = collect(preg_split('/\s+/', trim((string) $term)) ?: [])
            ->filter(fn ($t) => $t !== '')
            ->take(self::MAX_TOKENS);

        if ($tokens->isEmpty()) {
            return $query;
        }

        foreach ($tokens as $token) {
            // Escape LIKE wildcards so a literal "100%" searches for "100%".
            $like = '%' . addcslashes($token, '%_\\') . '%';

            $query->where(function ($q) use ($like) {
                foreach (self::COLUMNS as $column) {
                    $q->orWhere($column, 'like', $like);
                }

                // Session 92 additions — guarded so a search during the window
                // between code deploy and migration #42 degrades to the old
                // field list instead of erroring on a missing column.
                if (self::hasColumn('products', 'slug')) {
                    $q->orWhere('slug', 'like', $like);
                }

                if (self::hasColumn('products', 'specs')) {
                    // LIKE over the raw JSON text. Crude and honest: values
                    // match, German key names match too (searching
                    // "Fahrzeugtyp" finding products that have one is not
                    // wrong), and it needs no per-key query fan-out.
                    $q->orWhere('specs', 'like', $like);
                }

                // Session 93 — a spec the product inherits from its brand is
                // shown on its page, so it must be findable. Same
                // case-insensitive name match the resolution itself uses.
                if (self::hasColumn('brands', 'specs')) {
                    $q->orWhereExists(function ($sub) use ($like) {
                        $sub->select(DB::raw(1))
                            ->from('brands')
                            ->whereRaw('LOWER(brands.name) = LOWER(products.brand)')
                            ->where('brands.is_active', true)
                            ->where('brands.specs', 'like', $like);
                    });
                }
            });
        }

        return $query;
    }

    // Cached per request — search may run per keystroke, and the answer
    // cannot change mid-request outside a deploy.
    private static array $columnCache = [];

    private static function hasColumn(string $table, string $column): bool
    {
        return self::$columnCache["{$table}.{$column}"]
            ??= Schema::hasColumn($table, $column);
    }

    /** @internal test harnesses rebuild the schema between tests. */
    public static function flushColumnCache(): void
    {
        self::$columnCache = [];
    }
}
