<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The assignee's note back on a to-do (Session 108).
 *
 * A to-do tagged to someone is worked from My Work, and until now the only
 * thing that travelled back was the status. "Done" and "In progress" cannot
 * say "the client asked for Thursday" — so the person chased the assignee in
 * Outlook, which is the thing this list exists to stop.
 *
 * Deliberately NOT `details`: that column is whoever wrote the task saying
 * what they want. Letting the assignee edit it would overwrite the brief with
 * the reply, and the two are different things said by different people.
 *
 * Additive, nullable, guarded. Nothing existing is read, altered or
 * backfilled, and every reader falls back to null, so the code is
 * deploy-order safe in both directions.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('todos') || Schema::hasColumn('todos', 'assignee_note')) {
            return;
        }

        Schema::table('todos', function (Blueprint $table) {
            $table->text('assignee_note')->nullable()->after('details');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('todos') || ! Schema::hasColumn('todos', 'assignee_note')) {
            return;
        }

        Schema::table('todos', function (Blueprint $table) {
            $table->dropColumn('assignee_note');
        });
    }
};
