<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>New quote request — {{ $quote->ref_number }}</title>
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
            <span style="font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;margin-left:12px;">Admin notification</span>
        </td>
    </tr>

    <!-- Body -->
    <tr>
        <td style="padding:32px 36px 0 36px;">
            <p style="margin:0 0 6px 0;font-family:Arial,Helvetica,sans-serif;font-size:18px;font-weight:700;color:#171a20;">New quote request received</p>
            <p style="margin:0 0 12px 0;font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#5c5e62;line-height:1.6;">A new quote request has been submitted. Details are below.</p>
            <p style="margin:0 0 24px 0;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#2e7d32;line-height:1.6;background-color:#f1f8e9;border-left:3px solid #66bb6a;padding:10px 14px;">&#8594; Reply directly to this email to contact the customer.</p>

            <!-- Contact info -->
            <p style="margin:0 0 10px 0;font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;color:#171a20;">Contact</p>
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #eeeeee;margin-bottom:24px;">
                <tr>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;width:38%;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Reference</td>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;color:#171a20;border-bottom:1px solid #eeeeee;">{{ $quote->ref_number }}</td>
                </tr>
                <tr>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Name</td>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;border-bottom:1px solid #eeeeee;">{{ $quote->full_name }}</td>
                </tr>
                @if ($quote->company_name)
                <tr>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Company</td>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;border-bottom:1px solid #eeeeee;">{{ $quote->company_name }}</td>
                </tr>
                @endif
                <tr>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Email</td>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;border-bottom:1px solid #eeeeee;"><a href="mailto:{{ $quote->email }}" style="color:#171a20;">{{ $quote->email }}</a></td>
                </tr>
                @if ($quote->phone)
                <tr>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Phone</td>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;border-bottom:1px solid #eeeeee;">{{ $quote->phone }}</td>
                </tr>
                @endif
                <tr>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Country</td>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;border-bottom:1px solid #eeeeee;">{{ $quote->country }}</td>
                </tr>
                @if ($quote->vat_number)
                <tr>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;">VAT number</td>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;">{{ $quote->vat_number }}@if ($quote->vat_valid !== null) &nbsp;<span style="font-size:11px;color:{{ $quote->vat_valid ? '#2e7d32' : '#c62828' }};">({{ $quote->vat_valid ? 'valid' : 'invalid' }})</span>@endif</td>
                </tr>
                @endif
            </table>

            <!-- Quote details -->
            <p style="margin:0 0 10px 0;font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;color:#171a20;">Quote details</p>
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #eeeeee;margin-bottom:24px;">
                @if ($quote->tyre_category)
                <tr>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;width:38%;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Category</td>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;border-bottom:1px solid #eeeeee;">{{ strtoupper($quote->tyre_category) }}</td>
                </tr>
                @endif
                @if ($quote->brand_preference)
                <tr>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Brand</td>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;border-bottom:1px solid #eeeeee;">{{ $quote->brand_preference }}</td>
                </tr>
                @endif
                @if ($quote->tyre_size)
                <tr>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Size</td>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;border-bottom:1px solid #eeeeee;">{{ $quote->tyre_size }}</td>
                </tr>
                @endif
                @if ($quote->quantity)
                <tr>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Quantity</td>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;border-bottom:1px solid #eeeeee;">{{ $quote->quantity }}</td>
                </tr>
                @endif
                @if ($quote->budget_range)
                <tr>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Budget</td>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;border-bottom:1px solid #eeeeee;">{{ $quote->budget_range }}</td>
                </tr>
                @endif
                @if ($quote->delivery_location)
                <tr>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Delivery location</td>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;border-bottom:1px solid #eeeeee;">{{ $quote->delivery_location }}</td>
                </tr>
                @endif
                @if ($quote->delivery_timeline)
                <tr>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Timeline</td>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;border-bottom:1px solid #eeeeee;">{{ $quote->delivery_timeline }}</td>
                </tr>
                @endif
                @if ($quote->business_type)
                <tr>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;">Business type</td>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;">{{ ucwords(str_replace('_', ' ', $quote->business_type)) }}</td>
                </tr>
                @endif
            </table>

            @if ($quote->notes)
            <!-- Notes -->
            <p style="margin:0 0 10px 0;font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;color:#171a20;">Notes from customer</p>
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #eeeeee;margin-bottom:24px;">
                <tr>
                    <td style="padding:14px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;line-height:1.6;">{{ $quote->notes }}</td>
                </tr>
            </table>
            @endif

            @if ($quote->attachment_original_name)
            <!-- Attachment note -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #e0e0e0;background-color:#fafafa;margin-bottom:24px;">
                <tr>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;line-height:1.6;">
                        Attachment: <strong style="color:#171a20;">{{ $quote->attachment_original_name }}</strong>
                        &nbsp;&mdash; available in the admin panel.
                    </td>
                </tr>
            </table>
            @endif

        </td>
    </tr>

    <!-- Footer -->
    <tr>
        <td style="padding:24px 36px;border-top:1px solid #eeeeee;">
            <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#9e9e9e;">Okelcor admin notification &mdash; {{ date('Y') }}</p>
        </td>
    </tr>

</table>

</td></tr>
</table>

</body>
</html>
