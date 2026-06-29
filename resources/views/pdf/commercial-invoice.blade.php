<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Commercial Invoice {{ $document->number }}</title>
    <style>
        @include('pdf.partials._styles')
    </style>
</head>
<body>
<div class="page">

    @php
    $totalQty = $order->items->sum('quantity');

    $carrierTypeMap = [
        'sea'  => 'Sea Freight',
        'air'  => 'Air Freight',
        'dhl'  => 'DHL Express',
        'road' => 'Road Freight',
        'truck' => 'Truck Freight',
    ];
    $carrierTypeLabel = $carrierTypeMap[$order->carrier_type ?? ''] ?? ucfirst($order->carrier_type ?? '');

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

    $trackingRef = $order->tracking_number ?: ($order->container_number ?: null);
    @endphp

    @include('pdf.partials._header')
    @include('pdf.partials._address')

    <div style="height:24px;"></div>

    {{-- Document title --}}
    <div class="doc-title">Commercial Invoice {{ $document->number }}</div>
    <p class="intro-p" style="margin-bottom:4px;">
        Order reference: <strong>{{ $order->ref }}</strong>
        @if ($invoice?->invoice_number)
        &nbsp;&mdash;&nbsp; Related invoice: <strong>{{ $invoice->invoice_number }}</strong>
        @endif
        @if ($order->payment_status === 'paid')
        &nbsp;&mdash;&nbsp; <strong>PAID</strong>
        @endif
    </p>

    <div style="height:14px;"></div>

    {{-- Trade terms bar --}}
    <table class="info-bar" style="margin-bottom:14px;">
        <tr>
            <td style="width:30%;">
                <div class="ibl">Delivery / Trade Terms</div>
                <div class="ival" style="font-size:10px;">{{ $incotermLabel }}</div>
            </td>
            <td style="width:15%;">
                <div class="ibl">Country of Export</div>
                <div class="ival">Germany</div>
            </td>
            <td style="width:20%;">
                <div class="ibl">Country of Destination</div>
                <div class="ival">{{ $order->country ?: '—' }}</div>
            </td>
            <td style="width:20%;">
                <div class="ibl">Carrier / Transport</div>
                <div class="ival" style="font-size:10px;">
                    {{ $order->carrier ?: ($carrierTypeLabel ?: '—') }}
                </div>
                @if ($order->carrier && $carrierTypeLabel)
                <div class="isub">{{ $carrierTypeLabel }}</div>
                @endif
            </td>
            <td style="width:15%;">
                <div class="ibl">Tracking / Waybill</div>
                <div class="ival" style="font-size:10px;">{{ $trackingRef ?: '—' }}</div>
            </td>
        </tr>
    </table>

    {{-- Items --}}
    <table class="items-table">
        <thead>
            <tr>
                <th style="width:22px;">#</th>
                <th>Product Description</th>
                <th style="width:90px;">Tyre Size</th>
                <th class="c" style="width:60px;">HS Code</th>
                <th class="c" style="width:55px;">Origin</th>
                <th class="c" style="width:38px;">Qty</th>
                <th class="r" style="width:78px;">Unit Value</th>
                <th class="r" style="width:88px;">Total Value</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->items as $i => $item)
            <tr>
                <td class="c" style="color:#888;">{{ $i + 1 }}</td>
                <td>
                    <strong>{{ $item->brand }}</strong> &mdash; {{ $item->name }}
                    @if ($item->sku)
                    <div class="item-sub">SKU: {{ $item->sku }}</div>
                    @endif
                </td>
                <td>{{ $item->size ?: '—' }}</td>
                <td class="c" style="color:#aaa;font-style:italic;">—</td>
                <td class="c">Germany</td>
                <td class="c">
                    <div class="qty-num">{{ number_format($item->quantity, 2, '.', ',') }}</div>
                    <div class="qty-unit">pcs</div>
                </td>
                <td class="r">{{ number_format((float) $item->unit_price, 2) }} EUR</td>
                <td class="r">
                    {{ number_format((float) $item->line_total, 2) }} EUR<br>
                    <span class="price-tax">(Tax {{ number_format((float) ($order->tax_rate ?? 0), 0) }}%)</span>
                </td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr style="background:#f0f0f0;">
                <td colspan="5" class="r" style="padding:7px 9px;font-size:10px;font-weight:700;border:1px solid #d0d0d0;">Total units</td>
                <td class="c" style="padding:7px 9px;font-weight:700;border:1px solid #d0d0d0;">{{ $totalQty }}</td>
                <td style="border:1px solid #d0d0d0;"></td>
                <td class="r" style="padding:7px 9px;font-weight:700;border:1px solid #d0d0d0;">
                    {{ number_format((float) $order->subtotal, 2) }} EUR
                </td>
            </tr>
        </tfoot>
    </table>

    {{-- Totals --}}
    @include('pdf.partials._totals')

    {{-- Customs / VAT declaration --}}
    @if ($order->is_reverse_charge)
    <p class="terms-p" style="background:#fffbe6;border-left:3px solid #f0b429;padding:8px 10px;font-size:10px;">
        <strong>Intra-Community Supply — Reverse Charge:</strong>
        VAT liability transfers to the recipient pursuant to Article 138 of Council Directive 2006/112/EC.
        The buyer is responsible for accounting for VAT in the member state of destination.
    </p>
    @elseif ($order->tax_treatment === 'exempt')
    <p class="terms-p" style="background:#f0f9f0;border-left:3px solid #4caf50;padding:8px 10px;font-size:10px;">
        <strong>Export Outside the EU — VAT Exempt:</strong>
        This shipment constitutes an export outside the European Union and is exempt from VAT pursuant to §6 UStG.
    </p>
    @else
    <p class="terms-p" style="background:#f5f5f5;border-left:3px solid #aaa;padding:8px 10px;font-size:10px;">
        <strong>Standard VAT Applied:</strong>
        This supply is subject to German VAT at {{ number_format((float) $order->tax_rate, 0) }}%.
        VAT amount: {{ number_format((float) $order->tax_amount, 2) }} EUR.
    </p>
    @endif

    {{-- Signature blocks --}}
    <table class="sig-table">
        <tr>
            <td style="width:50%;padding-right:10px;">
                <div class="sig-box">
                    <div class="sig-lbl">Authorised Signatory — Okelcor GmbH</div>
                    <div class="sig-line"></div>
                    <div class="sig-caption">Name / Title / Date / Signature</div>
                </div>
            </td>
            <td style="width:50%;">
                <div class="sig-box">
                    <div class="sig-lbl">Company Stamp</div>
                    <div class="sig-line"></div>
                    <div class="sig-caption">Official stamp of Okelcor GmbH</div>
                </div>
            </td>
        </tr>
    </table>

    <p class="terms-p" style="font-size:10px;color:#555;font-style:italic;">
        Commercial Invoice — for customs and export purposes only. This is not a final tax invoice.
        Document: {{ $document->number }} &mdash; Order: {{ $order->ref }}
        @if ($invoice?->invoice_number) &mdash; Invoice: {{ $invoice->invoice_number }}@endif
    </p>

    {{-- Footer --}}
    @include('pdf.partials._footer')

</div>
</body>
</html>
