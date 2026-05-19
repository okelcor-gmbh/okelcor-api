@php
$_net = round(
    (float) $order->subtotal
    + (float) $order->delivery_cost
    - (float) ($order->discount_amount ?? 0),
    2
);

if ($order->is_reverse_charge) {
    $_taxLabel = 'Reverse Charge (0%)';
} elseif ($order->tax_treatment === 'exempt') {
    $_taxLabel = 'Export 0%';
} elseif ((float) $order->tax_amount > 0) {
    $_taxLabel = 'VAT (' . number_format((float) $order->tax_rate, 0) . '%)';
} else {
    $_taxLabel = 'VAT (0%)';
}
@endphp
<table class="totals-table">
    @if ((float) ($order->discount_amount ?? 0) > 0)
    <tr>
        <td>{{ $order->discount_label ?? 'Discount' }}</td>
        <td class="amt">&minus;{{ number_format((float) $order->discount_amount, 2) }} EUR</td>
    </tr>
    @endif
    <tr>
        <td>Total net</td>
        <td class="amt">{{ number_format($_net, 2) }} EUR</td>
    </tr>
    @if ($order->tax_treatment !== null)
    <tr>
        <td>{{ $_taxLabel }}</td>
        <td class="amt">{{ number_format((float) $order->tax_amount, 2) }} EUR</td>
    </tr>
    @endif
    <tr class="gross-row">
        <td>TOTAL GROSS</td>
        <td class="amt">{{ number_format((float) $order->total, 2) }} EUR</td>
    </tr>
</table>
<hr class="totals-divider">
