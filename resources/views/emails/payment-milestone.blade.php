<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>{{ $stage === 'deposit_requested' ? 'Deposit Requested' : ($stage === 'deposit_paid' ? 'Deposit Received' : ($stage === 'balance_due' ? 'Balance Payment Due' : ($stage === 'balance_paid' ? 'Balance Received' : 'Shipment Released'))) }} — {{ $order->ref }}</title>
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

            {{-- ── DEPOSIT REQUESTED ─────────────────────────────────────────── --}}
            @if ($stage === 'deposit_requested')

            <p style="margin:0 0 6px 0;font-family:Arial,Helvetica,sans-serif;font-size:18px;font-weight:700;color:#171a20;">Deposit requested</p>
            <p style="margin:0 0 24px 0;font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#5c5e62;line-height:1.6;">
                Hello {{ $firstName }}, to proceed with your order a deposit payment is required.
                Please transfer the amount shown below using the bank details provided.
            </p>

            <!-- Payment summary -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #eeeeee;margin-bottom:28px;">
                <tr>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;width:45%;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Order reference</td>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;color:#171a20;border-bottom:1px solid #eeeeee;">{{ $order->ref }}</td>
                </tr>
                <tr>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Order total</td>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;border-bottom:1px solid #eeeeee;">&euro;{{ number_format((float) $order->total, 2) }}</td>
                </tr>
                <tr>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Deposit ({{ number_format((float) $order->deposit_percent, 0) }}%)</td>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:700;color:#171a20;border-bottom:1px solid #eeeeee;">&euro;{{ number_format((float) $order->deposit_amount, 2) }}</td>
                </tr>
                <tr>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;">Balance due later</td>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;">&euro;{{ number_format((float) $order->balance_amount, 2) }}</td>
                </tr>
            </table>

            <!-- Bank details -->
            <p style="margin:0 0 12px 0;font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;color:#171a20;">Bank transfer details</p>
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #e0e0e0;background-color:#fafafa;margin-bottom:28px;">
                <tr><td style="padding:16px 20px;">
                    <table width="100%" cellpadding="0" cellspacing="0" border="0">
                        <tr>
                            <td style="padding:4px 12px 4px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#5c5e62;width:38%;vertical-align:top;">Account name</td>
                            <td style="padding:4px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;font-weight:700;color:#171a20;">{{ $bank['account_name'] }}</td>
                        </tr>
                        <tr>
                            <td style="padding:4px 12px 4px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#5c5e62;vertical-align:top;">IBAN</td>
                            <td style="padding:4px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;font-weight:700;color:#171a20;">{{ $bank['iban'] }}</td>
                        </tr>
                        <tr>
                            <td style="padding:4px 12px 4px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#5c5e62;vertical-align:top;">BIC / SWIFT</td>
                            <td style="padding:4px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;font-weight:700;color:#171a20;">{{ $bank['swift_bic'] }}</td>
                        </tr>
                        <tr>
                            <td style="padding:4px 12px 4px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#5c5e62;vertical-align:top;">Bank</td>
                            <td style="padding:4px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#171a20;">{{ $bank['bank_name'] }}</td>
                        </tr>
                        <tr>
                            <td style="padding:4px 12px 4px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#5c5e62;vertical-align:top;">Payment reference</td>
                            <td style="padding:4px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;font-weight:700;color:#171a20;">{{ $order->ref }}</td>
                        </tr>
                        <tr>
                            <td colspan="2" style="padding:10px 0 0 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#5c5e62;line-height:1.5;">{{ $bank['sepa_note'] }}</td>
                        </tr>
                        <tr>
                            <td colspan="2" style="padding:4px 0 0 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#5c5e62;line-height:1.5;">{{ $bank['international_note'] }}</td>
                        </tr>
                    </table>
                </td></tr>
            </table>

            {{-- ── DEPOSIT PAID ──────────────────────────────────────────────── --}}
            @elseif ($stage === 'deposit_paid')

            <p style="margin:0 0 6px 0;font-family:Arial,Helvetica,sans-serif;font-size:18px;font-weight:700;color:#171a20;">Deposit received</p>
            <p style="margin:0 0 24px 0;font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#5c5e62;line-height:1.6;">
                Hello {{ $firstName }}, we have received your deposit for order <strong>{{ $order->ref }}</strong>.
                Thank you &mdash; we are now preparing your order for shipment and will issue the commercial documents shortly.
            </p>

            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #eeeeee;margin-bottom:28px;">
                <tr>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;width:45%;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Order reference</td>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;color:#171a20;border-bottom:1px solid #eeeeee;">{{ $order->ref }}</td>
                </tr>
                <tr>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Deposit confirmed</td>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;color:#2e7d32;border-bottom:1px solid #eeeeee;">&euro;{{ number_format((float) $order->deposit_amount, 2) }}</td>
                </tr>
                <tr>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;">Remaining balance</td>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;">&euro;{{ number_format((float) $order->balance_amount, 2) }}</td>
                </tr>
            </table>

            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-left:4px solid #f4511e;background-color:#fff8f6;margin-bottom:28px;">
                <tr>
                    <td style="padding:14px 18px;font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#5c5e62;line-height:1.6;">
                        <strong style="color:#171a20;display:block;margin-bottom:4px;">What happens next?</strong>
                        We are now preparing your order and arranging logistics. Your commercial invoice and packing list will be issued once preparation is complete. You will receive a separate notification when your balance payment becomes due.
                    </td>
                </tr>
            </table>

            {{-- ── BALANCE DUE ───────────────────────────────────────────────── --}}
            @elseif ($stage === 'balance_due')

            <p style="margin:0 0 6px 0;font-family:Arial,Helvetica,sans-serif;font-size:18px;font-weight:700;color:#171a20;">Balance payment due</p>
            <p style="margin:0 0 24px 0;font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#5c5e62;line-height:1.6;">
                Hello {{ $firstName }}, your order <strong>{{ $order->ref }}</strong> is ready for the final payment.
                Please transfer the balance amount below to release your shipment.
            </p>

            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #eeeeee;margin-bottom:28px;">
                <tr>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;width:45%;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Order reference</td>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;color:#171a20;border-bottom:1px solid #eeeeee;">{{ $order->ref }}</td>
                </tr>
                <tr>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Deposit paid</td>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#2e7d32;border-bottom:1px solid #eeeeee;">&euro;{{ number_format((float) $order->deposit_amount, 2) }}</td>
                </tr>
                <tr>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;">Balance due now</td>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:700;color:#171a20;">&euro;{{ number_format((float) $order->balance_amount, 2) }}</td>
                </tr>
            </table>

            <p style="margin:0 0 12px 0;font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;color:#171a20;">Bank transfer details</p>
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #e0e0e0;background-color:#fafafa;margin-bottom:28px;">
                <tr><td style="padding:16px 20px;">
                    <table width="100%" cellpadding="0" cellspacing="0" border="0">
                        <tr>
                            <td style="padding:4px 12px 4px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#5c5e62;width:38%;vertical-align:top;">Account name</td>
                            <td style="padding:4px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;font-weight:700;color:#171a20;">{{ $bank['account_name'] }}</td>
                        </tr>
                        <tr>
                            <td style="padding:4px 12px 4px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#5c5e62;vertical-align:top;">IBAN</td>
                            <td style="padding:4px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;font-weight:700;color:#171a20;">{{ $bank['iban'] }}</td>
                        </tr>
                        <tr>
                            <td style="padding:4px 12px 4px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#5c5e62;vertical-align:top;">BIC / SWIFT</td>
                            <td style="padding:4px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;font-weight:700;color:#171a20;">{{ $bank['swift_bic'] }}</td>
                        </tr>
                        <tr>
                            <td style="padding:4px 12px 4px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#5c5e62;vertical-align:top;">Bank</td>
                            <td style="padding:4px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#171a20;">{{ $bank['bank_name'] }}</td>
                        </tr>
                        <tr>
                            <td style="padding:4px 12px 4px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#5c5e62;vertical-align:top;">Payment reference</td>
                            <td style="padding:4px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;font-weight:700;color:#171a20;">{{ $order->ref }}</td>
                        </tr>
                        <tr>
                            <td colspan="2" style="padding:10px 0 0 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#5c5e62;line-height:1.5;">{{ $bank['sepa_note'] }}</td>
                        </tr>
                        <tr>
                            <td colspan="2" style="padding:4px 0 0 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#5c5e62;line-height:1.5;">{{ $bank['international_note'] }}</td>
                        </tr>
                    </table>
                </td></tr>
            </table>

            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-left:4px solid #f4511e;background-color:#fff8e1;margin-bottom:28px;">
                <tr>
                    <td style="padding:14px 18px;font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#5c5e62;line-height:1.6;">
                        Shipment will be released once your balance payment has been received and confirmed.
                    </td>
                </tr>
            </table>

            {{-- ── BALANCE PAID ──────────────────────────────────────────────── --}}
            @elseif ($stage === 'balance_paid')

            <p style="margin:0 0 6px 0;font-family:Arial,Helvetica,sans-serif;font-size:18px;font-weight:700;color:#171a20;">Full payment received</p>
            <p style="margin:0 0 24px 0;font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#5c5e62;line-height:1.6;">
                Hello {{ $firstName }}, your full payment for order <strong>{{ $order->ref }}</strong> has been received. Thank you.
                We are now processing the final release of your shipment.
            </p>

            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #eeeeee;margin-bottom:28px;">
                <tr>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;width:45%;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Order reference</td>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;color:#171a20;border-bottom:1px solid #eeeeee;">{{ $order->ref }}</td>
                </tr>
                <tr>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;">Total received</td>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:700;color:#2e7d32;">&euro;{{ number_format((float) $order->total, 2) }}</td>
                </tr>
            </table>

            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-left:4px solid #f4511e;background-color:#fff8f6;margin-bottom:28px;">
                <tr>
                    <td style="padding:14px 18px;font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#5c5e62;line-height:1.6;">
                        <strong style="color:#171a20;display:block;margin-bottom:4px;">What happens next?</strong>
                        We will now arrange the release of your shipment. You will receive a separate notification with shipment and tracking details once your order is released.
                    </td>
                </tr>
            </table>

            {{-- ── SHIPMENT RELEASED ─────────────────────────────────────────── --}}
            @elseif ($stage === 'shipment_released')

            <p style="margin:0 0 6px 0;font-family:Arial,Helvetica,sans-serif;font-size:18px;font-weight:700;color:#171a20;">Shipment released</p>
            <p style="margin:0 0 24px 0;font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#5c5e62;line-height:1.6;">
                Hello {{ $firstName }}, great news &mdash; your shipment for order <strong>{{ $order->ref }}</strong> has been released.
            </p>

            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #eeeeee;margin-bottom:28px;">
                <tr>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;width:45%;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Order reference</td>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;color:#171a20;border-bottom:1px solid #eeeeee;">{{ $order->ref }}</td>
                </tr>
                @if ($order->carrier)
                <tr>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Carrier</td>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;border-bottom:1px solid #eeeeee;">{{ $order->carrier }}</td>
                </tr>
                @endif
                @if ($order->tracking_number)
                <tr>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Tracking number</td>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;color:#171a20;border-bottom:1px solid #eeeeee;">{{ $order->tracking_number }}</td>
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
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;">Estimated arrival</td>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;">{{ \Carbon\Carbon::parse($order->eta)->format('d M Y') }}</td>
                </tr>
                @endif
            </table>

            @if ($order->shipment_release_note)
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-left:4px solid #f4511e;background-color:#fff8f6;margin-bottom:28px;">
                <tr>
                    <td style="padding:14px 18px;">
                        <p style="margin:0 0 4px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;font-weight:700;color:#5c5e62;text-transform:uppercase;letter-spacing:0.5px;">Note from Okelcor</p>
                        <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#171a20;line-height:1.6;">{{ $order->shipment_release_note }}</p>
                    </td>
                </tr>
            </table>
            @endif

            <p style="margin:0 0 24px 0;font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#5c5e62;line-height:1.6;">
                Shipping documents are available in your order area. You can log in to view all documents and track your delivery.
            </p>

            @endif

            <!-- View order CTA -->
            <p style="margin:0 0 32px 0;font-family:Arial,Helvetica,sans-serif;font-size:14px;">
                <a href="{{ $orderUrl }}" style="color:#171a20;text-decoration:underline;">View your order: {{ $orderUrl }}</a>
            </p>

        </td>
    </tr>

    <!-- Footer -->
    <tr>
        <td style="padding:24px 36px;border-top:1px solid #eeeeee;">
            <p style="margin:0 0 4px 0;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;">
                Questions? Email us at <a href="mailto:support@okelcor.com" style="color:#555555;text-decoration:underline;">support@okelcor.com</a>
            </p>
            <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#9e9e9e;">Okelcor &mdash; {{ date('Y') }}</p>
        </td>
    </tr>

</table>

</td></tr>
</table>

</body>
</html>
