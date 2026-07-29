<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Site-wide dispatch estimate, surfaced on the public product payload so the
 * shop can say "ships in ~N days" instead of a bare stock badge.
 *
 * Seeded EMPTY on purpose. The frontend displays whatever this returns
 * verbatim, so an invented default would become a delivery promise nobody
 * approved — the payload omits the field entirely until the order manager
 * sets a real number in Admin → Settings.
 *
 * Created here rather than in SiteSettingsSeeder because that seeder uses
 * updateOrCreate(), which would reset an admin-entered value on any re-run.
 * insertOrIgnore is idempotent and never clobbers an existing row.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('site_settings')->insertOrIgnore([
            'key'   => 'estimated_dispatch_days',
            'value' => '',
            'type'  => 'string',
            'group' => 'shop',
        ]);
    }

    public function down(): void
    {
        DB::table('site_settings')->where('key', 'estimated_dispatch_days')->delete();
    }
};
