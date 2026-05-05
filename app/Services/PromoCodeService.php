<?php

namespace App\Services;

use App\Models\Promotion;

class PromoCodeService
{
    /**
     * Resolve a submitted promo code string against active promotions.
     * Matches on the `code` column (case-insensitive).
     * Returns null if not found, expired, or missing required campaign fields.
     */
    public function resolve(string $code): ?Promotion
    {
        $upper = strtoupper(trim($code));
        $today = now()->toDateString();

        return Promotion::where('is_active', true)
            ->whereNotNull('discount_pct')
            ->whereNotNull('brand_name')
            ->whereRaw('UPPER(TRIM(code)) = ?', [$upper])
            ->where(function ($q) use ($today) {
                $q->whereNull('start_date')->orWhere('start_date', '<=', $today);
            })
            ->where(function ($q) use ($today) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $today);
            })
            ->first();
    }

    /**
     * Sum the net total of items whose brand matches the promotion's brand_name.
     * Comparison is case-insensitive.
     * Returns 0.0 when no items match.
     *
     * @param  array<array{brand: string, unit_price: float, quantity: int}>  $lineItems
     */
    public function matchingNet(Promotion $promo, array $lineItems): float
    {
        $promoB2b = strtoupper(trim((string) $promo->brand_name));
        $total    = 0.0;

        foreach ($lineItems as $item) {
            if (strtoupper(trim((string) ($item['brand'] ?? ''))) === $promoB2b) {
                $total += (float) $item['unit_price'] * (int) $item['quantity'];
            }
        }

        return $total;
    }

    /**
     * Calculate discount amount for qualifying line items.
     */
    public function calculateDiscount(Promotion $promo, array $lineItems): float
    {
        $matchingNet = $this->matchingNet($promo, $lineItems);

        if ($matchingNet <= 0.0) {
            return 0.0;
        }

        return round($matchingNet * (float) $promo->discount_pct / 100, 2);
    }

    /**
     * Human-readable label stored on order/invoice.
     * e.g. "Rapid Tyres Campaign — 5% off"
     */
    public function label(Promotion $promo): string
    {
        return $promo->title . ' — ' . number_format((float) $promo->discount_pct, 0) . '% off';
    }
}
