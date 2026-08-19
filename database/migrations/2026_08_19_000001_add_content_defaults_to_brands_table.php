<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brand-level content defaults (Session 93) — the marketer's follow-up to the
 * Session 92 brief: with ~15,000 products, filling the optimization content
 * product by product is not a workflow, it is a punishment. Most of that
 * content is the same for every tyre a brand makes, so it is entered once per
 * brand and every product without its own value inherits it.
 *
 * Same four content fields the products table gained in #42, deliberately —
 * the resolution chain is product → brand → site setting, and a chain only
 * works when every level speaks the same shape.
 *
 * Nothing is ever copied onto product rows. Resolution happens at read time,
 * so editing a brand takes effect on all its products instantly, a product's
 * own value always wins, and there is never a moment where 15,000 stale
 * copies disagree with the brand they came from.
 *
 * Guarded and additive; nothing existing is read, renamed or rewritten.
 * Deploy-order safe: the resolution code reads whole brand rows, so before
 * this runs the attributes are simply absent and resolve as null — exactly
 * the no-defaults behaviour that exists today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            if (! Schema::hasColumn('brands', 'description_html')) {
                $table->longText('description_html')->nullable()->after('logo');
            }
            if (! Schema::hasColumn('brands', 'specs')) {
                // Only json-backed sheet attributes belong here — a brand does
                // not have one width or one EAN. Enforced by the same
                // TyreSpecs::cleanForStorage the product path uses.
                $table->json('specs')->nullable()->after('description_html');
            }
            if (! Schema::hasColumn('brands', 'shipping_info')) {
                $table->text('shipping_info')->nullable()->after('specs');
            }
            if (! Schema::hasColumn('brands', 'returns_info')) {
                $table->text('returns_info')->nullable()->after('shipping_info');
            }
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            foreach (['description_html', 'specs', 'shipping_info', 'returns_info'] as $column) {
                if (Schema::hasColumn('brands', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
