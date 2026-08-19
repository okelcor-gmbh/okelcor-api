<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Product optimization (Session 92) — the marketing team's brief.
 *
 * Adds to `products`:
 *  - `slug`             — SEO URL handle, brand+name+season. Unique. Backfilled
 *                         below for every existing product, so the frontend can
 *                         link by slug from day one.
 *  - `description_html` — rich-text description, sanitized like article bodies.
 *                         The plain `description` column is untouched and keeps
 *                         serving as the fallback and the meta-description.
 *  - `specs`            — JSON, ONLY the attributes that have no column of
 *                         their own (EU-label classes, 3PMSF, EPREL …).
 *                         Width/rim/load-index etc. already exist as columns
 *                         and stay there; see App\Support\TyreSpecs for the
 *                         one list that says where each attribute lives.
 *  - `shipping_info` / `returns_info` — per-product overrides. The site-wide
 *                         defaults live in `site_settings` (seeded below), so
 *                         the marketer edits one text for the whole catalogue
 *                         and overrides per product only where it differs.
 *
 * Everything guarded and additive; no existing column is read, renamed or
 * rewritten. The backfill only fills NULL slugs, so re-running cannot rename
 * a live URL. Deploy-order safe: code reading these columns checks for them
 * via the model's normal null-handling, and the public API keeps resolving
 * numeric ids regardless.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'slug')) {
                $table->string('slug', 255)->nullable()->unique()->after('sku');
            }
            if (! Schema::hasColumn('products', 'description_html')) {
                // LONGTEXT for parity with article bodies — a rich description
                // with inline images is bigger than TEXT's 64KB.
                $table->longText('description_html')->nullable()->after('description');
            }
            if (! Schema::hasColumn('products', 'specs')) {
                $table->json('specs')->nullable()->after('description_html');
            }
            if (! Schema::hasColumn('products', 'shipping_info')) {
                $table->text('shipping_info')->nullable()->after('specs');
            }
            if (! Schema::hasColumn('products', 'returns_info')) {
                $table->text('returns_info')->nullable()->after('shipping_info');
            }
        });

        $this->backfillSlugs();

        // Site-wide shipping default, from the brief. Returns is seeded EMPTY
        // on purpose: the brief's returns text is copied from an eBay listing
        // (eBay Plus, eBay return labels) and would be wrong on okelcor.com —
        // the marketer words the site version in Admin → Settings.
        DB::table('site_settings')->insertOrIgnore([
            [
                'key'   => 'product_shipping_info',
                'value' => "Versand: Kostenlos – Deutsche Post Brief.\nStandort: Munich, Deutschland",
                'type'  => 'string',
                'group' => 'shop',
            ],
            [
                'key'   => 'product_returns_info',
                'value' => '',
                'type'  => 'string',
                'group' => 'shop',
            ],
        ]);
    }

    /**
     * One slug per existing product: brand + name + season, deduplicated with
     * a numeric suffix, ids ascending so re-runs on a partly-filled table give
     * the same answers. Soft-deleted rows included — a restored product must
     * not collide with the slug a later product took meanwhile.
     */
    private function backfillSlugs(): void
    {
        $taken = DB::table('products')->whereNotNull('slug')->pluck('slug')
            ->flip()->map(fn () => true)->all();

        DB::table('products')->whereNull('slug')->orderBy('id')
            ->select(['id', 'brand', 'name', 'season'])
            ->chunkById(200, function ($products) use (&$taken) {
                foreach ($products as $p) {
                    $base = Str::slug(trim("{$p->brand} {$p->name} {$p->season}")) ?: "product-{$p->id}";
                    $slug = $base;

                    for ($i = 2; isset($taken[$slug]); $i++) {
                        $slug = "{$base}-{$i}";
                    }

                    $taken[$slug] = true;

                    DB::table('products')->where('id', $p->id)->update(['slug' => $slug]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            foreach (['slug', 'description_html', 'specs', 'shipping_info', 'returns_info'] as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        DB::table('site_settings')->whereIn('key', ['product_shipping_info', 'product_returns_info'])->delete();
    }
};
