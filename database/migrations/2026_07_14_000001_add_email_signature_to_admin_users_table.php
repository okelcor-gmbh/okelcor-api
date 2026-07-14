<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_users', function (Blueprint $table) {
            if (! Schema::hasColumn('admin_users', 'email_signature')) {
                // LONGTEXT, not a capped TEXT — a signature with an inline
                // logo (even after the image itself is extracted to a file,
                // see RichEmailHtmlSanitizer) plus Outlook's verbose inline
                // style markup can run past a 64KB TEXT column.
                $table->longText('email_signature')->nullable()->after('two_factor_confirmed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('admin_users', function (Blueprint $table) {
            if (Schema::hasColumn('admin_users', 'email_signature')) {
                $table->dropColumn('email_signature');
            }
        });
    }
};
