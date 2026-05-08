OKELCOR
================================================================================

@if ($order->payment_method === 'bank_transfer' && $order->payment_status !== 'paid')
PAYMENT INSTRUCTIONS — {{ $order->ref }}

Hello {{ explode(' ', trim($order->customer_name))[0] }},

Your order has been received. Please transfer the amount due using the bank
details below. Quote your order reference {{ $order->ref }} as the payment
reference so we can match your transfer.
@else
ORDER CONFIRMED — {{ $order->ref }}

Hello {{ explode(' ', trim($order->customer_name))[0] }},

Your payment has been received. We will confirm availability and shipping
details shortly.
@endif

--------------------------------------------------------------------------------
ORDER SUMMARY
--------------------------------------------------------------------------------
Order reference : {{ $order->ref }}
Date            : {{ $order->created_at?->format('d M Y') }}
Payment         : @if ($order->payment_method === 'bank_transfer' && $order->payment_status !== 'paid')Pending — Bank Transfer@else Confirmed @endif

--------------------------------------------------------------------------------
ITEMS
--------------------------------------------------------------------------------
@foreach ($order->items as $item)
{{ $item->name }}@if ($item->size) ({{ $item->size }})@endif
  Qty {{ $item->quantity }} x €{{ number_format((float) $item->unit_price, 2) }} = €{{ number_format((float) $item->line_total, 2) }}
@endforeach

--------------------------------------------------------------------------------
@if ($order->tax_treatment !== null)
Subtotal (net)  : €{{ number_format((float) $order->subtotal, 2) }}
@if ((float) $order->delivery_cost > 0)
Delivery        : €{{ number_format((float) $order->delivery_cost, 2) }}
@endif
@if ((float) ($order->discount_amount ?? 0) > 0)
{{ $order->discount_label ?? 'Discount' }}{{ str_repeat(' ', max(1, 16 - strlen($order->discount_label ?? 'Discount'))) }}: -€{{ number_format((float) $order->discount_amount, 2) }}
@endif
VAT             : €{{ number_format((float) ($order->tax_amount ?? 0), 2) }}
@endif
TOTAL           : €{{ number_format((float) $order->total, 2) }}

Track your order: {{ $trackingUrl }}

@if ($invoice)
Invoice number  : {{ $invoice->invoice_number }}
View invoices   : {{ $invoicesUrl }}
@elseif ($order->is_reverse_charge && $order->payment_status === 'paid')
INVOICE NOTICE
Your final invoice will be available after the EU Entry Certificate is signed.
As your order qualifies for VAT zero-rating under the reverse-charge mechanism,
the tax invoice will be released once you have signed the EU Entry Certificate
(Gelangensbestätigung) confirming delivery to the destination EU member state.
Sign and download from your account: {{ $trackingUrl }}
@endif
@if ($order->payment_method === 'bank_transfer' && $order->payment_status !== 'paid')
================================================================================
BANK TRANSFER DETAILS
================================================================================
Account Name    : {{ config('payment.bank_transfer.account_name') }}
Account Number  : {{ config('payment.bank_transfer.account_number') }}
IBAN            : {{ config('payment.bank_transfer.iban') }}
BIC / SWIFT     : {{ config('payment.bank_transfer.swift_bic') }}
Bank            : {{ config('payment.bank_transfer.bank_name') }}
Bank Address    : {{ config('payment.bank_transfer.bank_address') }}
Payment Ref     : {{ $order->ref }}
Delivery Term   : {{ config('payment.bank_transfer.delivery_term') }}

{{ config('payment.bank_transfer.terms') }}

{{ config('payment.bank_transfer.sepa_note') }}
{{ config('payment.bank_transfer.international_note') }}
@endif
================================================================================
Questions? Email us at support@okelcor.com
Okelcor — {{ date('Y') }}
