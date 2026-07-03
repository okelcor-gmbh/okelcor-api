<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Proposal {{ $quote->proposal_number }}</title>
    <style>
        @include('pdf.partials._styles')
    </style>
</head>
<body>
<div class="page">

    @php
    $incoterm = strtoupper((string) ($quote->incoterm ?? ''));
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
    $currency = strtoupper($quote->proposal_currency ?? 'EUR');
    @endphp

    @include('pdf.partials._header')

    {{-- Bill-to block --}}
    <table style="width:100%;border-collapse:collapse;margin-bottom:4px;">
        <tr>
            <td style="width:50%;vertical-align:top;padding-right:16px;">
                <div style="font-size:9px;text-transform:uppercase;color:#999;margin-bottom:4px;">Proposal for</div>
                <div style="font-size:12px;font-weight:700;color:#1a1a1a;">{{ $quote->full_name }}</div>
                @if ($quote->company_name)
                <div style="font-size:11px;color:#444;">{{ $quote->company_name }}</div>
                @endif
                @if ($quote->company_address)
                <div style="font-size:10px;color:#666;">
                    {{ $quote->company_address }}
                    @if ($quote->company_city) , {{ $quote->company_city }} @endif
                    @if ($quote->company_postal_code) {{ $quote->company_postal_code }} @endif
                </div>
                @endif
                <div style="font-size:10px;color:#666;">{{ $quote->country }}</div>
                @if ($quote->vat_number)
                <div style="font-size:10px;color:#666;margin-top:2px;">VAT: {{ $quote->vat_number }}</div>
                @endif
            </td>
            <td style="width:50%;vertical-align:top;text-align:right;">
                <div style="font-size:9px;text-transform:uppercase;color:#999;margin-bottom:4px;">Proposal Details</div>
                <table style="font-size:10px;border-collapse:collapse;margin-left:auto;">
                    <tr>
                        <td style="color:#888;padding:2px 8px 2px 0;">Proposal No.</td>
                        <td style="font-weight:700;color:#1a1a1a;">{{ $quote->proposal_number }}</td>
                    </tr>
                    <tr>
                        <td style="color:#888;padding:2px 8px 2px 0;">Quote Ref.</td>
                        <td>{{ $quote->ref_number }}</td>
                    </tr>
                    <tr>
                        <td style="color:#888;padding:2px 8px 2px 0;">Date Issued</td>
                        <td>{{ now()->format('d M Y') }}</td>
                    </tr>
                    @if ($quote->proposal_expires_at)
                    <tr>
                        <td style="color:#888;padding:2px 8px 2px 0;">Valid Until</td>
                        <td style="color:#c0392b;font-weight:600;">{{ $quote->proposal_expires_at->format('d M Y') }}</td>
                    </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    <div style="height:24px;"></div>

    {{-- Document title --}}
    <div class="doc-title">Proposal {{ $quote->proposal_number }}</div>

    <p class="intro-p">Dear {{ $quote->full_name ?? 'Valued Customer' }},</p>
    <p class="intro-p" style="margin-bottom:16px;">
        Thank you for your enquiry (ref: {{ $quote->ref_number }}).
        Please find below our commercial proposal for the supply of tyres:
    </p>

    {{-- Items table --}}
    <table class="items-table">
        <thead>
            <tr>
                <th style="width:26px;">#</th>
                <th>Description</th>
                <th class="r" style="width:80px;">Quantity</th>
                <th class="r" style="width:90px;">Unit Price</th>
                <th class="r" style="width:100px;">Line Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($quote->proposal_items as $i => $item)
            <tr>
                <td style="color:#666;">{{ $i + 1 }}.</td>
                <td>
                    @if (!empty($item['brand']))
                    <strong>{{ $item['brand'] }}</strong> &mdash;
                    @endif
                    {{ $item['name'] }}
                    @if (!empty($item['size']))
                    <div style="font-size:10px;font-weight:700;margin-top:2px;">{{ $item['size'] }}</div>
                    @endif
                    @if (!empty($item['sku']))
                    <div style="font-size:9px;color:#999;margin-top:1px;">SKU: {{ $item['sku'] }}</div>
                    @endif
                </td>
                <td class="r">
                    <div class="qty-num">{{ number_format((int) $item['quantity']) }}</div>
                    <div class="qty-unit">piece</div>
                </td>
                <td class="r">{{ number_format((float) $item['unit_price'], 2) }} {{ $currency }}</td>
                <td class="r">{{ number_format((float) $item['line_total'], 2) }} {{ $currency }}</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr style="font-weight:700;border-top:2px solid #E87722;">
                <td colspan="4" class="r" style="padding:8px 6px;">
                    Total (excluding taxes &amp; duties)
                </td>
                <td class="r" style="padding:8px 6px;font-size:13px;">
                    {{ number_format((float) $quote->proposal_total, 2) }} {{ $currency }}
                </td>
            </tr>
        </tfoot>
    </table>

    {{-- Terms --}}
    <p class="terms-p" style="margin-top:16px;">
        <strong>Delivery Terms:</strong> {{ $incotermLabel }}
    </p>

    @if ($quote->delivery_location)
    <p class="terms-p">
        <strong>Delivery Destination:</strong> {{ $quote->delivery_location }}
    </p>
    @endif

    @if ($quote->delivery_timeline)
    <p class="terms-p">
        <strong>Lead Time:</strong> {{ $quote->delivery_timeline }}
    </p>
    @endif

    <p class="terms-p">
        <strong>Payment Terms:</strong>
        {{ config('payment.bank_transfer.terms', '50% against order confirmation, balance against bill of lading.') }}
    </p>

    @include('pdf.partials._bank')

    {{-- Customer acceptance — signature confirms acceptance of this proposal.
         Print, sign, and return via the customer portal — an alternative to
         the digital "Accept" link below for customers who prefer a signed
         paper trail. --}}
    <p class="terms-p" style="margin-top:14px;margin-bottom:6px;">
        <strong>Acceptance:</strong> by signing below, the customer confirms acceptance of this proposal.
    </p>
    <table class="sig-table">
        <tr>
            <td style="width:34%;padding-right:10px;">
                <div class="sig-box">
                    <div class="sig-lbl">Date</div>
                    <div class="sig-line"></div>
                </div>
            </td>
            <td style="width:33%;padding-right:10px;">
                <div class="sig-box">
                    <div class="sig-lbl">Signature</div>
                    <div class="sig-line"></div>
                    <div class="sig-caption">Authorised representative</div>
                </div>
            </td>
            <td style="width:33%;">
                <div class="sig-box">
                    <div class="sig-lbl">Company Stamp</div>
                    <div class="sig-line"></div>
                    <div class="sig-caption">If applicable</div>
                </div>
            </td>
        </tr>
    </table>

    {{-- Validity disclaimer --}}
    <p class="terms-p" style="font-size:10px;color:#555;margin-top:14px;font-style:italic;">
        This proposal is valid until
        {{ $quote->proposal_expires_at ? $quote->proposal_expires_at->format('d M Y') : '30 days from issue' }}
        and is subject to product availability at time of order confirmation.
        Prices are quoted in {{ $currency }} and exclude all destination taxes, duties, and local charges.
    </p>

    <p class="terms-p" style="margin-top:12px;">
        To accept this proposal, use the acceptance link provided in your email, or reply with the printed,
        signed copy above uploaded via your customer portal account.
        For questions, contact us at
        <strong>support@okelcor.com</strong>
        quoting proposal {{ $quote->proposal_number }}.
    </p>

    @include('pdf.partials._footer')

</div>
</body>
</html>
