<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'adyen' => [
        'api_key'          => env('ADYEN_API_KEY'),
        'merchant_account' => env('ADYEN_MERCHANT_ACCOUNT'),
        'environment'      => env('ADYEN_ENVIRONMENT', 'test'),
        'client_key'       => env('ADYEN_CLIENT_KEY'),
    ],

    'stripe' => [
        'secret'         => env('STRIPE_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'currency'       => env('STRIPE_CURRENCY', 'eur'),
    ],

    'shipsgo' => [
        'key' => env('SHIPSGO_API_KEY'),
    ],

    'dhl' => [
        'api_key' => env('DHL_API_KEY'),
    ],

    // Traccar — open-source GPS/fleet tracking server (REST client).
    // We are a CLIENT of a Traccar instance; the server runs elsewhere
    // (own VPS/cloud, or the public demo server for trials).
    //   TRACCAR_URL      e.g. https://demo.traccar.org  (no trailing /api)
    //   TRACCAR_TOKEN    preferred: a Traccar API token (Bearer)
    //   TRACCAR_EMAIL/PASSWORD  fallback: Basic auth (e.g. a demo account)
    'traccar' => [
        'url'      => rtrim((string) env('TRACCAR_URL', ''), '/'),
        'token'    => env('TRACCAR_TOKEN'),
        'email'    => env('TRACCAR_EMAIL'),
        'password' => env('TRACCAR_PASSWORD'),
        'timeout'  => (int) env('TRACCAR_TIMEOUT', 15),
        // Max hours of history for the customer "current trip" trail (safety cap).
        'route_hours' => (int) env('TRACCAR_ROUTE_HOURS', 12),
        // Delivery ETA tuning: straight-line distance × road factor, ÷ speed.
        'road_factor'       => (float) env('TRACCAR_ROAD_FACTOR', 1.3),
        'default_speed_kmh' => (float) env('TRACCAR_DEFAULT_SPEED_KMH', 60),
    ],

    // OpenStreetMap Nominatim — free forward geocoding (address → lat/lng) for
    // delivery destinations. Results are cached + persisted on the order, so we
    // call it at most once per address. Nominatim policy requires a User-Agent
    // with a contact, so set NOMINATIM_EMAIL (falls back to MAIL_FROM_ADDRESS).
    'nominatim' => [
        'url'   => rtrim((string) env('NOMINATIM_URL', 'https://nominatim.openstreetmap.org'), '/'),
        'email' => env('NOMINATIM_EMAIL', env('MAIL_FROM_ADDRESS', 'support@okelcor.com')),
    ],

    'ebay' => [
        'client_id'     => env('EBAY_CLIENT_ID'),
        'client_secret' => env('EBAY_CLIENT_SECRET'),
        'environment'   => env('EBAY_ENVIRONMENT', 'sandbox'),
    ],

    'mollie' => [
        'webhook_secret' => env('MOLLIE_WEBHOOK_SECRET'),
    ],

    'ebay_sell' => [
        'client_id'              => env('EBAY_CLIENT_ID'),
        'client_secret'          => env('EBAY_CLIENT_SECRET'),
        'refresh_token'          => env('EBAY_REFRESH_TOKEN'),
        'ru_name'                => env('EBAY_RU_NAME'),
        'marketplace_id'         => env('EBAY_MARKETPLACE_ID', 'EBAY_DE'),
        'category_id'            => env('EBAY_CATEGORY_ID', '11755'),
        'fulfillment_policy_id'  => env('EBAY_FULFILLMENT_POLICY_ID'),
        'payment_policy_id'      => env('EBAY_PAYMENT_POLICY_ID'),
        'return_policy_id'       => env('EBAY_RETURN_POLICY_ID'),
        'seller_postal_code'     => env('EBAY_SELLER_POSTAL_CODE'),
        'seller_location'        => env('EBAY_SELLER_LOCATION', 'Germany'),
        'merchant_location_key'  => env('EBAY_MERCHANT_LOCATION_KEY', 'OKELCOR-MAIN'),
    ],

];
