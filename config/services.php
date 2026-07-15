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

    // GLS parcel Track & Trace API v1 + Authentication API v2 (GLS Group
    // Developer Portal). App ID + API Key + API Secret are issued together
    // per registered app — no separate "customer ID". Base URL, tracking
    // endpoint, response schema, and the token-exchange shape are all
    // confirmed directly from the account's own portal ("Try this API"
    // panels + published EventDTO schema) — see GlsTrackingService for the
    // full detail. Degrades cleanly (['error' => ...]) until fully set.
    //
    // Defaults to the SANDBOX host — every confirmed portal panel for this
    // account showed api-sandbox.gls-group.net; production access needs a
    // separate GLS approval step not yet completed. Sandbox may return
    // test data rather than real parcel status — verify before trusting
    // this for real customers. Swap both envs to the api.gls-group.net host
    // once production access is granted.
    'gls' => [
        'app_id'            => env('GLS_APP_ID'),
        'api_key'           => env('GLS_API_KEY'),
        'api_secret'        => env('GLS_API_SECRET'),
        'base_url'          => rtrim((string) env('GLS_API_BASE_URL', 'https://api-sandbox.gls-group.net/track-and-trace-v1'), '/'),
        'token_endpoint'    => env('GLS_API_TOKEN_ENDPOINT', 'https://api-sandbox.gls-group.net/oauth2/v2/token'),
        'tracking_endpoint' => env('GLS_API_TRACKING_ENDPOINT', rtrim((string) env('GLS_API_BASE_URL', 'https://api-sandbox.gls-group.net/track-and-trace-v1'), '/') . '/tracking/simple/trackids'),
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

    // Inbound e-mail capture — polls a plain IMAP mailbox so a customer's
    // reply to a system-sent e-mail lands back in the admin panel, not only
    // in the sending admin's personal inbox. See EMAIL_INBOUND_SETUP.md for
    // the one-time setup this requires.
    //
    // Deliberately NOT Microsoft Graph/Azure — support@okelcor.com is a
    // Microsoft 365 mailbox, and Microsoft has fully retired Basic Auth for
    // IMAP/POP/SMTP on Exchange Online (a username+password IMAP connection
    // to it is rejected outright). Instead, an Exchange inbox rule
    // REDIRECTS a copy of everything sent to support@okelcor.com to a
    // separate, non-Microsoft mailbox — `host`/`username`/`password` below
    // point at THAT mailbox, not at Microsoft 365 directly.
    //
    // `address` stays support@okelcor.com regardless — it's the customer-
    // facing address (Reply-To on outgoing mail is a plus-addressed variant
    // of it, e.g. support+{id}@okelcor.com) and Exchange's Redirect (unlike
    // Forward) preserves the original To: header, so plus-address matching
    // on the redirected copy still works against this same value.
    //
    // IMPORTANT: the redirected mailbox also passes through other automated
    // system mail — ORDER_EMAIL/QUOTE_EMAIL/CRM_DIGEST_EMAIL all send to
    // support@okelcor.com too, and get redirected along with everything
    // else. `own_domain` (defaults to the domain in mail.from.address) is
    // used to skip any message sent BY this app's own domain, so those
    // never get mistaken for a customer reply and spawn a bogus lead.
    //
    // Degrades cleanly: `enabled=false` (the default) leaves Reply-To
    // exactly as it was before this feature existed (replies go to the
    // sending admin).
    'mail_inbound' => [
        'enabled'           => (bool) env('MAIL_INBOUND_ENABLED', false),
        'address'           => env('MAIL_INBOUND_ADDRESS', 'support@okelcor.com'),
        'host'              => env('MAIL_INBOUND_HOST'),
        'port'              => env('MAIL_INBOUND_PORT', 993),
        'encryption'        => env('MAIL_INBOUND_ENCRYPTION', 'ssl'),
        'username'          => env('MAIL_INBOUND_USERNAME'),
        'password'          => env('MAIL_INBOUND_PASSWORD'),
        'message_id_domain' => env('MAIL_INBOUND_MESSAGE_ID_DOMAIN', 'okelcor.com'),
        'own_domain'        => env('MAIL_INBOUND_OWN_DOMAIN'),
    ],

    // WhatsApp Business Cloud API (Meta). Requires a WhatsApp Business
    // Account (WABA) + a registered phone number in Meta Business Manager —
    // see WHATSAPP_SETUP.md for the full one-time setup this account owner
    // needs to do before any of this works. Degrades cleanly (['error' =>
    // ...]) when unconfigured, same pattern as gls/dhl above.
    'whatsapp' => [
        'phone_number_id'    => env('WHATSAPP_PHONE_NUMBER_ID'),
        'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
        'access_token'       => env('WHATSAPP_ACCESS_TOKEN'),
        'app_secret'         => env('WHATSAPP_APP_SECRET'),
        'verify_token'       => env('WHATSAPP_VERIFY_TOKEN'),
        'api_version'        => env('WHATSAPP_API_VERSION', 'v20.0'),
        'base_url'           => env('WHATSAPP_API_BASE_URL', 'https://graph.facebook.com'),
    ],

];
