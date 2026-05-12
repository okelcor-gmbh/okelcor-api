<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | CORS_LOCAL_ORIGIN — set in .env for local development only (e.g.
    | http://localhost:3000). Leave empty or unset in production.
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter([
        env('CORS_LOCAL_ORIGIN'),                    // local dev only — not set in production
        'https://okelcor-website.vercel.app',        // Vercel preview (approved)
        'https://okelcor.com',
        'https://www.okelcor.com',
    ])),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Authorization', 'Accept', 'X-Requested-With'],

    'exposed_headers' => [],

    'max_age' => 86400,

    'supports_credentials' => true,

];
