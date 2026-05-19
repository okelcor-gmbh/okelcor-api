<?php

return [
    'name'          => 'Okelcor GmbH',
    'address'       => 'Landsberger Str 155',
    'city'          => '80687 München',
    'country'       => 'Germany',
    'tel'           => '+49(0)8954558360',
    'fax'           => '+49(0)89545583 33',
    'email'         => 'info@okelcor.com',
    'web'           => 'www.okelcor.com',
    'city_court'    => 'München',
    'hr_nr'         => '265378',
    'vat_id'        => 'DE343138173',
    'tax_no'        => '143/167/80505',
    'ceo'           => 'Solomon Okello',
    'contact'       => 'Okelcor Support',

    // PDF document branding
    'logo_path'  => env('DOCUMENT_LOGO_PATH', 'okelcor-logo.avif'),  // relative to public_path()
    'qr_enabled' => env('DOCUMENT_QR_ENABLED', true),

    // Registration bank shown in the document footer (different from customer payment account)
    'footer_bank_name' => 'BANKING CIRCLE S.A- GERMAN BRANCH',
    'footer_bank_iban' => 'DE32202208000027782722',
    'footer_bank_bic'  => 'SXPYDEHHXXX',
];
