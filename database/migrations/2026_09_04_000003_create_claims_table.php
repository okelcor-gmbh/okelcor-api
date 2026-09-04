<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The after-sales claims queue (Session 119).
 *
 * "We handle claims" is a promise the business makes on the website, but the
 * claims themselves live in e-mail threads — nobody can see how many are
 * open, who is on each one, or how long a customer has been waiting. This
 * table turns the promise into a system, on the exact machinery that already
 * runs the finance snapshot and the team to-dos: status + assignee + My Work
 * + notify-on-change. Its stats become a quality signal the dashboard can
 * read later.
 *
 * `status` and `type` are plain strings, not MySQL enums, with the value
 * lists living in Claim::STATUSES / Claim::TYPES — the SQLite test harness
 * renders enums as varchar and hides literal mismatches until production
 * (the customer_registered lesson).
 *
 * One NEW table; nothing existing is read, altered or backfilled.
 * Deploy-order safe: readers go through Claim::available().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('claims')) {
            return;
        }

        Schema::create('claims', function (Blueprint $table) {
            $table->id();

            // CLM-00001 — stamped from the id right after insert, so every
            // e-mail and notification can name the claim unambiguously.
            $table->string('ref', 20)->nullable()->unique();

            // The order the claim is about. Both halves nullable and
            // independent: order_id when the order lives in this system,
            // order_number as free text for the historic and eBay orders
            // that do not — a claim about an order we cannot link must
            // still be loggable, because the customer does not care where
            // we keep our records.
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->string('order_number', 60)->nullable();

            $table->string('customer_name', 160);
            $table->string('customer_email', 160)->nullable();
            $table->string('customer_company', 160)->nullable();

            // damage | wrong_item | shortage | quality | warranty | delivery
            // | other (Claim::TYPES).
            $table->string('type', 20)->default('other');

            // What the customer says happened — pasted from the e-mail thread
            // this claim used to be.
            $table->text('description');

            // Tyres affected, where it is a per-unit problem.
            $table->unsignedInteger('quantity')->nullable();

            // new | in_review | awaiting_customer | approved | rejected |
            // closed (Claim::STATUSES). Only `closed` is terminal: an
            // approved claim still owes the customer a credit note or
            // replacement, a rejected one still owes them the reasons.
            $table->string('status', 20)->default('new');

            // What was decided and what was done about it.
            $table->text('outcome_note')->nullable();

            // The tag — same contract as the snapshot board and the to-dos:
            // being assigned lands the claim in My Work and is authorization
            // to work it from there.
            $table->foreignId('assigned_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();

            // First entry into approved/rejected — the decision date the
            // resolution-time stats measure to. Kept even if the status later
            // moves on to closed; cleared if the decision is reopened.
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('admin_users')->nullOnDelete();

            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            $table->index(['assigned_admin_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claims');
    }
};
