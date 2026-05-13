<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Commercial Invoice {{ $document->number }}</title>
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
            border-bottom: 3px solid #1a237e;
            padding-bottom: 18px;
            margin-bottom: 14px;
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
            color: #1a237e;
            text-align: right;
        }
        .doc-number { font-size: 13px; color: #5c5e62; text-align: right; margin-top: 4px; }

        /* ── Export notice banner ────────────────────────────────────────── */
        .export-notice {
            background: #e8eaf6;
            border-left: 4px solid #1a237e;
            padding: 8px 14px;
            margin-bottom: 20px;
            font-size: 11px;
            color: #3949ab;
            line-height: 1.5;
        }
        .export-notice strong { color: #1a237e; }

        /* ── Section title ───────────────────────────────────────────────── */
        .section-title {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #9e9e9e;
            margin-bottom: 5px;
        }

        /* ── Two-column meta block ───────────────────────────────────────── */
        .meta-table { width: 100%; margin-bottom: 20px; border-collapse: collapse; }
        .meta-table td { vertical-align: top; padding: 0; width: 50%; }
        .meta-value { font-size: 12px; color: #171a20; line-height: 1.7; }
        .meta-value strong { font-weight: 700; }

        .detail-inner { margin-left: auto; border-collapse: collapse; }
        .detail-inner td { padding: 2px 0; font-size: 11px; }
        .detail-inner .lbl { color: #5c5e62; padding-right: 12px; white-space: nowrap; }
        .detail-inner .val { font-weight: 700; color: #171a20; }

        /* ── Trade terms bar ─────────────────────────────────────────────── */
        .terms-bar {
            width: 100%;
            border-collapse: collapse;
            background: #f3f4f8;
            border: 1px solid #c5cae9;
            margin-bottom: 20px;
        }
        .terms-bar td {
            padding: 10px 14px;
            vertical-align: top;
            font-size: 11px;
            border-right: 1px solid #c5cae9;
        }
        .terms-bar td:last-child { border-right: none; }
        .terms-bar .bar-lbl {
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            color: #5c5e8a;
            margin-bottom: 3px;
        }
        .terms-bar .bar-val {
            font-size: 12px;
            font-weight: 700;
            color: #171a20;
        }
        .terms-bar .bar-val-light {
            font-size: 10px;
            color: #5c5e62;
            margin-top: 2px;
        }

        /* ── Items table ─────────────────────────────────────────────────── */
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .items-table thead tr { background-color: #1a237e; }
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
        .items-table th.right  { text-align: right; }
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
        .items-table td.right  { text-align: right; }
        .item-sub { font-size: 10px; color: #5c5e62; margin-top: 1px; }

        .total-row td {
            background: #f3f4f8;
            font-weight: 700;
            font-size: 12px;
            border-top: 2px solid #c5cae9;
        }

        /* ── Totals ──────────────────────────────────────────────────────── */
        .totals-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .totals-table td { padding: 5px 10px; font-size: 12px; }
        .totals-table .lbl { color: #5c5e62; text-align: right; width: 75%; }
        .totals-table .amt { color: #171a20; text-align: right; font-weight: 700; }
        .totals-table .grand-total td {
            border-top: 2px solid #1a237e;
            padding-top: 10px;
            font-size: 14px;
            color: #1a237e;
        }
        .totals-table .grand-total .lbl { color: #1a237e; }

        /* ── Declaration box ─────────────────────────────────────────────── */
        .declaration-box {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .declaration-box td { padding: 12px 16px; font-size: 11px; line-height: 1.6; }
        .declaration-rc {
            background: #fff8e1;
            border: 1px solid #ffe082;
            color: #5c5e62;
        }
        .declaration-rc strong { color: #f57f17; }
        .declaration-exempt {
            background: #e8f5e9;
            border: 1px solid #a5d6a7;
            color: #5c5e62;
        }
        .declaration-exempt strong { color: #2e7d32; }
        .declaration-standard {
            background: #f5f5f5;
            border: 1px solid #e0e0e0;
            color: #5c5e62;
        }

        /* ── Signature blocks ────────────────────────────────────────────── */
        .sig-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .sig-table td { padding: 0; vertical-align: top; width: 50%; }
        .sig-box {
            border: 1px solid #c5cae9;
            padding: 14px 16px;
            height: 80px;
            background: #fafafa;
        }
        .sig-box-left { margin-right: 12px; }
        .sig-title {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            color: #5c5e8a;
            margin-bottom: 4px;
        }
        .sig-line {
            border-bottom: 1px solid #bbbbbb;
            margin-top: 38px;
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
        ];
        $carrierTypeLabel = $carrierTypeMap[$order->carrier_type ?? ''] ?? ucfirst($order->carrier_type ?? '—');

        // Incoterms — prefer QuoteRequest, fall back to config default
        if ($quote?->incoterm) {
            $incoterm = strtoupper($quote->incoterm);
            $incotermDisplay = match($incoterm) {
                'FOB' => 'Incoterms 2020: FOB Germany',
                'CIF' => 'Incoterms 2020: CIF — freight & insurance to destination port',
                'EXW' => 'Incoterms 2020: EXW — ex works',
                'DAP' => 'Incoterms 2020: DAP — delivered at place',
                'DDP' => 'Incoterms 2020: DDP — delivered duty paid',
                default => 'Incoterms 2020: ' . $incoterm,
            };
        } else {
            $incotermDisplay = config('payment.bank_transfer.delivery_term', 'Incoterms 2020: FOB Germany');
        }

        $paymentMethodLabel = match($order->payment_method) {
            'bank_transfer' => 'Bank Transfer (Wire / SEPA)',
            'stripe'        => 'Online Payment (Stripe)',
            default         => ucfirst(str_replace('_', ' ', $order->payment_method ?? 'Not specified')),
        };

        $paymentStatusLabel = match($order->payment_status) {
            'paid'     => 'Paid',
            'pending'  => 'Pending',
            'failed'   => 'Failed',
            'refunded' => 'Refunded',
            default    => ucfirst($order->payment_status ?? '—'),
        };

        $trackingRef = $order->tracking_number ?: ($order->container_number ?: null);
    @endphp

    {{-- ── Header ─────────────────────────────────────────────────────── --}}
    <table class="header-table">
        <tr>
            <td>
                <div class="brand">Okelcor</div>
                <div class="brand-sub">support@okelcor.com &mdash; okelcor.com</div>
            </td>
            <td>
                <div class="doc-label">Commercial Invoice</div>
                <div class="doc-number">{{ $document->number }}</div>
            </td>
        </tr>
    </table>

    {{-- ── Export notice ───────────────────────────────────────────────── --}}
    <div class="export-notice">
        <strong>COMMERCIAL INVOICE — EXPORT / CUSTOMS DOCUMENT.</strong>
        This document is issued for customs, export, and trade purposes only.
        It does <strong>not</strong> replace the final tax/accounting invoice (INV-YYYY-NNNN).
    </div>

    {{-- ── Seller / Buyer | Document details ─────────────────────────── --}}
    <table class="meta-table">
        <tr>
            <td>
                {{-- Seller --}}
                <div class="section-title">Seller / Consignor</div>
                <div class="meta-value" style="margin-bottom: 14px;">
                    <strong>Okelcor GmbH</strong><br>
                    support@okelcor.com<br>
                    okelcor.com
                </div>

                {{-- Buyer --}}
                <div class="section-title">Buyer / Consignee</div>
                <div class="meta-value">
                    <strong>{{ $order->customer_name }}</strong><br>
                    {{ $order->customer_email }}<br>
                    @if ($order->customer_phone){{ $order->customer_phone }}<br>@endif
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
                        <td class="lbl">CI number</td>
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
                        <td class="lbl">Related invoice</td>
                        <td class="val">{{ $invoice->invoice_number }}</td>
                    </tr>
                    @endif
                    <tr>
                        <td class="lbl">Payment method</td>
                        <td class="val">{{ $paymentMethodLabel }}</td>
                    </tr>
                    <tr>
                        <td class="lbl">Payment status</td>
                        <td class="val">{{ $paymentStatusLabel }}</td>
                    </tr>
                    <tr>
                        <td class="lbl">Currency</td>
                        <td class="val">EUR (&euro;)</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- ── Trade terms bar ────────────────────────────────────────────── --}}
    <table class="terms-bar">
        <tr>
            <td style="width:30%;">
                <div class="bar-lbl">Delivery / Trade Terms</div>
                <div class="bar-val" style="font-size:11px;">{{ $incotermDisplay }}</div>
            </td>
            <td style="width:15%;">
                <div class="bar-lbl">Country of Export</div>
                <div class="bar-val">Germany</div>
            </td>
            <td style="width:20%;">
                <div class="bar-lbl">Country of Destination</div>
                <div class="bar-val">{{ $order->country ?: '—' }}</div>
            </td>
            <td style="width:20%;">
                <div class="bar-lbl">Carrier / Transport Mode</div>
                <div class="bar-val" style="font-size:11px;">
                    {{ $order->carrier ?: ($carrierTypeLabel !== '—' ? $carrierTypeLabel : '—') }}
                </div>
                @if ($order->carrier && $carrierTypeLabel !== '—')
                <div class="bar-val-light">{{ $carrierTypeLabel }}</div>
                @endif
            </td>
            <td style="width:15%;">
                <div class="bar-lbl">Tracking / Waybill</div>
                <div class="bar-val" style="font-size:11px;">{{ $trackingRef ?: '—' }}</div>
                @if ($order->tracking_number && $order->container_number)
                <div class="bar-val-light">Container: {{ $order->container_number }}</div>
                @endif
            </td>
        </tr>
    </table>

    {{-- ── Items table ─────────────────────────────────────────────────── --}}
    <table class="items-table">
        <thead>
            <tr>
                <th style="width:28px;">#</th>
                <th>Product Description</th>
                <th style="width:90px;">Tyre Size</th>
                <th style="width:70px;" class="center">HS Code</th>
                <th style="width:70px;" class="center">Origin</th>
                <th style="width:40px;" class="center">Qty</th>
                <th style="width:80px;" class="right">Unit Value</th>
                <th style="width:90px;" class="right">Total Value</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->items as $i => $item)
            @php $rowClass = ($i % 2 === 1) ? 'alt' : ''; @endphp
            <tr class="{{ $rowClass }}">
                <td class="center" style="color:#9e9e9e;">{{ $i + 1 }}</td>
                <td>
                    <strong>{{ $item->brand }}</strong> &mdash; {{ $item->name }}
                    @if ($item->sku)
                    <div class="item-sub">SKU: {{ $item->sku }}</div>
                    @endif
                </td>
                <td>{{ $item->size ?: '—' }}</td>
                <td class="center" style="color:#9e9e9e; font-style:italic;">—</td>
                <td class="center" style="color:#9e9e9e; font-style:italic;">Germany</td>
                <td class="center"><strong>{{ $item->quantity }}</strong></td>
                <td class="right">&euro;{{ number_format((float) $item->unit_price, 2) }}</td>
                <td class="right">&euro;{{ number_format((float) $item->line_total, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td colspan="5" style="text-align:right; padding-right:10px; color:#5c5e62;">Total units</td>
                <td class="center">{{ $totalQty }}</td>
                <td class="right" style="color:#5c5e62;"></td>
                <td class="right">&euro;{{ number_format((float) $order->subtotal, 2) }}</td>
            </tr>
        </tfoot>
    </table>

    {{-- ── Totals ───────────────────────────────────────────────────────── --}}
    <table class="totals-table">
        <tr>
            <td class="lbl">Subtotal (net)</td>
            <td class="amt">&euro;{{ number_format((float) $order->subtotal, 2) }}</td>
        </tr>
        @if ((float) ($order->discount_amount ?? 0) > 0)
        <tr>
            <td class="lbl">{{ $order->discount_label ?? 'Discount' }}</td>
            <td class="amt" style="color:#2e7d32;">&minus;&euro;{{ number_format((float) $order->discount_amount, 2) }}</td>
        </tr>
        @endif
        @if ((float) $order->delivery_cost > 0)
        <tr>
            <td class="lbl">Freight / Delivery</td>
            <td class="amt">&euro;{{ number_format((float) $order->delivery_cost, 2) }}</td>
        </tr>
        @endif
        @if ($order->tax_treatment !== null)
        <tr>
            @if ((float) $order->tax_amount > 0)
            <td class="lbl">VAT ({{ number_format((float) $order->tax_rate, 0) }}%) &mdash; Standard rate</td>
            <td class="amt">&euro;{{ number_format((float) $order->tax_amount, 2) }}</td>
            @elseif ($order->is_reverse_charge)
            <td class="lbl">VAT &mdash; Reverse Charge (0%) &mdash; Intra-community supply</td>
            <td class="amt">&euro;0.00</td>
            @else
            <td class="lbl">VAT &mdash; Exempt (0%) &mdash; Export outside EU</td>
            <td class="amt">&euro;0.00</td>
            @endif
        </tr>
        @endif
        <tr class="grand-total">
            <td class="lbl">Total Commercial Value (EUR)</td>
            <td class="amt">&euro;{{ number_format((float) $order->total, 2) }}</td>
        </tr>
    </table>

    {{-- ── VAT / Customs declaration ────────────────────────────────────── --}}
    @if ($order->is_reverse_charge)
    <table class="declaration-box">
        <tr>
            <td class="declaration-rc">
                <strong>Intra-Community Supply &mdash; Reverse Charge:</strong>
                VAT liability transfers to the recipient pursuant to Article 138 of Council Directive 2006/112/EC.
                The supply of goods is zero-rated for VAT purposes. The buyer is responsible for accounting for VAT
                in their member state of destination.
            </td>
        </tr>
    </table>
    @elseif ($order->tax_treatment === 'exempt')
    <table class="declaration-box">
        <tr>
            <td class="declaration-exempt">
                <strong>Export Outside the EU &mdash; VAT Exempt:</strong>
                This shipment constitutes an export outside the European Union and is exempt from VAT
                pursuant to &sect;6 UStG (German VAT Act). The goods are destined for a country outside
                the European customs territory.
            </td>
        </tr>
    </table>
    @else
    <table class="declaration-box">
        <tr>
            <td class="declaration-standard">
                <strong>Standard VAT Applied:</strong>
                This supply is subject to German VAT at
                {{ number_format((float) $order->tax_rate, 0) }}%.
                VAT amount: &euro;{{ number_format((float) $order->tax_amount, 2) }}.
            </td>
        </tr>
    </table>
    @endif

    {{-- ── Signature blocks ────────────────────────────────────────────── --}}
    <table class="sig-table">
        <tr>
            <td>
                <div class="sig-box sig-box-left">
                    <div class="sig-title">Authorised Signatory — Okelcor GmbH</div>
                    <div class="sig-line"></div>
                    <div class="sig-label">Name &nbsp;/&nbsp; Title &nbsp;/&nbsp; Date &nbsp;/&nbsp; Signature</div>
                </div>
            </td>
            <td>
                <div class="sig-box">
                    <div class="sig-title">Company Stamp</div>
                    <div class="sig-line"></div>
                    <div class="sig-label">Official stamp of Okelcor GmbH</div>
                </div>
            </td>
        </tr>
    </table>

    {{-- ── Footer ─────────────────────────────────────────────────────── --}}
    <div class="footer">
        <p>
            <strong>Commercial Invoice &mdash; for customs and export purposes only. This is not a tax invoice.</strong>
        </p>
        <p style="margin-top:4px;">
            {{ $document->number }} &mdash; Order {{ $order->ref }}
            @if ($invoice?->invoice_number) &mdash; Related Invoice: {{ $invoice->invoice_number }}@endif
            &mdash; Generated: {{ $document->issued_at?->format('d M Y') }}
        </p>
        <p style="margin-top:4px;">Okelcor GmbH &mdash; support@okelcor.com &mdash; okelcor.com</p>
    </div>

</div>
</body>
</html>
