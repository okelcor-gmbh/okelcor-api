<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dual sign-off on an order confirmation: one from operations, one from finance.
 *
 * A table rather than four columns on `orders`, for three reasons that all bite
 * later otherwise: a sign-off can be withdrawn and the withdrawal is itself
 * evidence, so the row has to survive being undone; a third slot (a director on
 * large orders, say) is then a value rather than a schema change; and the
 * question actually asked in an audit — "who approved this, when, and had
 * anyone withdrawn first" — is a query rather than an archaeology of columns.
 *
 * `active` is the live-row marker and is either 1 or NULL, never 0. MySQL
 * treats NULLs in a unique index as distinct, so unique(order_id, slot, active)
 * permits any number of withdrawn rows and exactly one standing signature per
 * slot — enforced by the database rather than by remembering to check.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_signoffs')) {
            return;
        }

        Schema::create('order_signoffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            // Denormalised so the trail stays readable if an order is ever
            // renumbered, and so a support question can be answered without a
            // join.
            $table->string('order_ref', 30)->index();

            // 'ops' | 'finance'. A string rather than an ENUM — see the
            // admin_users.role migration alongside this one for why this
            // codebase has stopped reaching for ENUMs.
            $table->string('slot', 20);

            $table->foreignId('admin_user_id')->constrained('admin_users')->cascadeOnDelete();
            $table->string('admin_role', 30);
            $table->string('admin_name', 150);
            $table->timestamp('signed_at');
            $table->string('note', 500)->nullable();

            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('revoke_reason', 500)->nullable();

            // 1 while standing, NULL once withdrawn. Never 0 — see the class
            // docblock; the NULL is what makes the unique index work.
            $table->unsignedTinyInteger('active')->nullable()->default(1);

            $table->timestamps();

            $table->unique(['order_id', 'slot', 'active'], 'order_signoffs_one_live_per_slot');
            $table->index(['order_id', 'slot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_signoffs');
    }
};
