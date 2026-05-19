<div class="sender-line">
    {{ config('company.name') }} &mdash; {{ config('company.address') }}, &mdash; {{ config('company.city') }}
</div>
<div class="customer-address">
    <strong>{{ $order->customer_name }}</strong><br>
    @if (($quote ?? null)?->company_name && $quote->company_name !== $order->customer_name)
    {{ $quote->company_name }}<br>
    @endif
    {{ $order->address }}<br>
    {{ $order->city }}@if ($order->postal_code), {{ $order->postal_code }}@endif<br>
    {{ $order->country }}
    @if ($order->vat_number)
    <br>VAT: {{ $order->vat_number }}
    @endif
</div>
