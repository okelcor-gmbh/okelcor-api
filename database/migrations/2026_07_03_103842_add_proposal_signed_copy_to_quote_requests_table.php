<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a customer accept a proposal by printing, signing, and uploading it
 * back — an alternative to the digital "Accept" click, for customers who
 * prefer/require a wet-signature paper trail. Additive/guarded, mirrors the
 * existing proposal_* column pattern on this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quote_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('quote_requests', 'proposal_signed_copy_path')) {
                $table->string('proposal_signed_copy_path', 500)->nullable()->after('proposal_acceptance_note');
                $table->string('proposal_signed_copy_original_filename', 255)->nullable()->after('proposal_signed_copy_path');
                $table->string('proposal_signed_copy_mime_type', 100)->nullable()->after('proposal_signed_copy_original_filename');
                $table->timestamp('proposal_signed_copy_uploaded_at')->nullable()->after('proposal_signed_copy_mime_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('quote_requests', function (Blueprint $table) {
            if (Schema::hasColumn('quote_requests', 'proposal_signed_copy_path')) {
                $table->dropColumn([
                    'proposal_signed_copy_path',
                    'proposal_signed_copy_original_filename',
                    'proposal_signed_copy_mime_type',
                    'proposal_signed_copy_uploaded_at',
                ]);
            }
        });
    }
};
