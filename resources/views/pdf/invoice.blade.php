<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12px;
            color: #171a20;
            background: #ffffff;
        }

        .page {
            padding: 40px 48px;
        }

        /* Header */
        .header-table {
            width: 100%;
            border-bottom: 3px solid #f4511e;
            padding-bottom: 20px;
            margin-bottom: 28px;
        }
        .header-table td { vertical-align: top; }
        .brand {
            font-size: 22px;
            font-weight: 700;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: #171a20;
        }
        .invoice-label {
            font-size: 20px;
            font-weight: 700;
            color: #171a20;
            text-align: right;
        }
        .invoice-number {
            font-size: 13px;
            color: #5c5e62;
            text-align: right;
            margin-top: 4px;
        }

        /* Meta section */
        .meta-table {
            width: 100%;
            margin-bottom: 28px;
        }
        .meta-table td { vertical-align: top; width: 50%; }
        .section-title {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #9e9e9e;
            margin-bottom: 6px;
        }
        .meta-value {
            font-size: 12px;
            color: #171a20;
            line-height: 1.6;
        }
        .meta-value strong {
            font-weight: 700;
        }

        /* Items table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
        }
        .items-table thead tr {
            background-color: #f5f5f5;
        }
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
        .item-size {
            font-size: 11px;
            color: #5c5e62;
            margin-top: 2px;
        }

        /* Totals */
        .totals-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 36px;
        }
        .totals-table td {
            padding: 6px 12px;
            font-size: 12px;
        }
        .totals-table .label { color: #5c5e62; text-align: right; width: 75%; }
        .totals-table .amount { color: #171a20; text-align: right; font-weight: 700; }
        .totals-table .total-row td {
            border-top: 2px solid #dddddd;
            padding-top: 10px;
            font-size: 14px;
        }

        /* Status badge */
        .status-paid {
            display: inline-block;
            background-color: #e8f5e9;
            color: #2e7d32;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            padding: 3px 8px;
        }

        /* Footer */
        .footer {
            border-top: 1px solid #eeeeee;
            padding-top: 16px;
            color: #9e9e9e;
            font-size: 11px;
            line-height: 1.6;
        }
    </style>
</head>
<body>
<div class="page">

    <!-- Header -->
    <table class="header-table">
        <tr>
            <td><span class="brand">Okelcor</span></td>
            <td>
                <div class="invoice-label">Invoice</div>
                <div class="invoice-number">{{ $invoice->invoice_number }}</div>
            </td>
        </tr>
    </table>

    <!-- Bill to / Invoice details -->
    <table class="meta-table">
        <tr>
            <td>
                <div class="section-title">Bill to</div>
                <div class="meta-value">
                    <strong>{{ $order->customer_name }}</strong><br>
                    {{ $order->customer_email }}<br>
                    @if ($order->customer_phone){{ $order->customer_phone }}<br>@endif
                    {{ $order->address }}<br>
                    {{ $order->city }}, {{ $order->postal_code }}<br>
                    {{ $order->country }}
                </div>
            </td>
            <td style="text-align: right;">
                <div class="section-title">Invoice details</div>
                <div class="meta-value">
                    <table style="margin-left: auto; border-collapse: collapse;">
                        <tr>
                            <td style="color: #5c5e62; padding: 2px 8px 2px 0;">Invoice number</td>
                            <td style="font-weight: 700;">{{ $invoice->invoice_number }}</td>
                        </tr>
                        <tr>
                            <td style="color: #5c5e62; padding: 2px 8px 2px 0;">Date issued</td>
                            <td>{{ $invoice->issued_at->format('d M Y') }}</td>
                        </tr>
                        <tr>
                            <td style="color: #5c5e62; padding: 2px 8px 2px 0;">Order reference</td>
                            <td>{{ $order->ref }}</td>
                        </tr>
                        <tr>
                            <td style="color: #5c5e62; padding: 2px 8px 2px 0;">Status</td>
                            <td><span class="status-paid">{{ strtoupper($invoice->status) }}</span></td>
                        </tr>
                    </table>
                </div>
            </td>
        </tr>
    </table>

    <!-- Items -->
    <table class="items-table">
        <thead>
            <tr>
                <th>Product</th>
                <th class="right" style="width: 50px;">Qty</th>
                <th class="right" style="width: 90px;">Unit price</th>
                <th class="right" style="width: 90px;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->items as $item)
            <tr>
                <td>
                    {{ $item->brand }} — {{ $item->name }}
                    @if ($item->size)
                    <div class="item-size">{{ $item->size }}</div>
                    @endif
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
            <td class="label">Subtotal (net)</td>
            <td class="amount">&euro;{{ number_format((float) $order->subtotal, 2) }}</td>
        </tr>
        @if ((float) $order->delivery_cost > 0)
        <tr>
            <td class="label">Delivery</td>
            <td class="amount">&euro;{{ number_format((float) $order->delivery_cost, 2) }}</td>
        </tr>
        @endif
        @if ((float) ($order->discount_amount ?? 0) > 0)
        <tr>
            <td class="label">{{ $order->discount_label ?? 'Discount' }}</td>
            <td class="amount" style="color:#2e7d32;">&minus;&euro;{{ number_format((float) $order->discount_amount, 2) }}</td>
        </tr>
        @endif
        @if ($order->tax_treatment !== null)
        <tr>
            @if ((float) $order->tax_amount > 0)
            <td class="label">VAT ({{ number_format((float) $order->tax_rate, 0) }}%)</td>
            <td class="amount">&euro;{{ number_format((float) $order->tax_amount, 2) }}</td>
            @elseif ($order->is_reverse_charge)
            <td class="label">VAT &mdash; reverse charge (0%)</td>
            <td class="amount">&euro;0.00</td>
            @else
            <td class="label">VAT &mdash; exempt (0%)</td>
            <td class="amount">&euro;0.00</td>
            @endif
        </tr>
        @endif
        <tr class="total-row">
            <td class="label">Total</td>
            <td class="amount">&euro;{{ number_format((float) $order->total, 2) }}</td>
        </tr>
    </table>

    @if ($order->is_reverse_charge)
    <p style="font-size:11px;color:#5c5e62;margin-bottom:24px;line-height:1.5;">
        Reverse charge &mdash; VAT liability transfers to the recipient.
    </p>
    @elseif ($order->tax_treatment === 'exempt')
    <p style="font-size:11px;color:#5c5e62;margin-bottom:24px;line-height:1.5;">
        Export outside the EU &mdash; VAT exempt.
    </p>
    @endif

    @if ($order->payment_method === 'bank_transfer' && $invoice->status !== 'paid')
    <!-- Bank transfer payment details -->
    <table style="width:100%;border-collapse:collapse;margin-bottom:28px;border:1px solid #e0e0e0;background-color:#fafafa;">
        <tr>
            <td style="padding:14px 16px;" colspan="2">
                <p style="margin:0 0 10px 0;font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#9e9e9e;">Payment Details</p>
                <table style="width:100%;border-collapse:collapse;">
                    <tr>
                        <td style="padding:3px 12px 3px 0;font-size:11px;color:#5c5e62;width:35%;">Account Name</td>
                        <td style="padding:3px 0;font-size:11px;font-weight:700;color:#171a20;">{{ config('payment.bank_transfer.account_name') }}</td>
                    </tr>
                    <tr>
                        <td style="padding:3px 12px 3px 0;font-size:11px;color:#5c5e62;">IBAN</td>
                        <td style="padding:3px 0;font-size:11px;font-weight:700;color:#171a20;">{{ config('payment.bank_transfer.iban') }}</td>
                    </tr>
                    <tr>
                        <td style="padding:3px 12px 3px 0;font-size:11px;color:#5c5e62;">SWIFT / BIC</td>
                        <td style="padding:3px 0;font-size:11px;font-weight:700;color:#171a20;">{{ config('payment.bank_transfer.swift_bic') }}</td>
                    </tr>
                    <tr>
                        <td style="padding:3px 12px 3px 0;font-size:11px;color:#5c5e62;">Bank</td>
                        <td style="padding:3px 0;font-size:11px;color:#171a20;">{{ config('payment.bank_transfer.bank_name') }}, {{ config('payment.bank_transfer.bank_address') }}</td>
                    </tr>
                    <tr>
                        <td style="padding:3px 12px 3px 0;font-size:11px;color:#5c5e62;">Delivery Term</td>
                        <td style="padding:3px 0;font-size:11px;color:#171a20;">{{ config('payment.bank_transfer.delivery_term') }}</td>
                    </tr>
                    <tr>
                        <td style="padding:3px 12px 3px 0;font-size:11px;color:#5c5e62;">Payment Terms</td>
                        <td style="padding:3px 0;font-size:11px;color:#171a20;">{{ config('payment.bank_transfer.terms') }}</td>
                    </tr>
                    <tr>
                        <td style="padding:6px 12px 0 0;font-size:11px;color:#5c5e62;">Reference</td>
                        <td style="padding:6px 0 0 0;font-size:11px;font-weight:700;color:#171a20;">{{ $order->ref }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
    @endif

    <!-- Footer -->
    <div class="footer">
        <p>Okelcor &mdash; support@okelcor.com &mdash; okelcor.com</p>
        <p style="margin-top: 4px;">Thank you for your business.</p>
    </div>

</div>
</body>
</html>
