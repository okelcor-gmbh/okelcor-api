<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who a product is listed for (Session 116).
 *
 * The catalogue serves two audiences from one table, and until now the only
 * separation was implicit: a product "was B2B" if price_b2b was set. That
 * gives pricing control but no merchandising control — there was no way to
 * list a container-only lot that private buyers should never see, or a
 * retail-only promotion the trade should not, except by leaving prices
 * blank and hoping.
 *
 * `audience` makes the listing intent explicit: 'both' (default, matches
 * every existing row's current behaviour), 'b2b' (trade only), 'b2c'
 * (retail only). A plain string, NOT an enum — this project has paid for
 * MySQL enum drift three times.
 *
 * Additive, guarded, default preserves behaviour for all 15,265 rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products') || Schema::hasColumn('products', 'audience')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->string('audience', 8)->default('both')->after('type');
            $table->index('audience');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'audience')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['audience']);
            $table->dropColumn('audience');
        });
    }
};
