<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer-saved tyre sizes ("My Garage" style) — lets a repeat B2B buyer
 * save a size/brand profile once and reuse it, instead of re-typing search
 * filters every visit. Plain CRUD, no pricing logic (that lives in the
 * reorder endpoint instead).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_fitments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_id')
                ->constrained('customers')
                ->cascadeOnDelete();

            $table->string('size', 50);
            $table->string('brand', 100)->nullable();
            $table->string('label', 100)->nullable();

            $table->timestamps();

            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_fitments');
    }
};
