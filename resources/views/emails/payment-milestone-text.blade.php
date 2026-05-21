OKELCOR
{{ strtoupper(str_replace('_', ' ', $stage)) }} — ORDER {{ $order->ref }}
================================================================================

@if ($stage === 'deposit_requested')
Hello {{ $firstName }},

To proceed with your order a deposit payment is required. Please transfer the
amount below using the bank details provided.

ORDER SUMMARY
Order reference:    {{ $order->ref }}
Order total:        EUR {{ number_format((float) $order->total, 2) }}
Deposit ({{ number_format((float) $order->deposit_percent, 0) }}%):         EUR {{ number_format((float) $order->deposit_amount, 2) }}
Balance due later:  EUR {{ number_format((float) $order->balance_amount, 2) }}

BANK TRANSFER DETAILS
Account name:       {{ $bank['account_name'] }}
IBAN:               {{ $bank['iban'] }}
BIC / SWIFT:        {{ $bank['swift_bic'] }}
Bank:               {{ $bank['bank_name'] }}
Payment reference:  {{ $order->ref }}

{{ $bank['sepa_note'] }}
{{ $bank['international_note'] }}

@elseif ($stage === 'deposit_paid')
Hello {{ $firstName }},

Your deposit for order {{ $order->ref }} has been received. Thank you.
We are now preparing your order and will issue the commercial documents shortly.

Order reference:    {{ $order->ref }}
Deposit confirmed:  EUR {{ number_format((float) $order->deposit_amount, 2) }}
Remaining balance:  EUR {{ number_format((float) $order->balance_amount, 2) }}

WHAT HAPPENS NEXT?
We are preparing your order for shipment and arranging logistics. You will
receive a separate notification when your balance payment becomes due.

@elseif ($stage === 'balance_due')
Hello {{ $firstName }},

Your order {{ $order->ref }} is ready for the final balance payment.
Please transfer the amount below to release your shipment.

Order reference:    {{ $order->ref }}
Deposit paid:       EUR {{ number_format((float) $order->deposit_amount, 2) }}
Balance due now:    EUR {{ number_format((float) $order->balance_amount, 2) }}

BANK TRANSFER DETAILS
Account name:       {{ $bank['account_name'] }}
IBAN:               {{ $bank['iban'] }}
BIC / SWIFT:        {{ $bank['swift_bic'] }}
Bank:               {{ $bank['bank_name'] }}
Payment reference:  {{ $order->ref }}

{{ $bank['sepa_note'] }}
{{ $bank['international_note'] }}

Note: Shipment will be released once your balance payment has been received
and confirmed.

@elseif ($stage === 'balance_paid')
Hello {{ $firstName }},

Your full payment for order {{ $order->ref }} has been received. Thank you.

Order reference:    {{ $order->ref }}
Total received:     EUR {{ number_format((float) $order->total, 2) }}

WHAT HAPPENS NEXT?
We are now arranging the release of your shipment. You will receive a separate
notification with shipment and tracking details once the order is released.

@elseif ($stage === 'shipment_released')
Hello {{ $firstName }},

Great news — your shipment for order {{ $order->ref }} has been released.

Order reference:    {{ $order->ref }}
@if ($order->carrier)
Carrier:            {{ $order->carrier }}
@endif
@if ($order->tracking_number)
Tracking number:    {{ $order->tracking_number }}
@endif
@if ($order->container_number)
Container:          {{ $order->container_number }}
@endif
@if ($order->eta)
Estimated arrival:  {{ \Carbon\Carbon::parse($order->eta)->format('d M Y') }}
@endif
@if ($order->shipment_release_note)

Note: {{ $order->shipment_release_note }}
@endif

Shipping documents are available in your account.

@endif

View your order: {{ $orderUrl }}

--------------------------------------------------------------------------------
Questions? Email us at support@okelcor.com
Okelcor GmbH — {{ date('Y') }}
