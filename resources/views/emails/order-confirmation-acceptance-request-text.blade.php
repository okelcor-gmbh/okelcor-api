OKELCOR
=======

Action required: Please confirm your Order — {{ $order->ref }}

Your Order Confirmation ({{ $document->number }}) for order {{ $order->ref }} is attached to this email.
Please review it carefully and confirm your acceptance so we can proceed with your Proforma Invoice.

Order reference:  {{ $order->ref }}
Document number:  {{ $document->number }}
Order total:      €{{ number_format($order->total, 2) }}
Link expires:     {{ $expiresAt }}

@if ($adminMessage)
Note from Okelcor:
{{ $adminMessage }}

@endif
To review and confirm your order, open this link in your browser:
{{ $acceptUrl }}

If you have any questions, please contact us at support@okelcor.com.

Okelcor — {{ date('Y') }}
