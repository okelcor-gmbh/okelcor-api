<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shared team to-do list (Session 102).
 *
 * One list the whole panel can see and anyone can write to — the ask was
 * "anyone can use it to tag a team member". Tagging someone notifies them
 * and lands the item in their My Work, the same chase pattern as the finance
 * snapshot and the EC invoice lines. Editing is for the people the item
 * concerns: its creator and its assignee.
 *
 * One NEW table; nothing existing is read, altered or backfilled.
 * Deploy-order safe: readers go through Todo::available().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('todos')) {
            return;
        }

        Schema::create('todos', function (Blueprint $table) {
            $table->id();

            $table->string('title', 200);
            $table->text('details')->nullable();

            $table->date('due_on')->nullable();

            // low | normal | high (Todo::PRIORITIES).
            $table->string('priority', 10)->default('normal');

            // open | in_progress | done (Todo::STATUSES).
            $table->string('status', 20)->default('open');

            // The tag. Nullable — an untagged item is the team's, not
            // nobody's.
            $table->foreignId('assigned_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();

            // Who closed it and when — "done" with no name is a fact nobody
            // can ask about.
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('admin_users')->nullOnDelete();

            $table->timestamps();

            $table->index(['assigned_admin_id', 'status']);
            $table->index(['status', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('todos');
    }
};
