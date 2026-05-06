<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Order Confirmation — {{ $order->ref }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f5f5f5;font-family:Arial,Helvetica,sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f5f5f5;">
<tr><td align="center" style="padding:32px 16px;">

<table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background-color:#ffffff;">

    <!-- Top accent line -->
    <tr>
        <td style="background-color:#f4511e;height:3px;font-size:0;line-height:0;">&nbsp;</td>
    </tr>

    <!-- Header -->
    <tr>
        <td style="padding:28px 36px 20px 36px;border-bottom:1px solid #eeeeee;">
            <span style="font-family:Arial,Helvetica,sans-serif;font-size:18px;font-weight:700;letter-spacing:2px;color:#171a20;text-transform:uppercase;">OKELCOR</span>
        </td>
    </tr>

    <!-- Body -->
    <tr>
        <td style="padding:32px 36px 0 36px;">
            <p style="margin:0 0 6px 0;font-family:Arial,Helvetica,sans-serif;font-size:18px;font-weight:700;color:#171a20;">Order confirmed</p>
            @if ($order->payment_method === 'bank_transfer' && $order->payment_status !== 'paid')
            <p style="margin:0 0 24px 0;font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#5c5e62;line-height:1.6;">Hello {{ explode(' ', trim($order->customer_name))[0] }}, your order has been received. Please use the bank transfer details below to complete your payment.</p>
            @else
            <p style="margin:0 0 24px 0;font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#5c5e62;line-height:1.6;">Hello {{ explode(' ', trim($order->customer_name))[0] }}, your payment has been received. We will confirm availability and shipping details shortly.</p>
            @endif

            <!-- Order summary -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #eeeeee;margin-bottom:28px;">
                <tr>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;width:40%;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Order reference</td>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;color:#171a20;border-bottom:1px solid #eeeeee;">{{ $order->ref }}</td>
                </tr>
                <tr>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Date</td>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;border-bottom:1px solid #eeeeee;">{{ $order->created_at?->format('d M Y') }}</td>
                </tr>
                <tr>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Payment</td>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;border-bottom:1px solid #eeeeee;">@if ($order->payment_method === 'bank_transfer' && $order->payment_status !== 'paid')<span style="display:inline-block;padding:2px 10px;background-color:#fff8e1;color:#e65100;border-radius:3px;font-size:12px;font-weight:700;letter-spacing:0.5px;text-transform:uppercase;">Pending &mdash; Bank Transfer</span>@else Confirmed @endif</td>
                </tr>
                @if ($order->carrier)
                <tr>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Carrier</td>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;border-bottom:1px solid #eeeeee;">{{ $order->carrier }}</td>
                </tr>
                @endif
                @if ($order->tracking_number)
                <tr>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Tracking</td>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;border-bottom:1px solid #eeeeee;">{{ $order->tracking_number }}</td>
                </tr>
                @endif
                @if ($order->container_number)
                <tr>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Container</td>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;border-bottom:1px solid #eeeeee;">{{ $order->container_number }}</td>
                </tr>
                @endif
                @if ($order->eta)
                <tr>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;">ETA</td>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;">{{ \Carbon\Carbon::parse($order->eta)->format('d M Y') }}</td>
                </tr>
                @endif
            </table>

            <!-- Items -->
            <p style="margin:0 0 12px 0;font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;color:#171a20;">Items ordered</p>
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #eeeeee;margin-bottom:28px;">
                <thead>
                    <tr style="background-color:#fafafa;">
                        <th style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:12px;font-weight:700;color:#5c5e62;text-align:left;border-bottom:1px solid #eeeeee;">Product</th>
                        <th style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:12px;font-weight:700;color:#5c5e62;text-align:center;width:40px;border-bottom:1px solid #eeeeee;">Qty</th>
                        <th style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:12px;font-weight:700;color:#5c5e62;text-align:right;width:80px;border-bottom:1px solid #eeeeee;">Unit</th>
                        <th style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:12px;font-weight:700;color:#5c5e62;text-align:right;width:90px;border-bottom:1px solid #eeeeee;">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($order->items as $item)
                    <tr>
                        <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;border-bottom:1px solid #eeeeee;vertical-align:top;">
                            {{ $item->name }}@if ($item->size)<br><span style="font-size:12px;color:#5c5e62;">{{ $item->size }}</span>@endif
                        </td>
                        <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;text-align:center;border-bottom:1px solid #eeeeee;vertical-align:top;">{{ $item->quantity }}</td>
                        <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;text-align:right;border-bottom:1px solid #eeeeee;vertical-align:top;">€{{ number_format((float) $item->unit_price, 2) }}</td>
                        <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;text-align:right;border-bottom:1px solid #eeeeee;vertical-align:top;">€{{ number_format((float) $item->line_total, 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    @if ($order->tax_treatment !== null)
                    <tr>
                        <td colspan="3" style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;text-align:right;border-top:1px solid #eeeeee;">Subtotal (net)</td>
                        <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;text-align:right;border-top:1px solid #eeeeee;">€{{ number_format((float) $order->subtotal, 2) }}</td>
                    </tr>
                    @if ((float) $order->delivery_cost > 0)
                    <tr>
                        <td colspan="3" style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;text-align:right;">Delivery</td>
                        <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;text-align:right;">€{{ number_format((float) $order->delivery_cost, 2) }}</td>
                    </tr>
                    @endif
                    @if ((float) ($order->discount_amount ?? 0) > 0)
                    <tr>
                        <td colspan="3" style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;text-align:right;">{{ $order->discount_label ?? 'Discount' }}</td>
                        <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#2e7d32;text-align:right;">-€{{ number_format((float) $order->discount_amount, 2) }}</td>
                    </tr>
                    @endif
                    <tr>
                        <td colspan="3" style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;text-align:right;">@if ((float) $order->tax_amount > 0)VAT ({{ number_format((float) $order->tax_rate, 0) }}%)@elseif ($order->is_reverse_charge)VAT &mdash; reverse charge (0%)@else VAT &mdash; exempt (0%)@endif</td>
                        <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;text-align:right;">€{{ number_format((float) ($order->tax_amount ?? 0), 2) }}</td>
                    </tr>
                    @endif
                    <tr>
                        <td colspan="3" style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;color:#171a20;text-align:right;border-top:2px solid #eeeeee;">Total</td>
                        <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:700;color:#171a20;text-align:right;border-top:2px solid #eeeeee;">€{{ number_format((float) $order->total, 2) }}</td>
                    </tr>
                </tfoot>
            </table>

            <!-- CTA -->
            <p style="margin:0 0 16px 0;font-family:Arial,Helvetica,sans-serif;font-size:14px;">
                <a href="{{ $trackingUrl }}" style="color:#171a20;text-decoration:underline;">View your order: {{ $trackingUrl }}</a>
            </p>

            @if ($invoice)
            <!-- Invoice reference -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #eeeeee;margin-bottom:32px;">
                <tr>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;width:40%;background-color:#fafafa;">Invoice number</td>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;color:#171a20;">{{ $invoice->invoice_number }}</td>
                </tr>
                <tr>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;border-top:1px solid #eeeeee;">Download / view</td>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;border-top:1px solid #eeeeee;">
                        <a href="{{ $invoicesUrl }}" style="color:#171a20;text-decoration:underline;">View invoices in your account</a>
                    </td>
                </tr>
            </table>
            @endif

            @if ($order->payment_method === 'bank_transfer' && $order->payment_status !== 'paid')
            <!-- Bank transfer details -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #e0e0e0;background-color:#fafafa;margin-bottom:32px;">
                <tr>
                    <td style="padding:16px 20px;">
                        <p style="margin:0 0 12px 0;font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;color:#171a20;">Bank Transfer Details</p>
                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="padding:4px 12px 4px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#5c5e62;width:38%;vertical-align:top;">Account Name</td>
                                <td style="padding:4px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;font-weight:700;color:#171a20;">{{ config('payment.bank_transfer.account_name') }}</td>
                            </tr>
                            <tr>
                                <td style="padding:4px 12px 4px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#5c5e62;vertical-align:top;">IBAN</td>
                                <td style="padding:4px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;font-weight:700;color:#171a20;">{{ config('payment.bank_transfer.iban') }}</td>
                            </tr>
                            <tr>
                                <td style="padding:4px 12px 4px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#5c5e62;vertical-align:top;">SWIFT / BIC</td>
                                <td style="padding:4px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;font-weight:700;color:#171a20;">{{ config('payment.bank_transfer.swift_bic') }}</td>
                            </tr>
                            <tr>
                                <td style="padding:4px 12px 4px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#5c5e62;vertical-align:top;">Bank</td>
                                <td style="padding:4px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#171a20;">{{ config('payment.bank_transfer.bank_name') }}</td>
                            </tr>
                            <tr>
                                <td style="padding:4px 12px 4px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#5c5e62;vertical-align:top;">Bank Address</td>
                                <td style="padding:4px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#171a20;">{{ config('payment.bank_transfer.bank_address') }}</td>
                            </tr>
                            <tr>
                                <td style="padding:4px 12px 4px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#5c5e62;vertical-align:top;">Delivery Term</td>
                                <td style="padding:4px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#171a20;">{{ config('payment.bank_transfer.delivery_term') }}</td>
                            </tr>
                            <tr>
                                <td colspan="2" style="padding:10px 0 0 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#5c5e62;line-height:1.5;">{{ config('payment.bank_transfer.terms') }}</td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
            @endif
        </td>
    </tr>

    <!-- Footer -->
    <tr>
        <td style="padding:24px 36px;border-top:1px solid #eeeeee;">
            <p style="margin:0 0 4px 0;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;">Questions? Email us at <a href="mailto:support@okelcor.com" style="color:#555555;text-decoration:underline;">support@okelcor.com</a></p>
            <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#9e9e9e;">Okelcor &mdash; {{ date('Y') }}</p>
        </td>
    </tr>

</table>

</td></tr>
</table>

</body>
</html>
