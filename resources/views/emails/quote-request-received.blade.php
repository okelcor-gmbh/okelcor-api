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
            <p style="margin:0 0 12px 0;font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#5c5e62;line-height:1.6;">Ref: <strong>{{ $quote->ref_number }}</strong> &mdash; {{ $quote->created_at?->format('d M Y, H:i') }} UTC</p>
            <p style="margin:0 0 24px 0;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#2e7d32;line-height:1.6;background-color:#f1f8e9;border-left:3px solid #66bb6a;padding:10px 14px;">&#8594; Reply directly to this email to contact the customer.</p>

            {{-- ── CONTACT ── --}}
            <p style="margin:0 0 10px 0;font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;color:#171a20;text-transform:uppercase;letter-spacing:1px;">Contact</p>
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #eeeeee;margin-bottom:24px;">
                <tr>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;width:38%;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Name</td>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;border-bottom:1px solid #eeeeee;">{{ $quote->full_name }}</td>
                </tr>
                @if ($quote->contact_person)
                <tr>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Contact person</td>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;border-bottom:1px solid #eeeeee;">{{ $quote->contact_person }}</td>
                </tr>
                @endif
                @if ($quote->company_name)
                <tr>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Company</td>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;border-bottom:1px solid #eeeeee;">{{ $quote->company_name }}</td>
                </tr>
                @endif
                @if ($quote->company_address || $quote->company_city)
                <tr>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Company address</td>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;border-bottom:1px solid #eeeeee;">
                        @if ($quote->company_address){{ $quote->company_address }}, @endif
                        @if ($quote->company_postal_code){{ $quote->company_postal_code }} @endif
                        {{ $quote->company_city }}
                    </td>
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
                @if ($quote->business_type)
                <tr>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Business type</td>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;border-bottom:1px solid #eeeeee;">{{ ucwords(str_replace('_', ' ', $quote->business_type)) }}</td>
                </tr>
                @endif
                @if ($quote->vat_number)
                <tr>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;">VAT number</td>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;">
                        {{ $quote->vat_number }}
                        @if ($quote->vat_valid !== null)
                            &nbsp;<span style="font-size:11px;font-weight:700;color:{{ $quote->vat_valid ? '#2e7d32' : '#c62828' }};">({{ $quote->vat_valid ? 'VERIFIED' : 'NOT VERIFIED' }})</span>
                        @endif
                    </td>
                </tr>
                @endif
            </table>

            {{-- ── TYRE REQUEST ── --}}
            <p style="margin:0 0 10px 0;font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;color:#171a20;text-transform:uppercase;letter-spacing:1px;">Tyre Request</p>
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #eeeeee;margin-bottom:24px;">
                @if ($quote->tyre_category)
                <tr>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;width:38%;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Category</td>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;border-bottom:1px solid #eeeeee;">{{ strtoupper($quote->tyre_category) }}</td>
                </tr>
                @endif
                @if ($quote->brand_preference)
                <tr>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Brand preference</td>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;border-bottom:1px solid #eeeeee;">{{ $quote->brand_preference }}</td>
                </tr>
                @endif
                @if ($quote->tyre_condition)
                <tr>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Condition</td>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;border-bottom:1px solid #eeeeee;">{{ ucfirst($quote->tyre_condition) }}</td>
                </tr>
                @endif
                @if ($quote->tyre_condition === 'used' && $quote->used_tyre_grade)
                <tr>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Used grade</td>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;border-bottom:1px solid #eeeeee;">{{ ucwords(str_replace('_', ' ', $quote->used_tyre_grade)) }}</td>
                </tr>
                @endif
                @if ($quote->tyre_condition === 'used' && $quote->used_tyre_notes)
                <tr>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Condition notes</td>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;border-bottom:1px solid #eeeeee;line-height:1.5;">{{ $quote->used_tyre_notes }}</td>
                </tr>
                @endif

                {{-- Tyre items: multi-row or legacy fallback --}}
                @if (!empty($quote->tyre_items) && count($quote->tyre_items) > 0)
                <tr>
                    <td colspan="2" style="padding:0;border-bottom:1px solid #eeeeee;">
                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="padding:8px 16px;font-family:Arial,Helvetica,sans-serif;font-size:12px;font-weight:700;color:#5c5e62;background-color:#f0f0f0;border-bottom:1px solid #eeeeee;width:38%;">Size</td>
                                <td style="padding:8px 16px;font-family:Arial,Helvetica,sans-serif;font-size:12px;font-weight:700;color:#5c5e62;background-color:#f0f0f0;border-bottom:1px solid #eeeeee;">Quantity</td>
                            </tr>
                            @foreach ($quote->tyre_items as $item)
                            <tr>
                                <td style="padding:9px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;{{ !$loop->last ? 'border-bottom:1px solid #eeeeee;' : '' }}">{{ $item['size'] ?? '—' }}</td>
                                <td style="padding:9px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;{{ !$loop->last ? 'border-bottom:1px solid #eeeeee;' : '' }}">{{ $item['quantity'] ?? '—' }}</td>
                            </tr>
                            @endforeach
                        </table>
                    </td>
                </tr>
                @else
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
                @endif

            </table>

            {{-- ── LOGISTICS ── --}}
            <p style="margin:0 0 10px 0;font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;color:#171a20;text-transform:uppercase;letter-spacing:1px;">Logistics</p>
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #eeeeee;margin-bottom:24px;">
                @if ($quote->delivery_location)
                <tr>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;width:38%;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Delivery destination</td>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;border-bottom:1px solid #eeeeee;">{{ $quote->delivery_location }}</td>
                </tr>
                @endif
                @if ($quote->delivery_address || $quote->delivery_city)
                <tr>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Delivery address</td>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;border-bottom:1px solid #eeeeee;">
                        @if ($quote->delivery_address){{ $quote->delivery_address }}, @endif
                        @if ($quote->delivery_postal_code){{ $quote->delivery_postal_code }} @endif
                        {{ $quote->delivery_city }}
                    </td>
                </tr>
                @endif
                @if ($quote->delivery_timeline)
                <tr>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Timeline</td>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;border-bottom:1px solid #eeeeee;">{{ $quote->delivery_timeline }}</td>
                </tr>
                @endif
                @if ($quote->incoterm)
                <tr>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;border-bottom:1px solid #eeeeee;">Incoterm</td>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;border-bottom:1px solid #eeeeee;">
                        {{ strtoupper($quote->incoterm) }}
                        @if ($quote->incoterm_type)
                            <span style="color:#5c5e62;">({{ ucwords(str_replace('_', ' ', $quote->incoterm_type)) }})</span>
                        @endif
                    </td>
                </tr>
                @endif
                @if ($quote->budget_range)
                <tr>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;background-color:#fafafa;">Budget range</td>
                    <td style="padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;">{{ $quote->budget_range }}</td>
                </tr>
                @endif
            </table>

            @if ($quote->notes)
            {{-- ── NOTES ── --}}
            <p style="margin:0 0 10px 0;font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;color:#171a20;text-transform:uppercase;letter-spacing:1px;">Notes from customer</p>
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #eeeeee;margin-bottom:24px;">
                <tr>
                    <td style="padding:14px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#171a20;line-height:1.6;">{{ $quote->notes }}</td>
                </tr>
            </table>
            @endif

            @if ($quote->attachment_original_name)
            {{-- ── ATTACHMENT ── --}}
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #e0e0e0;background-color:#fafafa;margin-bottom:24px;">
                <tr>
                    <td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;line-height:1.6;">
                        &#128206; Attachment: <strong style="color:#171a20;">{{ $quote->attachment_original_name }}</strong>
                        @if ($quote->attachment_size)
                            <span style="color:#9e9e9e;">({{ round($quote->attachment_size / 1024) }} KB)</span>
                        @endif
                        &mdash; available in the admin panel.
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
