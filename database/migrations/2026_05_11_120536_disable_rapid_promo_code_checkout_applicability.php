<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Rapid product prices are already final selling prices (imported directly
     * from the supplier Excel). The 30% campaign figure is promotional messaging
     * only, NOT a checkout multiplier.
     *
     * Codes RAPID5 and RAPID2 are currently resolvable by PromoCodeService and
     * would deduct 30% from Rapid items at checkout — a real double-discount.
     *
     * Fix: null the code column on both records. PromoCodeService::resolve()
     * queries UPPER(TRIM(code)) = ?, so a NULL code can never be matched by
     * any submitted string. discount_pct and brand_name remain intact for the
     * UI badge ("30% OFF") and Specials section trigger.
     *
     * The promo_code field in the API response will return null, which the
     * frontend should treat as "no code to display or apply at checkout".
     */
    public function up(): void
    {
        DB::table('promotions')
            ->where('brand_name', 'Rapid')
            ->where('is_active', true)
            ->whereIn('code', ['RAPID5', 'RAPID2'])
            ->update(['code' => null]);
    }

    public function down(): void
    {
        // Restore original codes (pre-nulling); use updateOrInsert to be safe
        DB::table('promotions')
            ->where('brand_name', 'Rapid')
            ->where('placement', 'shop_inline')
            ->update(['code' => 'RAPID5']);

        DB::table('promotions')
            ->where('brand_name', 'Rapid')
            ->where('placement', 'announcement_bar')
            ->update(['code' => 'RAPID2']);
    }
};
