<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Order Confirmation {{ $document->number }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12px;
            color: #171a20;
            background: #ffffff;
        }

        .page { padding: 40px 48px; }

        /* Header */
        .header-table {
            width: 100%;
            border-bottom: 3px solid #f4511e;
            padding-bottom: 20px;
            margin-bottom: 16px;
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
        .doc-proposal { font-size: 11px; color: #9e9e9e; text-align: right; margin-top: 3px; }

        /* Meta */
        .meta-table { width: 100%; margin-bottom: 24px; }
        .meta-table td { vertical-align: top; width: 50%; }
        .section-title {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #9e9e9e;
            margin-bottom: 6px;
        }
        .meta-value { font-size: 12px; color: #171a20; line-height: 1.7; }
        .meta-value strong { font-weight: 700; }

        /* Details side table */
        .detail-inner { margin-left: auto; border-collapse: collapse; }
        .detail-inner td { padding: 2px 0 2px 0; font-size: 11px; }
        .detail-inner .lbl { color: #5c5e62; padding-right: 12px; }
        .detail-inner .val { font-weight: 700; color: #171a20; }

        /* Terms block */
        .terms-block {
            background: #fafafa;
            border: 1px solid #e0e0e0;
            padding: 12px 14px;
            margin-bottom: 20px;
            font-size: 11px;
            line-height: 1.7;
        }
        .terms-block .section-title { margin-bottom: 4px; }

        /* Items table */
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
        .items-table thead tr { background-color: #f5f5f5; }
        .items-table th {
            padding: 9px 12px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            color: #5c5e62;
            text-align: left;
            border-bottom: 1px solid #dddddd;
        }
        .items-table th.right { text-align: right; }
        .items-table td {
            padding: 10px 12px;
            font-size: 12px;
            color: #171a20;
            border-bottom: 1px solid #eeeeee;
            vertical-align: top;
        }
        .items-table td.right { text-align: right; }
        .item-sub { font-size: 11px; color: #5c5e62; margin-top: 2px; }
        .item-qty-line { font-size: 11px; color: #5c5e62; margin-top: 3px; }

        /* Totals */
        .totals-table { width: 100%; border-collapse: collapse; margin-bottom: 28px; }
        .totals-table td { padding: 6px 12px; font-size: 12px; }
        .totals-table .lbl { color: #5c5e62; text-align: right; width: 75%; }
        .totals-table .amt { color: #171a20; text-align: right; font-weight: 700; }
        .totals-table .total-row td {
            border-top: 2px solid #dddddd;
            padding-top: 10px;
            font-size: 14px;
        }

        /* Bank transfer block */
        .bank-block {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 28px;
            border: 1px solid #e0e0e0;
            background-color: #fafafa;
        }
        .bank-block td { padding: 14px 16px; }
        .bank-inner { width: 100%; border-collapse: collapse; }
        .bank-inner td { padding: 3px 0; font-size: 11px; }
        .bank-inner .lbl { color: #5c5e62; width: 35%; padding-right: 12px; }
        .bank-inner .val { font-weight: 700; color: #171a20; }

        /* Footer */
        .footer {
            border-top: 1px solid #eeeeee;
            padding-top: 14px;
            font-size: 10px;
            color: #9e9e9e;
            line-height: 1.7;
        }
    </style>
</head>
<body>
<div class="page">

    <!-- Header -->
    <table class="header-table">
        <tr>
            <td>
                <div class="brand">Okelcor</div>
                <div class="brand-sub">support@okelcor.com &mdash; okelcor.com</div>
            </td>
            <td>
                <div class="doc-label">Order Confirmation</div>
                <div class="doc-number">{{ $document->number }}</div>
                @if ($quote?->ref_number)
                <div class="doc-proposal">by Proposal {{ $quote->ref_number }}</div>
                @endif
            </td>
        </tr>
    </table>

    <!-- Bill to / Document details -->
    <table class="meta-table">
        <tr>
            <td>
                <!-- Seller -->
                <div class="section-title">Seller / Consignor</div>
                <div class="meta-value" style="margin-bottom:14px;">
                    <strong>Okelcor GmbH</strong><br>
                    support@okelcor.com<br>
                    okelcor.com
                </div>

                <!-- Buyer -->
                <div class="section-title">Buyer / Consignee</div>
                <div class="meta-value">
                    <strong>{{ $order->customer_name }}</strong><br>
                    @if ($quote?->company_name && $quote->company_name !== $order->customer_name)
                    {{ $quote->company_name }}<br>
                    @endif
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
                        <td class="lbl">Confirmation number</td>
                        <td class="val">{{ $document->number }}</td>
                    </tr>
                    @if ($quote?->ref_number)
                    <tr>
                        <td class="lbl">Proposal reference</td>
                        <td class="val">{{ $quote->ref_number }}</td>
                    </tr>
                    @endif
                    <tr>
                        <td class="lbl">Date issued</td>
                        <td class="val">{{ $document->issued_at?->format('d M Y') }}</td>
                    </tr>
                    <tr>
                        <td class="lbl">Order reference</td>
                        <td class="val">{{ $order->ref }}</td>
                    </tr>
                    <tr>
                        <td class="lbl">Payment method</td>
                        <td class="val">{{ $order->payment_method === 'bank_transfer' ? 'Bank Transfer' : 'Online Payment' }}</td>
                    </tr>
                    @php
                        $incoterm = $quote?->incoterm ?? null;
                        $incotermDisplay = match(strtoupper((string) $incoterm)) {
                            'FOB'   => 'Incoterms 2020: FOB Germany',
                            'CIF'   => 'Incoterms 2020: CIF — freight and insurance to destination port',
                            'EXW'   => 'Incoterms 2020: EXW — Ex Works',
                            'DAP'   => 'Incoterms 2020: DAP — Delivered at Place',
                            default => $incoterm ? 'Incoterms 2020: ' . strtoupper($incoterm) : config('payment.bank_transfer.delivery_term', 'See payment terms'),
                        };
                    @endphp
                    <tr>
                        <td class="lbl">Delivery terms</td>
                        <td class="val">{{ $incotermDisplay }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <!-- Payment terms -->
    <div class="terms-block">
        <div class="section-title">Payment Terms</div>
        {{ config('payment.bank_transfer.terms', '50% against order confirmation and balance against bill of lading.') }}
    </div>

    <!-- Items -->
    <table class="items-table">
        <thead>
            <tr>
                <th>Product</th>
                <th class="right" style="width:80px;">Qty (pcs)</th>
                <th class="right" style="width:100px;">Unit price</th>
                <th class="right" style="width:100px;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->items as $item)
            <tr>
                <td>
                    <strong>{{ $item->brand }}</strong> &mdash; {{ $item->name }}
                    @if ($item->size)
                    <div class="item-sub">{{ $item->size }}</div>
                    @endif
                    @if ($item->sku)
                    <div class="item-sub">SKU: {{ $item->sku }}</div>
                    @endif
                    <div class="item-qty-line">
                        {{ $item->quantity }} pcs &times; &euro;{{ number_format((float) $item->unit_price, 2) }}
                    </div>
                </td>
                <td class="right">{{ $item->quantity }}</td>
                <td class="right">&euro;{{ number_format((float) $item->unit_price, 2) }}</td>
                <td class="right">&euro;{{ number_format((float) $item->line_total, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <!-- Totals -->
    <table class="totals-table">
        <tr>
            <td class="lbl">Subtotal (net)</td>
            <td class="amt">&euro;{{ number_format((float) $order->subtotal, 2) }}</td>
        </tr>
        @if ((float) $order->delivery_cost > 0)
        <tr>
            <td class="lbl">Delivery / Freight</td>
            <td class="amt">&euro;{{ number_format((float) $order->delivery_cost, 2) }}</td>
        </tr>
        @else
        <tr>
            <td class="lbl">Delivery / Freight</td>
            <td class="amt" style="color:#9e9e9e;">To be confirmed</td>
        </tr>
        @endif
        @if ((float) ($order->discount_amount ?? 0) > 0)
        <tr>
            <td class="lbl">{{ $order->discount_label ?? 'Discount' }}</td>
            <td class="amt" style="color:#2e7d32;">&minus;&euro;{{ number_format((float) $order->discount_amount, 2) }}</td>
        </tr>
        @endif
        @if ($order->tax_treatment !== null)
        <tr>
            @if ((float) $order->tax_amount > 0)
            <td class="lbl">VAT ({{ number_format((float) $order->tax_rate, 0) }}%)</td>
            <td class="amt">&euro;{{ number_format((float) $order->tax_amount, 2) }}</td>
            @elseif ($order->is_reverse_charge)
            <td class="lbl">VAT &mdash; reverse charge (0%)</td>
            <td class="amt">&euro;0.00</td>
            @else
            <td class="lbl">VAT &mdash; exempt (0%)</td>
            <td class="amt">&euro;0.00</td>
            @endif
        </tr>
        @endif
        <tr class="total-row">
            <td class="lbl">Order Total</td>
            <td class="amt">&euro;{{ number_format((float) $order->total, 2) }}</td>
        </tr>
    </table>

    @if ($order->is_reverse_charge)
    <p style="font-size:11px;color:#5c5e62;margin-bottom:20px;line-height:1.5;">
        Reverse charge &mdash; VAT liability transfers to the recipient.
    </p>
    @elseif ($order->tax_treatment === 'exempt')
    <p style="font-size:11px;color:#5c5e62;margin-bottom:20px;line-height:1.5;">
        Export outside the EU &mdash; VAT exempt.
    </p>
    @endif

    <!-- Bank transfer payment details -->
    @if ($order->payment_method === 'bank_transfer')
    <table class="bank-block">
        <tr>
            <td>
                <p style="margin:0 0 10px 0;font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#9e9e9e;">
                    Bank Transfer Details
                </p>
                <table class="bank-inner">
                    <tr>
                        <td class="lbl">Account Name</td>
                        <td class="val">{{ config('payment.bank_transfer.account_name') }}</td>
                    </tr>
                    <tr>
                        <td class="lbl">IBAN</td>
                        <td class="val">{{ config('payment.bank_transfer.iban') }}</td>
                    </tr>
                    <tr>
                        <td class="lbl">BIC / SWIFT</td>
                        <td class="val">{{ config('payment.bank_transfer.swift_bic') }}</td>
                    </tr>
                    <tr>
                        <td class="lbl">Bank</td>
                        <td class="val">{{ config('payment.bank_transfer.bank_name') }}, {{ config('payment.bank_transfer.bank_address') }}</td>
                    </tr>
                    <tr>
                        <td class="lbl" style="padding-top:6px;">Payment Reference</td>
                        <td class="val" style="padding-top:6px;">{{ $order->ref }}</td>
                    </tr>
                    <tr>
                        <td colspan="2" style="padding-top:6px;font-size:11px;color:#5c5e62;line-height:1.5;">
                            {{ config('payment.bank_transfer.sepa_note') }}<br>
                            {{ config('payment.bank_transfer.international_note') }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
    @endif

    <!-- Footer -->
    <div class="footer">
        <p><strong>This Order Confirmation constitutes Okelcor's acceptance of your order.</strong>
        The goods and quantities listed above are reserved pending receipt of the deposit payment.</p>
        <p style="margin-top:4px;">A Proforma Invoice (PI-) will be issued separately once delivery and freight terms are finalised.
        Please quote order reference <strong>{{ $order->ref }}</strong> in all correspondence.</p>
        <p style="margin-top:8px;">Okelcor &mdash; support@okelcor.com &mdash; okelcor.com</p>
    </div>

</div>
</body>
</html>
