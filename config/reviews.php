<?php

/*
|--------------------------------------------------------------------------
| Review invites (Session 118)
|--------------------------------------------------------------------------
|
| When an order is marked delivered, the customer gets one e-mail asking
| for a public review. The single biggest trust lever in this trade is a
| review count a buyer can check (Blackcircles leads its homepage with
| "4.6 on Trustpilot, 200,000 reviews"); Okelcor currently has zero
| public proof.
|
| REVIEW_INVITE_URL is the public review page — the Trustpilot business
| profile or the Google review link. BLANK MEANS OFF: the feature no-ops
| silently until the business creates the profile and sets the URL, the
| same contract as the Gemini key. No code change needed to switch on.
|
*/

return [
    'enabled' => env('REVIEW_INVITE_ENABLED', true),

    // e.g. https://www.trustpilot.com/evaluate/okelcor.com
    // or   https://g.page/r/XXXX/review
    'url' => env('REVIEW_INVITE_URL', ''),

    // Days after delivery before the invite goes out would need a queue
    // worker; while QUEUE_CONNECTION is sync it sends with the status
    // change itself, which is fine — delivered IS the moment.
];
