<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two halves of the contribution record, deliberately kept apart.
 *
 * `staff_activities` is work this system watched happen — written from model
 * events, never by hand, never editable. `staff_contributions` is work only the
 * person knows about: a supplier call, a trade fair, a social media campaign.
 *
 * They are two tables rather than one table with a `source` column because the
 * promise made to the team is that verified work and self-reported work never
 * merge into a single figure. A column would make that a convention the next
 * feature can forget; two tables make it structural. Nothing joins them, and no
 * endpoint sums across them.
 *
 * Both are new. Nothing existing is read, altered or backfilled by this
 * migration, so it cannot affect a live row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('staff_activities')) {
            Schema::create('staff_activities', function (Blueprint $table) {
                $table->id();

                $table->foreignId('admin_user_id')->nullable()
                    ->constrained('admin_users')->nullOnDelete();

                // Copied onto the row rather than read back through the
                // relation, for the same reason OrderSignoff copies them: a
                // record of what someone did is a statement about who they were
                // at the time. Reading it live would rewrite last year's report
                // the day somebody changes role or leaves.
                $table->string('admin_name', 120)->nullable();
                $table->string('admin_role', 30)->nullable();

                $table->string('category', 30);
                $table->string('action', 60);

                // What the work was done to, so every row can be opened rather
                // than merely counted. A number nobody can click into is a
                // number nobody trusts.
                $table->string('subject_type', 40)->nullable();
                $table->unsignedBigInteger('subject_id')->nullable();
                $table->string('subject_label', 160)->nullable();

                // Where the row came from. Also the idempotency key, which is
                // what lets the backfill be re-run without doubling anyone's
                // figures.
                $table->string('source_type', 40);
                $table->unsignedBigInteger('source_id');

                $table->timestamp('occurred_at');
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['source_type', 'source_id', 'action'], 'staff_activities_source_unique');
                $table->index(['admin_user_id', 'occurred_at']);
                $table->index(['category', 'occurred_at']);
                $table->index('occurred_at');
            });
        }

        if (! Schema::hasTable('staff_contributions')) {
            Schema::create('staff_contributions', function (Blueprint $table) {
                $table->id();

                $table->foreignId('admin_user_id')
                    ->constrained('admin_users')->cascadeOnDelete();

                $table->string('category', 30);
                $table->string('title', 160);
                $table->text('description')->nullable();
                $table->date('performed_on');

                // Optional, and it stays optional. Making someone account for
                // their hours turns a contribution log into a timesheet, which
                // is a different product with a different reception.
                $table->unsignedSmallInteger('minutes')->nullable();

                $table->string('link', 500)->nullable();
                $table->string('file_path', 255)->nullable();
                $table->string('original_filename', 255)->nullable();
                $table->string('mime_type', 100)->nullable();
                $table->unsignedBigInteger('file_size')->nullable();

                $table->string('status', 20)->default('pending');
                $table->foreignId('reviewed_by')->nullable()
                    ->constrained('admin_users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->string('review_note', 500)->nullable();

                $table->timestamps();

                $table->index(['admin_user_id', 'performed_on']);
                $table->index(['status', 'performed_on']);
                $table->index(['category', 'performed_on']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_contributions');
        Schema::dropIfExists('staff_activities');
    }
};
