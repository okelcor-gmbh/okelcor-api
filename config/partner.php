<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Edit window
    |--------------------------------------------------------------------------
    |
    | How long a partner may edit or delete their own entry, measured from the
    | server's `created_at` — never from a device clock, which on cheap shared
    | Android handsets drifts and is user-settable.
    |
    | Known and accepted consequence: an entry authored offline on Monday and
    | synced on Wednesday gets a Wednesday `created_at`, so its window starts
    | on arrival rather than on authoring. That is more generous than intended
    | rather than a bug — the alternative is trusting the device clock, which
    | would let anyone reopen a locked entry by changing the date on the phone.
    |
    */
    'edit_window_hours' => (int) env('PARTNER_EDIT_WINDOW_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | PIN policy
    |--------------------------------------------------------------------------
    |
    | 6 digits minimum. Four digits on a public endpoint is 10,000 combinations
    | against a threat model that explicitly includes shared devices.
    |
    */
    'pin' => [
        'min_length'      => 6,
        'max_length'      => 10,
        'max_attempts'    => 5,
        'lockout_minutes' => 15,
    ],

    /*
    |--------------------------------------------------------------------------
    | Backdating bounds
    |--------------------------------------------------------------------------
    |
    | `sold_at` is partner-declared, so it needs bounds or a typo'd year lands
    | in the books. Future dates are refused outright; the floor is generous
    | because there is a real paper backlog to enter.
    |
    */
    'max_backdate_days' => (int) env('PARTNER_MAX_BACKDATE_DAYS', 730),

    /*
    |--------------------------------------------------------------------------
    | Accepted currencies
    |--------------------------------------------------------------------------
    |
    | An allowlist rather than "any 3 letters". A typo'd currency ("NGM") would
    | be silently unaggregatable in the books export and, because nothing here
    | converts, would never be caught by a failing conversion either — it would
    | just quietly sit outside every total. Adding a market means adding its
    | currency here, which is a one-line change.
    |
    */
    'currencies' => [
        'NGN', 'GHS', 'KES', 'AED', 'ZAR', 'XOF', 'XAF',
        'EUR', 'USD', 'GBP',
    ],

    /*
    |--------------------------------------------------------------------------
    | Token lifetime
    |--------------------------------------------------------------------------
    |
    | Partner sessions are long-lived on purpose: the app must open and accept
    | entries offline, and a partner who cannot reach the network also cannot
    | re-authenticate. Expiry is enforced on use, in PartnerAuth.
    |
    */
    'token_ttl_days' => (int) env('PARTNER_TOKEN_TTL_DAYS', 90),

];
