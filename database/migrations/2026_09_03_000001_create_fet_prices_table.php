<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What an FET unit costs us, and what it sells for (Session 112).
 *
 * Finance supplied a supplier price list — PRO FI 250, FII 450, FIII 750,
 * FIV 1,450 (EUR). Until now no FET price existed anywhere in the system: the
 * /fet page is a lookup that tells somebody which model fits their engine and
 * has never shown a figure.
 *
 * **Price belongs to the TIER, not to the engine row.** `fet_engines` holds 26
 * rows across seven `fet_model` strings, because the SAE size is baked into
 * the string ("FET-PRO-FI (SAE 5/16\")" and "FET-PRO-FI (SAE 5/16\" or SAE
 * 1/2\")" are the same tier). Putting a price on each engine would store the
 * same four numbers 26 times and make a price change a 26-row edit.
 *
 * **`cost_price` is what we pay the supplier and must never reach the public
 * endpoint.** `GET /api/v1/fet/engines` is unauthenticated, and it returns
 * Eloquent models wholesale — which is exactly why this is a separate table
 * rather than columns on `fet_engines`, where the existing controller would
 * have published our supplier costs to the open internet on the next deploy.
 *
 * `price` (retail) is deliberately seeded NULL. Finance confirmed the image is
 * cost, not retail, and has not yet given the margin — so the page shows no
 * price at all until somebody sets one. Showing cost would be worse than
 * showing nothing.
 */
return new class extends Migration
{
    /** Cost in EUR from the supplier list dated 2026-09-03. Retail is not ours to guess. */
    private const SEED = [
        ['tier' => 'PRO_FI',   'label' => 'FET PRO FI',   'cost_price' => 250.00],
        ['tier' => 'PRO_FII',  'label' => 'FET PRO FII',  'cost_price' => 450.00],
        ['tier' => 'PRO_FIII', 'label' => 'FET PRO FIII', 'cost_price' => 750.00],
        ['tier' => 'PRO_FIV',  'label' => 'FET PRO FIV',  'cost_price' => 1450.00],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('fet_prices')) {
            Schema::create('fet_prices', function (Blueprint $table) {
                $table->id();
                $table->string('tier', 12)->unique();
                $table->string('label', 40);
                // What we pay. Admin-only, never served publicly.
                $table->decimal('cost_price', 12, 2)->nullable();
                // What the customer pays. Null until finance sets it.
                $table->decimal('price', 12, 2)->nullable();
                $table->string('currency', 3)->default('EUR');
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }

        // insertOrIgnore, so re-running never overwrites a price finance has
        // since corrected in the panel. The unique key on `tier` is what makes
        // that safe.
        foreach (self::SEED as $row) {
            DB::table('fet_prices')->insertOrIgnore($row + [
                'currency'   => 'EUR',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fet_prices');
    }
};
