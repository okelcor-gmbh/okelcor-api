<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Delivery Note {{ $document->number }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12px;
            color: #171a20;
            background: #ffffff;
        }

        .page { padding: 40px 48px; }

        /* ── Header ─────────────────────────────────────────────────────── */
        .header-table {
            width: 100%;
            border-bottom: 3px solid #f4511e;
            padding-bottom: 18px;
            margin-bottom: 18px;
        }
        .header-table td { vertical-align: top; }
        .brand {
            font-size: 22px;
            font-weight: 700;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: #171a20;
        }
        .brand-sub { font-size: 11px; color: #5c5e62; margin-top: 3px; }
        .doc-label {
            font-size: 20px;
            font-weight: 700;
            color: #171a20;
            text-align: right;
        }
        .doc-number { font-size: 13px; color: #5c5e62; text-align: right; margin-top: 4px; }

        /* ── Section title ───────────────────────────────────────────────── */
        .section-title {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #9e9e9e;
            margin-bottom: 5px;
        }

        /* ── Info blocks (two-column) ────────────────────────────────────── */
        .meta-table { width: 100%; margin-bottom: 20px; border-collapse: collapse; }
        .meta-table td { vertical-align: top; padding: 0; width: 50%; }
        .meta-value { font-size: 12px; color: #171a20; line-height: 1.7; }
        .meta-value strong { font-weight: 700; }

        .detail-inner { margin-left: auto; border-collapse: collapse; }
        .detail-inner td { padding: 2px 0; font-size: 11px; }
        .detail-inner .lbl { color: #5c5e62; padding-right: 12px; white-space: nowrap; }
        .detail-inner .val { font-weight: 700; color: #171a20; }

        /* ── Delivery address block ──────────────────────────────────────── */
        .delivery-block {
            background: #f9f9f9;
            border-left: 3px solid #f4511e;
            padding: 12px 14px;
            margin-bottom: 20px;
            font-size: 11px;
            line-height: 1.7;
        }
        .delivery-block .delivery-title {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            color: #9e9e9e;
            margin-bottom: 6px;
        }

        /* ── Shipment info bar ───────────────────────────────────────────── */
        .shipment-bar {
            width: 100%;
            border-collapse: collapse;
            background: #f5f5f5;
            margin-bottom: 20px;
        }
        .shipment-bar td {
            padding: 10px 14px;
            vertical-align: top;
            width: 25%;
            font-size: 11px;
        }
        .shipment-bar .bar-lbl {
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            color: #9e9e9e;
            margin-bottom: 3px;
        }
        .shipment-bar .bar-val {
            font-size: 12px;
            font-weight: 700;
            color: #171a20;
        }
        .shipment-bar .bar-val-light {
            font-size: 11px;
            color: #5c5e62;
        }

        /* ── Items table ─────────────────────────────────────────────────── */
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .items-table thead tr { background-color: #171a20; }
        .items-table th {
            padding: 9px 10px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            color: #ffffff;
            text-align: left;
        }
        .items-table th.center { text-align: center; }
        .items-table tbody tr { background-color: #ffffff; }
        .items-table tbody tr.alt { background-color: #fafafa; }
        .items-table td {
            padding: 9px 10px;
            font-size: 11px;
            color: #171a20;
            border-bottom: 1px solid #eeeeee;
            vertical-align: middle;
        }
        .items-table td.center { text-align: center; }
        .item-sub { font-size: 10px; color: #5c5e62; margin-top: 1px; }
        .total-row td {
            background: #f5f5f5;
            font-weight: 700;
            font-size: 12px;
            border-top: 2px solid #dddddd;
        }

        /* ── Receipt confirmation box ────────────────────────────────────── */
        .receipt-box {
            border: 1px solid #e0e0e0;
            padding: 14px 16px;
            margin-bottom: 20px;
            background: #fffde7;
            font-size: 11px;
            line-height: 1.7;
            color: #333;
        }
        .receipt-box .receipt-title {
            font-size: 11px;
            font-weight: 700;
            color: #171a20;
            margin-bottom: 6px;
        }
        .receipt-box .receipt-line {
            font-size: 10px;
            color: #5c5e62;
        }

        /* ── Reverse-charge notice ───────────────────────────────────────── */
        .rc-notice {
            border: 1px solid #f4511e;
            padding: 10px 14px;
            margin-bottom: 20px;
            background: #fff3f0;
            font-size: 10px;
            color: #171a20;
            line-height: 1.6;
        }
        .rc-notice strong { color: #f4511e; }

        /* ── Signature blocks ────────────────────────────────────────────── */
        .sig-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .sig-table td { padding: 0; vertical-align: top; width: 50%; }
        .sig-box {
            border: 1px solid #dddddd;
            padding: 14px 16px;
            height: 90px;
        }
        .sig-box-left { margin-right: 12px; }
        .sig-title {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            color: #9e9e9e;
            margin-bottom: 4px;
        }
        .sig-sub {
            font-size: 9px;
            color: #bdbdbd;
            margin-bottom: 2px;
        }
        .sig-line {
            border-bottom: 1px solid #bbbbbb;
            margin-top: 46px;
        }
        .sig-label { font-size: 9px; color: #9e9e9e; margin-top: 4px; }

        /* ── Footer ─────────────────────────────────────────────────────── */
        .footer {
            border-top: 1px solid #eeeeee;
            padding-top: 12px;
            font-size: 10px;
            color: #9e9e9e;
            line-height: 1.7;
        }
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
            'bus'  => 'Bus / Courier',
        ];
        $carrierTypeLabel = $carrierTypeMap[$order->carrier_type ?? ''] ?? ucfirst($order->carrier_type ?? '—');

        // Incoterms
        if ($quote?->incoterm) {
            $incoterm = strtoupper($quote->incoterm);
            $incotermDisplay = match($incoterm) {
                'FOB' => 'FOB Germany (Incoterms 2020)',
                'CIF' => 'CIF (Incoterms 2020) — freight & insurance included',
                'EXW' => 'EXW (Incoterms 2020) — ex works',
                'DAP' => 'DAP (Incoterms 2020) — delivered at place',
                'DDP' => 'DDP (Incoterms 2020) — delivered duty paid',
                default => $incoterm . ' (Incoterms 2020)',
            };
        } else {
            $incotermDisplay = config('payment.bank_transfer.delivery_term', 'FOB Germany (Incoterms 2020)');
        }

        // Latest shipment event
        $latestEvent = $order->shipmentEvents?->sortByDesc('event_date')->first();

        // Actual delivery date — prefer eta, fall back to estimated_delivery
        $deliveryDate = $order->eta ?? $order->estimated_delivery;
    @endphp

    {{-- ── Header ─────────────────────────────────────────────────────── --}}
    <table class="header-table">
        <tr>
            <td>
                <div class="brand">Okelcor</div>
                <div class="brand-sub">support@okelcor.com &mdash; okelcor.com</div>
            </td>
            <td>
                <div class="doc-label">Delivery Note</div>
                <div class="doc-number">{{ $document->number }}</div>
            </td>
        </tr>
    </table>

    {{-- ── Seller / Consignee ──────────────────────────────────────────── --}}
    <table class="meta-table">
        <tr>
            <td>
                <div class="section-title">Seller / Consignor</div>
                <div class="meta-value" style="margin-bottom: 14px;">
                    <strong>Okelcor GmbH</strong><br>
                    support@okelcor.com &mdash; okelcor.com
                </div>

                <div class="section-title">Buyer / Consignee</div>
                <div class="meta-value">
                    <strong>{{ $order->customer_name }}</strong><br>
                    @if ($order->customer_phone){{ $order->customer_phone }}<br>@endif
                    {{ $order->customer_email }}<br>
                    {{ $order->address }}<br>
                    {{ $order->city }}@if ($order->postal_code), {{ $order->postal_code }}@endif<br>
                    {{ $order->country }}
                    @if ($order->vat_number)
                    <br><span style="color:#5c5e62;">VAT No.: </span><strong>{{ $order->vat_number }}</strong>
                    @endif
                </div>
            </td>
            <td style="text-align: right;">
                <div class="section-title">Document Details</div>
                <table class="detail-inner">
                    <tr>
                        <td class="lbl">Delivery note no.</td>
                        <td class="val">{{ $document->number }}</td>
                    </tr>
                    <tr>
                        <td class="lbl">Date issued</td>
                        <td class="val">{{ $document->issued_at?->format('d M Y') }}</td>
                    </tr>
                    <tr>
                        <td class="lbl">Order reference</td>
                        <td class="val">{{ $order->ref }}</td>
                    </tr>
                    @if ($invoice?->invoice_number)
                    <tr>
                        <td class="lbl">Invoice reference</td>
                        <td class="val">{{ $invoice->invoice_number }}</td>
                    </tr>
                    @endif
                    <tr>
                        <td class="lbl">Delivery terms</td>
                        <td class="val">{{ $incotermDisplay }}</td>
                    </tr>
                    @if ($deliveryDate)
                    <tr>
                        <td class="lbl">Delivery date</td>
                        <td class="val">
                            @if ($deliveryDate instanceof \Carbon\Carbon)
                                {{ $deliveryDate->format('d M Y') }}
                            @else
                                {{ \Carbon\Carbon::parse($deliveryDate)->format('d M Y') }}
                            @endif
                        </td>
                    </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    {{-- ── Delivery address bar ────────────────────────────────────────── --}}
    <div class="delivery-block">
        <div class="delivery-title">Delivery Address</div>
        <strong>{{ $order->customer_name }}</strong><br>
        {{ $order->address }}<br>
        {{ $order->city }}@if ($order->postal_code), {{ $order->postal_code }}@endif &mdash; {{ $order->country }}
        @if ($order->vat_number)
        &nbsp;&nbsp;|&nbsp;&nbsp; VAT: {{ $order->vat_number }}
        @endif
    </div>

    {{-- ── Shipment info bar ───────────────────────────────────────────── --}}
    <table class="shipment-bar">
        <tr>
            <td>
                <div class="bar-lbl">Carrier</div>
                <div class="bar-val">{{ $order->carrier ?: '—' }}</div>
            </td>
            <td>
                <div class="bar-lbl">Transport mode</div>
                <div class="bar-val">{{ $carrierTypeLabel ?: '—' }}</div>
            </td>
            <td>
                <div class="bar-lbl">Tracking / Waybill</div>
                <div class="bar-val">
                    {{ $order->tracking_number ?: ($order->container_number ?: '—') }}
                    @if ($order->tracking_number && $order->container_number)
                    <div class="bar-val-light">Container: {{ $order->container_number }}</div>
                    @endif
                </div>
            </td>
            <td>
                <div class="bar-lbl">Shipment status</div>
                @if ($latestEvent)
                <div class="bar-val" style="font-size:11px;">{{ $latestEvent->status_label }}</div>
                @if ($latestEvent->location)
                <div class="bar-val-light">{{ $latestEvent->location }}</div>
                @endif
                @if ($latestEvent->event_date)
                <div class="bar-val-light">
                    @if ($latestEvent->event_date instanceof \Carbon\Carbon)
                        {{ $latestEvent->event_date->format('d M Y') }}
                    @else
                        {{ \Carbon\Carbon::parse($latestEvent->event_date)->format('d M Y') }}
                    @endif
                </div>
                @endif
                @else
                <div class="bar-val" style="font-size:11px;color:#9e9e9e;">No events recorded</div>
                @endif
            </td>
        </tr>
    </table>

    {{-- ── Items table ─────────────────────────────────────────────────── --}}
    <table class="items-table">
        <thead>
            <tr>
                <th style="width:32px;">#</th>
                <th>Description</th>
                <th style="width:110px;">Size</th>
                <th style="width:60px;" class="center">Season</th>
                <th style="width:60px;" class="center">Load/Speed</th>
                <th style="width:50px;" class="center">Qty</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->items as $i => $item)
            @php
                $product   = $item->product ?? null;
                $season    = $product?->season;
                $loadSpeed = null;
                if ($product?->load_index && $product?->speed_rating) {
                    $loadSpeed = $product->load_index . $product->speed_rating;
                }
                $seasonLabel = match(strtolower((string) $season)) {
                    'summer'      => 'Summer',
                    'winter'      => 'Winter',
                    'all_season',
                    'all-season',
                    'allseason'   => 'All Season',
                    default       => $season ? ucfirst($season) : '—',
                };
                $rowClass = ($i % 2 === 1) ? 'alt' : '';
            @endphp
            <tr class="{{ $rowClass }}">
                <td class="center" style="color:#9e9e9e;">{{ $i + 1 }}</td>
                <td>
                    <strong>{{ $item->brand }}</strong> — {{ $item->name }}
                    @if ($item->sku)
                    <div class="item-sub">SKU: {{ $item->sku }}</div>
                    @endif
                </td>
                <td>{{ $item->size ?: '—' }}</td>
                <td class="center">{{ $seasonLabel }}</td>
                <td class="center">{{ $loadSpeed ?: '—' }}</td>
                <td class="center"><strong>{{ $item->quantity }}</strong></td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td colspan="5" style="text-align:right; padding-right:12px;">Total units delivered</td>
                <td class="center">{{ $totalQty }}</td>
            </tr>
        </tfoot>
    </table>

    {{-- ── Receipt confirmation statement ─────────────────────────────── --}}
    <div class="receipt-box">
        <div class="receipt-title">Customer Receipt Confirmation</div>
        <div class="receipt-line">
            By signing below, the receiver confirms that the goods described in this delivery note
            for order <strong>{{ $order->ref }}</strong> have been received in full and in acceptable condition,
            unless noted in writing at the time of delivery.
        </div>
        @if ($order->is_reverse_charge)
        <div class="receipt-line" style="margin-top:6px; color:#c62828;">
            This delivery is subject to reverse-charge VAT. The receiver is responsible for
            self-accounting of VAT in the country of receipt.
        </div>
        @endif
    </div>

    {{-- ── Reverse-charge EU Entry Certificate notice ──────────────────── --}}
    @if ($order->is_reverse_charge)
    <div class="rc-notice">
        <strong>EU Entry Certificate Required</strong><br>
        This delivery involves a zero-rated intra-Community supply subject to reverse charge (§ 4 Nr. 1b UStG / Art. 138 MwStSystRL).
        The buyer is required to complete and return an <strong>EU Entry Certificate (Gelangensbestätigung)</strong>
        confirming that the goods have arrived in the destination EU member state.
        The final invoice remains provisional until the EU Entry Certificate is acknowledged by Okelcor GmbH.
    </div>
    @endif

    {{-- ── Signature blocks ─────────────────────────────────────────────── --}}
    <table class="sig-table">
        <tr>
            <td>
                <div class="sig-box sig-box-left">
                    <div class="sig-title">Issued by Okelcor GmbH</div>
                    <div class="sig-sub">Authorised representative</div>
                    <div class="sig-line"></div>
                    <div class="sig-label">Name &nbsp; / &nbsp; Date &nbsp; / &nbsp; Stamp</div>
                </div>
            </td>
            <td>
                <div class="sig-box">
                    <div class="sig-title">Received by Customer</div>
                    <div class="sig-sub">I confirm receipt of the above goods</div>
                    <div class="sig-line"></div>
                    <div class="sig-label">Name &nbsp; / &nbsp; Date &nbsp; / &nbsp; Signature</div>
                </div>
            </td>
        </tr>
    </table>

    {{-- ── Footer ─────────────────────────────────────────────────────── --}}
    <div class="footer">
        <p>Delivery note issued by Okelcor GmbH &mdash; {{ $document->number }} &mdash; Order {{ $order->ref }}</p>
        <p style="margin-top:4px;">support@okelcor.com &mdash; okelcor.com &mdash; Generated: {{ $document->issued_at?->format('d M Y H:i') }} UTC</p>
    </div>

</div>
</body>
</html>
