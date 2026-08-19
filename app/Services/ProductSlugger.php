<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Str;

/**
 * Product URL slugs — brand + name + season, per the marketing brief.
 *
 * Two rules, both about URLs being promises:
 *
 * 1. **A slug is generated once and then left alone.** Renaming a product does
 *    NOT regenerate its slug: the old URL is in Google's index, in campaign
 *    e-mails and in customers' bookmarks, and silently moving it turns all of
 *    those into 404s. The marketer can change a slug deliberately through the
 *    explicit field; nothing changes it as a side effect.
 *
 * 2. **Uniqueness is settled here, not by the database exception.** Two
 *    products legitimately collide (same brand, model and season in two
 *    sizes), so collisions get a numeric suffix rather than an error the
 *    admin panel would have to translate.
 */
class ProductSlugger
{
    /** A unique slug from the product's own naming fields. */
    public function generate(string $brand, string $name, string $season, ?int $ignoreId = null): string
    {
        $base = Str::slug(trim("{$brand} {$name} {$season}"));

        if ($base === '') {
            $base = 'product';
        }

        return $this->unique($base, $ignoreId);
    }

    /** Normalize a hand-typed slug and make it unique. */
    public function fromInput(string $input, ?int $ignoreId = null): string
    {
        $base = Str::slug($input);

        if ($base === '') {
            $base = 'product';
        }

        return $this->unique($base, $ignoreId);
    }

    private function unique(string $base, ?int $ignoreId): string
    {
        // withTrashed: a soft-deleted product still owns its slug — restoring
        // it must not find the URL given away in the meantime.
        $taken = Product::withTrashed()
            ->where('slug', 'like', $base . '%')
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->pluck('slug')
            ->flip();

        if (! isset($taken[$base])) {
            return $base;
        }

        for ($i = 2; ; $i++) {
            if (! isset($taken["{$base}-{$i}"])) {
                return "{$base}-{$i}";
            }
        }
    }
}
