<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A relabel field, not a conversion — order.total (and all line items) are
 * already stored in whatever currency the customer actually paid in; this
 * just lets an order manager correct the label when it was recorded wrong
 * (e.g. a USD payment that got entered as if it were EUR). No exchange-rate
 * math anywhere. Defaults every existing row to EUR, matching how the
 * frontend has always rendered every order until now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('currency', 3)->default('EUR')->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
