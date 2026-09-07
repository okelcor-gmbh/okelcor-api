<?php

namespace App\Services;

use App\Models\Product;

/**
 * The one place the tier pricing formula lives — agreed with the order
 * manager and the team (2026-09-07):
 *
 *   cost     = the Tyre100 supplier price (products.cost_price)
 *   base     = cost × (1 + tier margin)      premium 15% / midrange 20% / budget 30%
 *   website  = base × (1 + Stripe fee 3%)    what okelcor.com charges
 *   eBay     = base × (1 + eBay uplift 9.5%) what the eBay offer is pushed at
 *
 * The Stripe fee is baked into the website price because the payment
 * method is unknown at listing time; the eBay price drops it (eBay
 * payments do not run through Stripe) and carries the eBay charges
 * instead. Percentages live in config/services.php ('pricing').
 */
class TierPricingService
{
    public const TIERS = ['premium', 'midrange', 'budget'];

    /** Margin % for a tier, or null for an unknown/unassigned tier. */
    public function marginPercentFor(?string $tier): ?float
    {
        return match ($tier) {
            'premium'  => (float) config('services.pricing.premium_margin_percent', 15.0),
            'midrange' => (float) config('services.pricing.midrange_margin_percent', 20.0),
            'budget'   => (float) config('services.pricing.budget_margin_percent', 30.0),
            default    => null,
        };
    }

    /** cost × (1 + margin) — the shared base both channel prices build on. */
    public function basePrice(float $cost, string $tier): ?float
    {
        $margin = $this->marginPercentFor($tier);

        return $margin === null ? null : round($cost * (1 + $margin / 100), 2);
    }

    public function websitePrice(float $cost, string $tier): ?float
    {
        $base = $this->basePrice($cost, $tier);
        if ($base === null) {
            return null;
        }

        $stripe = (float) config('services.pricing.stripe_fee_percent', 3.0);

        return round($base * (1 + $stripe / 100), 2);
    }

    public function ebayPrice(float $cost, string $tier): ?float
    {
        $base = $this->basePrice($cost, $tier);
        if ($base === null) {
            return null;
        }

        $uplift = (float) config('services.pricing.ebay_uplift_percent', 9.5);

        return round($base * (1 + $uplift / 100), 2);
    }

    /**
     * The eBay offer price for a product: the formula when the product can
     * be priced (cost + tier present), else its plain website price — so a
     * product outside the tier system still lists at what the site charges.
     */
    public function ebayOfferPriceFor(Product $product): float
    {
        if ($product->cost_price !== null && $product->price_tier !== null) {
            $price = $this->ebayPrice((float) $product->cost_price, $product->price_tier);
            if ($price !== null && $price > 0) {
                return $price;
            }
        }

        return (float) $product->price;
    }

    /** The whole model, for API meta — the panel shows the formula it applies. */
    public function model(): array
    {
        return [
            'margins' => [
                'premium'  => (float) config('services.pricing.premium_margin_percent', 15.0),
                'midrange' => (float) config('services.pricing.midrange_margin_percent', 20.0),
                'budget'   => (float) config('services.pricing.budget_margin_percent', 30.0),
            ],
            'stripe_fee_percent'  => (float) config('services.pricing.stripe_fee_percent', 3.0),
            'ebay_uplift_percent' => (float) config('services.pricing.ebay_uplift_percent', 9.5),
        ];
    }
}
