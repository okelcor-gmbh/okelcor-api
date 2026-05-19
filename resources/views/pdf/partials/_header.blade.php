@php
// Supports both trade documents ($document) and final invoices ($invoice)
$_hdrDate = isset($document) && $document
    ? ($document->issued_at?->format('M j, Y') ?? now()->format('M j, Y'))
    : (isset($invoice) ? $invoice->issued_at->format('M j, Y') : now()->format('M j, Y'));

$_quoteOrNull    = $quote ?? null;
$_hdrCustomerNo  = str_pad($_quoteOrNull?->id ?? $order->id ?? 0, 4, '0', STR_PAD_LEFT);
@endphp
<table style="width:100%;border-collapse:collapse;margin-bottom:20px;">
    <tr>
        <td style="vertical-align:top;">
            <div class="ok-logo">OKELCOR</div>
        </td>
        <td style="vertical-align:top;text-align:right;">
            <div class="hdr-meta-lbl">DATE</div>
            <div class="hdr-meta-val">{{ $_hdrDate }}</div>
            <div class="hdr-meta-lbl">YOUR CUSTOMER NO.</div>
            <div class="hdr-meta-val">{{ $_hdrCustomerNo }}</div>
            <div class="hdr-meta-lbl">YOUR CONTACT</div>
            <div class="hdr-meta-val" style="margin-bottom:0;">{{ config('company.contact', 'Okelcor Support') }}</div>
        </td>
    </tr>
</table>
