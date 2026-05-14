<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ebay_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('marketplace_id', 20)->default('EBAY_DE');
            $table->string('seller_username')->nullable();
            $table->text('access_token')->nullable();         // encrypted at rest via model cast
            $table->text('refresh_token');                   // encrypted at rest via model cast
            $table->timestamp('access_token_expires_at')->nullable();
            $table->timestamp('refresh_token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_refreshed_at')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ebay_tokens');
    }
};
