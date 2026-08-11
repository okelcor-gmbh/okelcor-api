<?php

return [
    'bank_transfer' => [
        'delivery_term'      => 'Incoterms 2020: FOB Germany unless otherwise agreed in writing.',
        'terms'              => '50% against order confirmation and balance against bill of lading.',
        'account_name'       => 'OKELCOR GMBH',
        'account_number'     => '7609068',
        'iban'               => 'BE74 9057 6090 6807',
        'swift_bic'          => 'TRWIBEB1XXX',
        'bank_name'          => 'Wise',
        'bank_address'       => 'Rue du Trône 100, 3rd floor, 1050 Brussels, Belgium',
        'sepa_note'          => 'EUR transfers within SEPA usually arrive within 1–2 working days.',
        'international_note' => 'International SWIFT transfers usually arrive within 4–5 working days.',
    ],

    'milestones' => [
        /*
         * Whether issuing a proforma invoice also starts the deposit ladder
         * (pending_proforma -> deposit_requested) and emails the customer that
         * a deposit is due.
         *
         * Default false, deliberately. Issuing a document and asking a customer
         * for money are two different decisions, and the second one belongs to
         * a person. When this was true the customer saw a deposit request in
         * the portal that nobody at Okelcor had chosen to send — reported by
         * the order manager after a buyer queried a payment he had not been
         * asked for and had not made.
         *
         * The deposit/balance amounts are still calculated and stored either
         * way; only the stage advance and the customer email are gated. Start
         * the ladder explicitly with
         * POST /admin/orders/{id}/payment-milestones/request-deposit.
         */
        'auto_start_on_proforma' => (bool) env('PAYMENT_MILESTONES_AUTO_START', false),

        /* Deposit percentage used when an order has none of its own. */
        'default_deposit_percent' => (float) env('PAYMENT_DEPOSIT_PERCENT', 50),
    ],
];
