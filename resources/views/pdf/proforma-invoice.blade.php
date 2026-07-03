<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Proforma Invoice {{ $document->number }}</title>
    <style>
        @include('pdf.partials._styles')
    </style>
</head>
<body>
<div class="page">

    @php
    $incoterm = strtoupper((string) ($quote?->incoterm ?? ''));
    $incotermLabel = match($incoterm) {
        'FOB' => 'FOB Germany (Incoterms 2020)',
        'CIF' => 'CIF (Incoterms 2020)',
        'EXW' => 'EXW (Incoterms 2020) — Ex Works',
        'DAP' => 'DAP (Incoterms 2020) — Delivered at Place',
        'DDP' => 'DDP (Incoterms 2020) — Delivered Duty Paid',
        default => $incoterm
            ? $incoterm . ' (Incoterms 2020)'
            : config('payment.bank_transfer.delivery_term', 'FOB Germany'),
    };
    @endphp

    @include('pdf.partials._header')
    @include('pdf.partials._address')

    <div style="height:30px;"></div>

    {{-- Document title --}}
    <div class="doc-title">Proforma Invoice {{ $document->number }}</div>

    <p class="intro-p">Dear customer,</p>
    <p class="intro-p" style="margin-bottom:16px;">
        Please find below the proforma invoice for order {{ $order->ref }}:
    </p>

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

    {{-- Delivery & payment terms --}}
    <p class="terms-p">Delivery Terms: {{ $incotermLabel }}</p>
    <p class="terms-p">
        <strong>Terms:</strong>
        {{ config('payment.bank_transfer.terms', '50% against order confirmation and balance against bill of lading.') }}
        Please make payment to the bank account below.
    </p>

    {{-- Bank (always show on proforma — it IS the payment instruction) --}}
    @include('pdf.partials._bank')

    {{-- Customer acceptance — signature confirms agreement to price, products, and terms above.
         Print, sign, and return via the customer portal. --}}
    <p class="terms-p" style="margin-top:14px;margin-bottom:6px;">
        <strong>Acceptance:</strong> by signing below, the customer confirms acceptance of the
        products, pricing, and terms stated in this proforma invoice.
    </p>
    <table class="sig-table">
        <tr>
            <td style="width:34%;padding-right:10px;">
                <div class="sig-box">
                    <div class="sig-lbl">Date</div>
                    <div class="sig-line"></div>
                </div>
            </td>
            <td style="width:33%;padding-right:10px;">
                <div class="sig-box">
                    <div class="sig-lbl">Signature</div>
                    <div class="sig-line"></div>
                    <div class="sig-caption">Authorised representative</div>
                </div>
            </td>
            <td style="width:33%;">
                <div class="sig-box">
                    <div class="sig-lbl">Company Stamp</div>
                    <div class="sig-line"></div>
                    <div class="sig-caption">If applicable</div>
                </div>
            </td>
        </tr>
    </table>

    <p class="terms-p" style="font-size:10px;color:#555;margin-top:10px;font-style:italic;">
        This document is a proforma invoice only and does not constitute a final tax invoice.
        A final invoice will be issued upon confirmation of payment.
    </p>

    {{-- Footer --}}
    @include('pdf.partials._footer')

</div>
</body>
</html>
