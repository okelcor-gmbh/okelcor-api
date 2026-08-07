<?php

namespace App\Services;

use App\Models\Product;

/**
 * Best-effort link from a partner's free-text size to a catalogue row.
 *
 * `partner_sales.product_id` is nullable forever and this is allowed to fail —
 * partners sell tyres Okelcor does not list, and a wrong link is worse than no
 * link because it would attribute a sale to the wrong SKU in every report. So
 * the matcher only ever links on an UNAMBIGUOUS match: exactly one catalogue
 * row for the parsed size (and brand, when given). Anything else leaves it null.
 *
 * The partner never sees or confirms this — the size and brand they typed are
 * stored verbatim regardless, and remain the source of truth for the books.
 */
class PartnerSaleProductMatcher
{
    /**
     * Parse "315/70 R22.5", "315 70 22.5", "315-70R22.5" into components.
     *
     * @return array{width: string, height: string, rim: string}|null
     */
    public static function parseSize(?string $size): ?array
    {
        if (! $size) {
            return null;
        }

        // Three numbers in order: width, aspect, rim. The rim may be decimal
        // (22.5 is a standard truck rim), the others are whole numbers.
        if (! preg_match('/(\d{2,3})\s*[\/\-\s]?\s*(\d{2,3})\s*[R\-\s]?\s*(\d{2}(?:\.\d)?)/i', $size, $m)) {
            return null;
        }

        return [
            'width'  => ltrim($m[1], '0') ?: $m[1],
            'height' => ltrim($m[2], '0') ?: $m[2],
            // Normalise "22.0" → "22" so it matches a catalogue stored either way.
            'rim'    => rtrim(rtrim($m[3], '0'), '.') ?: $m[3],
        ];
    }

    /**
     * @return int|null product id, or null when there is no single clear match
     */
    public static function match(?string $size, ?string $brand = null): ?int
    {
        $parts = self::parseSize($size);

        if ($parts === null) {
            return null;
        }

        $query = Product::query()
            ->where('width', $parts['width'])
            ->where('height', $parts['height'])
            ->where(function ($q) use ($parts) {
                // Catalogue rim may be stored as "22.5" or "22.50" or "22".
                $q->where('rim', $parts['rim'])
                    ->orWhere('rim', $parts['rim'] . '.0')
                    ->orWhere('rim', $parts['rim'] . '.00');
            });

        if ($brand !== null && trim($brand) !== '') {
            $query->where('brand', trim($brand));
        }

        // Two ids is enough to know it is ambiguous; no need to count 3,000.
        $ids = $query->limit(2)->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }
}
