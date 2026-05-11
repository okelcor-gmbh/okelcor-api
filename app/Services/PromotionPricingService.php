<?php

namespace App\Services;

use App\Models\Promotion;
use Illuminate\Support\Facades\DB;

class PromotionPricingService
{
    /**
     * Recalculate price = cost_price * (1 - discount_pct/100) for every
     * product whose brand matches the promotion's brand_name.
     *
     * Only touches products that have cost_price set (the supplier reference
     * price). Products without cost_price are skipped to avoid data loss.
     *
     * Returns the number of product rows updated.
     */
    public function recalculateForPromotion(Promotion $promotion): int
    {
        if (! $promotion->brand_name || $promotion->discount_pct === null) {
            return 0;
        }

        $factor = round(1 - ((float) $promotion->discount_pct / 100), 10);

        return DB::table('products')
            ->where('brand', $promotion->brand_name)
            ->whereNull('deleted_at')
            ->whereNotNull('cost_price')
            ->update([
                'price'      => DB::raw("ROUND(cost_price * {$factor}, 2)"),
                'price_b2b'  => DB::raw("ROUND(cost_price * {$factor}, 2)"),
                'price_b2c'  => DB::raw("ROUND(cost_price * {$factor}, 2)"),
                'updated_at' => now(),
            ]);
    }
}
