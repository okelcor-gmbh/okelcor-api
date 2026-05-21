<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('article_translations', function (Blueprint $table) {
            // Track whether the body column holds a legacy JSON array or rich HTML
            $table->enum('body_format', ['json_array', 'html'])->default('json_array')->after('body');

            // SEO fields per locale
            $table->string('meta_title', 160)->nullable()->after('body_format');
            $table->string('meta_description', 300)->nullable()->after('meta_title');

            // Alt text for the article cover image (locale-specific)
            $table->string('cover_alt', 200)->nullable()->after('meta_description');
        });

        Schema::table('articles', function (Blueprint $table) {
            // Optional OG/social share image distinct from cover image
            $table->string('og_image', 500)->nullable()->after('image');
        });
    }

    public function down(): void
    {
        Schema::table('article_translations', function (Blueprint $table) {
            $table->dropColumn(['body_format', 'meta_title', 'meta_description', 'cover_alt']);
        });

        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn('og_image');
        });
    }
};
