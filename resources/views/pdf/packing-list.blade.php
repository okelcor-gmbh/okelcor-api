<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Packing List {{ $document->number }}</title>
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
    <div class="doc-title">Packing List {{ $document->number }}</div>
    <p class="intro-p" style="margin-bottom:4px;">
        Order reference: <strong>{{ $order->ref }}</strong>
        @if ($invoice?->invoice_number)
        &nbsp;&mdash;&nbsp; Invoice: <strong>{{ $invoice->invoice_number }}</strong>
        @endif
    </p>

    <div style="height:14px;"></div>

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
                <div class="ibl">Latest Status</div>
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
                <td colspan="5" class="r" style="padding:7px 9px;font-size:10px;font-weight:700;border:1px solid #d0d0d0;">Total units</td>
                <td class="c" style="padding:7px 9px;font-weight:700;border:1px solid #d0d0d0;">{{ $totalQty }}</td>
            </tr>
        </tfoot>
    </table>

    {{-- Packaging & weight / Note --}}
    <table style="width:100%;border-collapse:collapse;margin-bottom:16px;">
        <tr>
            <td style="width:50%;vertical-align:top;padding-right:10px;">
                <div class="sec-lbl" style="margin-bottom:6px;">Packaging &amp; Weight</div>
                <table style="width:100%;border-collapse:collapse;border:1px solid #d9d9d9;">
                    <tr>
                        <td style="padding:5px 8px;font-size:10px;border-bottom:1px solid #e8e8e8;color:#444;">Number of pallets</td>
                        <td style="padding:5px 8px;font-size:10px;border-bottom:1px solid #e8e8e8;color:#aaa;text-align:right;">____________</td>
                    </tr>
                    <tr>
                        <td style="padding:5px 8px;font-size:10px;border-bottom:1px solid #e8e8e8;color:#444;">Number of packages</td>
                        <td style="padding:5px 8px;font-size:10px;border-bottom:1px solid #e8e8e8;color:#aaa;text-align:right;">____________</td>
                    </tr>
                    <tr>
                        <td style="padding:5px 8px;font-size:10px;border-bottom:1px solid #e8e8e8;color:#444;">Gross weight (kg)</td>
                        <td style="padding:5px 8px;font-size:10px;border-bottom:1px solid #e8e8e8;color:#aaa;text-align:right;">____________</td>
                    </tr>
                    <tr>
                        <td style="padding:5px 8px;font-size:10px;border-bottom:1px solid #e8e8e8;color:#444;">Net weight (kg)</td>
                        <td style="padding:5px 8px;font-size:10px;border-bottom:1px solid #e8e8e8;color:#aaa;text-align:right;">____________</td>
                    </tr>
                    <tr>
                        <td style="padding:5px 8px;font-size:10px;color:#444;">Total units</td>
                        <td style="padding:5px 8px;font-size:11px;font-weight:700;text-align:right;">{{ $totalQty }}</td>
                    </tr>
                </table>
            </td>
            <td style="width:50%;vertical-align:top;">
                <div class="sec-lbl" style="margin-bottom:6px;">Note to Carrier / Customs</div>
                <div style="border:1px solid #d9d9d9;padding:10px 12px;font-size:10px;line-height:1.7;color:#333;background:#fffde7;">
                    This packing list accompanies order <strong>{{ $order->ref }}</strong>.<br>
                    Goods described are automotive tyres for commercial use.<br>
                    Country of export: Germany.<br>
                    @if ($order->is_reverse_charge)
                    Reverse charge VAT — liability transfers to recipient.<br>
                    @endif
                    Delivery terms: {{ $incotermLabel }}.
                </div>
            </td>
        </tr>
    </table>

    {{-- Signature blocks --}}
    <table class="sig-table">
        <tr>
            <td style="width:50%;padding-right:10px;">
                <div class="sig-box">
                    <div class="sig-lbl">Warehouse Release Signature</div>
                    <div class="sig-line"></div>
                    <div class="sig-caption">Name / Date / Stamp</div>
                </div>
            </td>
            <td style="width:50%;">
                <div class="sig-box">
                    <div class="sig-lbl">Customer / Receiver Acknowledgement</div>
                    <div class="sig-line"></div>
                    <div class="sig-caption">Name / Date / Stamp</div>
                </div>
            </td>
        </tr>
    </table>

    {{-- Footer --}}
    @include('pdf.partials._footer')

</div>
</body>
</html>
