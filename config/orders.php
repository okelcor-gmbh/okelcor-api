<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Order confirmation sign-off
    |--------------------------------------------------------------------------
    |
    | An order confirmation goes to the customer only after two people have
    | signed it: one holding orders.signoff_ops (the order manager) and one
    | holding orders.signoff_finance. They must be two different people.
    |
    | `required` is the master switch. Turning it off leaves the sign-off
    | endpoints working and the state visible — it only stops the gate refusing
    | to send. Present so a control that turns out to block the business at a
    | bad moment can be stood down from .env in a minute, rather than needing a
    | deploy, without anyone reaching for the bypass permission repeatedly.
    |
    | `applies_from` grandfathers the backlog. Orders created before this
    | timestamp were confirmed under the old single-approval process and are
    | not retrospectively unapprovable — without it, shipping this would freeze
    | every open order on production until someone signed it twice. Leave blank
    | to apply the rule to every order regardless of age.
    |
    */

    'signoff' => [
        'required'     => env('ORDER_SIGNOFF_REQUIRED', true),
        'applies_from' => env('ORDER_SIGNOFF_APPLIES_FROM', '2026-08-13'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Trade document generation gates
    |--------------------------------------------------------------------------
    |
    | The payment-stage gates on generating a commercial invoice, packing list
    | or delivery note. They exist for a real reason (Session 76: a buyer was
    | e-mailed about a deposit nobody had asked him for) and stay on by default,
    | but an order manager can now pass one deliberately with a written reason,
    | which is recorded as `document_gate_overridden` on the order.
    |
    | Uploading a document is NOT gated at all, here or anywhere: an uploaded
    | file is a record of something that already happened outside this system,
    | and refusing to record a fact does not make the fact go away.
    |
    */

    'document_gates' => [
        'overridable' => env('ORDER_DOCUMENT_GATES_OVERRIDABLE', true),
    ],

];
