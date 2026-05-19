<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        @include('pdf.partials._styles')
    </style>
</head>
<body>
<div class="page">

    @php
    // Ensure partials receive expected variables
    $document = null;
    $quote    = null;

    $incoterm = '';
    $incotermLabel = config('payment.bank_transfer.delivery_term', 'FOB Germany');
    @endphp

    @include('pdf.partials._header')
    @include('pdf.partials._address')

    <div style="height:30px;"></div>

    {{-- Document title --}}
    <div class="doc-title">Invoice {{ $invoice->invoice_number }}</div>
    <p class="intro-p" style="margin-bottom:4px;">
        Order reference: <strong>{{ $order->ref }}</strong>
        &nbsp;&mdash;&nbsp;
        Status:
        <strong style="{{ $invoice->status === 'paid' ? 'color:#2e7d32;' : '' }}">
            {{ strtoupper($invoice->status) }}
        </strong>
    </p>

    <div style="height:14px;"></div>

    {{-- Items --}}
    <table class="items-table">
        <thead>
            <tr>
                <th style="width:26px;">Item</th>
                <th>Description</th>
                <th class="r" style="width:80px;">Quantity</th>
                <th class="r" style="width:90px;">Unit price</th>
                <th class="r" style="width:100px;">Total price</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->items as $i => $item)
            <tr>
                <td style="color:#666;">{{ $i + 1 }}.</td>
                <td>
                    <strong>{{ $item->brand }}</strong> &mdash; {{ $item->name }}
                    @if ($item->size)
                    <div style="font-size:10px;font-weight:700;margin-top:2px;">{{ $item->size }}</div>
                    @endif
                    @if ($item->sku)
                    <div class="item-sub">SKU: {{ $item->sku }}</div>
                    @endif
                </td>
                <td class="r">
                    <div class="qty-num">{{ number_format($item->quantity, 2, '.', ',') }}</div>
                    <div class="qty-unit">piece</div>
                </td>
                <td class="r">{{ number_format((float) $item->unit_price, 2) }} EUR</td>
                <td class="r">
                    {{ number_format((float) $item->line_total, 2) }} EUR<br>
                    <span class="price-tax">(Tax {{ number_format((float) ($order->tax_rate ?? 0), 0) }}%)</span>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Totals --}}
    @include('pdf.partials._totals')

    {{-- VAT / legal note --}}
    @if ($order->is_reverse_charge)
    <p class="terms-p" style="font-size:10px;color:#555;">
        Reverse charge — VAT liability transfers to the recipient.
    </p>
    @elseif ($order->tax_treatment === 'exempt')
    <p class="terms-p" style="font-size:10px;color:#555;">
        Export outside the EU — VAT exempt (§6 UStG).
    </p>
    @endif

    {{-- Bank transfer details (if unpaid bank transfer order) --}}
    @if ($order->payment_method === 'bank_transfer' && $invoice->status !== 'paid')
    <div style="height:8px;"></div>
    <p class="terms-p">
        <strong>Terms:</strong>
        {{ config('payment.bank_transfer.terms', '50% against order confirmation and balance against bill of lading.') }}
        Please make payment to the bank account below.
    </p>
    @include('pdf.partials._bank')
    @endif

    <p class="terms-p" style="font-size:10px;color:#555;margin-top:10px;font-style:italic;">
        This is a tax invoice issued by Okelcor GmbH.
        Please quote invoice number <strong>{{ $invoice->invoice_number }}</strong> in all correspondence.
    </p>

    {{-- Footer --}}
    @include('pdf.partials._footer')

</div>
</body>
</html>
