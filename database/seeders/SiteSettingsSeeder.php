<?php

namespace Database\Seeders;

use App\Models\SiteSetting;
use Illuminate\Database\Seeder;

class SiteSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            // company
            ['key' => 'company_name',            'value' => 'Okelcor GmbH',                   'type' => 'string',  'group' => 'company'],
            ['key' => 'company_email',            'value' => 'info@okelcor.com',               'type' => 'string',  'group' => 'company'],
            ['key' => 'company_phone',            'value' => '+49 (0) 89 / 545 583 60',        'type' => 'string',  'group' => 'company'],
            ['key' => 'company_fax',              'value' => '',                               'type' => 'string',  'group' => 'company'],
            ['key' => 'company_address',          'value' => '',                               'type' => 'string',  'group' => 'company'],

            // shop
            ['key' => 'vat_rate',                 'value' => '19',                             'type' => 'string',  'group' => 'shop'],
            ['key' => 'default_currency',         'value' => 'EUR',                            'type' => 'string',  'group' => 'shop'],
            ['key' => 'free_shipping_threshold',  'value' => '0',                              'type' => 'string',  'group' => 'shop'],

            // email
            ['key' => 'contact_email',            'value' => 'info@okelcor.com',               'type' => 'string',  'group' => 'email'],
            ['key' => 'quote_email',              'value' => 'quotes@okelcor.com',             'type' => 'string',  'group' => 'email'],
            ['key' => 'from_email',               'value' => 'no-reply@okelcor.com',           'type' => 'string',  'group' => 'email'],
            ['key' => 'quote_response_time',      'value' => '24 hours',                       'type' => 'string',  'group' => 'email'],

            // site
            ['key' => 'site_tagline',             'value' => 'Your B2B Tyre Wholesale Partner', 'type' => 'string', 'group' => 'site'],
            ['key' => 'google_analytics_id',      'value' => '',                               'type' => 'string',  'group' => 'site'],
            ['key' => 'maintenance_mode',         'value' => 'false',                          'type' => 'boolean', 'group' => 'site'],
        ];

        foreach ($settings as $setting) {
            SiteSetting::updateOrCreate(
                ['key' => $setting['key']],
                [
                    'value' => $setting['value'],
                    'type'  => $setting['type'],
                    'group' => $setting['group'],
                ]
            );
        }
    }
}
