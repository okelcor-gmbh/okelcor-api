<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Delivery Note {{ $document->number }}</title>
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

    $latestEvent  = $order->shipmentEvents?->sortByDesc('event_date')->first();
    $deliveryDate = $order->eta ?? $order->estimated_delivery;
    @endphp

    @include('pdf.partials._header')
    @include('pdf.partials._address')

    <div style="height:24px;"></div>

    {{-- Document title --}}
    <div class="doc-title">Delivery Note {{ $document->number }}</div>
    <p class="intro-p" style="margin-bottom:4px;">
        Order reference: <strong>{{ $order->ref }}</strong>
        @if ($invoice?->invoice_number)
        &nbsp;&mdash;&nbsp; Invoice: <strong>{{ $invoice->invoice_number }}</strong>
        @endif
        @if ($deliveryDate)
        &nbsp;&mdash;&nbsp; Delivery date:
        <strong>
            @if ($deliveryDate instanceof \Carbon\Carbon)
                {{ $deliveryDate->format('d M Y') }}
            @else
                {{ \Carbon\Carbon::parse($deliveryDate)->format('d M Y') }}
            @endif
        </strong>
        @endif
    </p>

    <div style="height:14px;"></div>

    {{-- Delivery address bar --}}
    <div style="background:#f5f5f5;border-left:3px solid #999;padding:10px 12px;font-size:11px;line-height:1.7;margin-bottom:14px;">
        <div class="sec-lbl" style="margin-bottom:4px;">Delivery Address</div>
        <strong>{{ $order->customer_name }}</strong><br>
        {{ $order->address }}<br>
        {{ $order->city }}@if ($order->postal_code), {{ $order->postal_code }}@endif &mdash; {{ $order->country }}
        @if ($order->vat_number)
        &nbsp;&nbsp;|&nbsp;&nbsp; VAT: {{ $order->vat_number }}
        @endif
    </div>

    {{-- Shipment info bar --}}
    <table class="info-bar">
        <tr>
            <td style="width:25%;">
                <div class="ibl">Carrier</div>
                <div class="ival">{{ $order->carrier ?: '—' }}</div>
                @if ($carrierTypeLabel)
                <div class="isub">{{ $carrierTypeLabel }}</div>
                @endif
            </td>
            <td style="width:25%;">
                <div class="ibl">Tracking / Waybill</div>
                <div class="ival" style="font-size:10px;">
                    {{ $order->tracking_number ?: ($order->container_number ?: '—') }}
                </div>
                @if ($order->tracking_number && $order->container_number)
                <div class="isub">Container: {{ $order->container_number }}</div>
                @endif
            </td>
            <td style="width:25%;">
                <div class="ibl">Delivery Terms</div>
                <div class="ival" style="font-size:10px;">{{ $incotermLabel }}</div>
            </td>
            <td style="width:25%;">
                <div class="ibl">Shipment Status</div>
                @if ($latestEvent)
                <div class="ival" style="font-size:10px;">{{ $latestEvent->status_label }}</div>
                @if ($latestEvent->location)
                <div class="isub">{{ $latestEvent->location }}</div>
                @endif
                @else
                <div class="ival" style="color:#aaa;font-size:10px;">No events</div>
                @endif
            </td>
        </tr>
    </table>

    {{-- Items --}}
    <table class="items-table">
        <thead>
            <tr>
                <th style="width:26px;">#</th>
                <th>Description</th>
                <th style="width:100px;">Size</th>
                <th class="c" style="width:60px;">Season</th>
                <th class="c" style="width:60px;">Load/Speed</th>
                <th class="c" style="width:50px;">Qty</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->items as $i => $item)
            @php
                $product    = $item->product ?? null;
                $season     = $product?->season;
                $loadSpeed  = ($product?->load_index && $product?->speed_rating)
                    ? $product->load_index . $product->speed_rating : null;
                $seasonLabel = match(strtolower((string) $season)) {
                    'summer'    => 'Summer',
                    'winter'    => 'Winter',
                    'all_season', 'all-season', 'allseason' => 'All Season',
                    default     => $season ? ucfirst($season) : '—',
                };
            @endphp
            <tr>
                <td class="c" style="color:#888;">{{ $i + 1 }}</td>
                <td>
                    <strong>{{ $item->brand }}</strong> &mdash; {{ $item->name }}
                    @if ($item->sku)
                    <div class="item-sub">SKU: {{ $item->sku }}</div>
                    @endif
                </td>
                <td>{{ $item->size ?: '—' }}</td>
                <td class="c">{{ $seasonLabel }}</td>
                <td class="c">{{ $loadSpeed ?: '—' }}</td>
                <td class="c">
                    <div class="qty-num">{{ number_format($item->quantity, 2, '.', ',') }}</div>
                    <div class="qty-unit">pcs</div>
                </td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr style="background:#f0f0f0;">
                <td colspan="5" class="r" style="padding:7px 9px;font-size:10px;font-weight:700;border:1px solid #d0d0d0;">Total units delivered</td>
                <td class="c" style="padding:7px 9px;font-weight:700;border:1px solid #d0d0d0;">{{ $totalQty }}</td>
            </tr>
        </tfoot>
    </table>

    {{-- Receipt confirmation --}}
    <div style="border:1px solid #d9d9d9;padding:12px 14px;font-size:11px;line-height:1.7;margin-bottom:12px;background:#fffde7;">
        <div style="font-weight:700;margin-bottom:4px;">Customer Receipt Confirmation</div>
        By signing below, the receiver confirms that the goods described in this delivery note
        for order <strong>{{ $order->ref }}</strong> have been received in full and in acceptable condition,
        unless noted in writing at the time of delivery.
        @if ($order->is_reverse_charge)
        <div style="margin-top:6px;color:#c62828;font-size:10px;">
            This delivery is subject to reverse-charge VAT. The receiver is responsible for
            self-accounting of VAT in the country of receipt.
        </div>
        @endif
    </div>

    {{-- EU Entry Certificate notice --}}
    @if ($order->is_reverse_charge)
    <div style="border:1px solid #ccc;background:#f5f5f5;padding:10px 12px;font-size:10px;color:#333;line-height:1.6;margin-bottom:12px;">
        <strong>EU Entry Certificate Required (Gelangensbestätigung)</strong><br>
        This delivery involves a zero-rated intra-Community supply subject to reverse charge (§ 4 Nr. 1b UStG / Art. 138 MwStSystRL).
        The buyer is required to complete and return an EU Entry Certificate confirming that the goods have arrived
        in the destination EU member state.
    </div>
    @endif

    {{-- Signature blocks --}}
    <table class="sig-table">
        <tr>
            <td style="width:50%;padding-right:10px;">
                <div class="sig-box">
                    <div class="sig-lbl">Issued by Okelcor GmbH</div>
                    <div class="sig-sub">Authorised representative</div>
                    <div class="sig-line"></div>
                    <div class="sig-caption">Name / Date / Stamp</div>
                </div>
            </td>
            <td style="width:50%;">
                <div class="sig-box">
                    <div class="sig-lbl">Received by Customer</div>
                    <div class="sig-sub">I confirm receipt of the above goods</div>
                    <div class="sig-line"></div>
                    <div class="sig-caption">Name / Date / Signature</div>
                </div>
            </td>
        </tr>
    </table>

    {{-- Footer --}}
    @include('pdf.partials._footer')

</div>
</body>
</html>
