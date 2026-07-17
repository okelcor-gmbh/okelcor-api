<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI-generated admin dashboard insights (AdminInsightsService). One row per
 * insight; every row inserted in the same scheduled cycle shares the same
 * `generated_at` timestamp, which is what "the latest batch" means for
 * GET /admin/insights. Kept in a real table (not just a cache key) so a
 * failed generation cycle simply doesn't insert new rows — the previous
 * batch keeps being served automatically, no separate "last known good"
 * cache value to manage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_insights', function (Blueprint $table) {
            $table->id();

            $table->string('external_id', 40)->unique();
            $table->string('category', 20);
            $table->string('severity', 20);
            $table->string('headline', 300);
            $table->text('detail');
            $table->string('action_url', 500)->nullable();
            $table->unsignedTinyInteger('rank')->default(0);
            $table->timestamp('generated_at');

            $table->timestamps();

            $table->index('generated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_insights');
    }
};
