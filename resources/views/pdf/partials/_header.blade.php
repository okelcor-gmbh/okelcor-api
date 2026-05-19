@php
// ── Date ──────────────────────────────────────────────────────────────
$_hdrDate = isset($document) && $document
    ? ($document->issued_at?->format('M j, Y') ?? now()->format('M j, Y'))
    : (isset($invoice) ? $invoice->issued_at->format('M j, Y') : now()->format('M j, Y'));

$_quoteOrNull   = $quote ?? null;
$_hdrCustomerNo = str_pad($_quoteOrNull?->id ?? $order->id ?? 0, 4, '0', STR_PAD_LEFT);

// ── Logo (AVIF → PNG base64 via GD, fallback to CSS wordmark) ─────────
$_logoSrc = null;
$_logoFile = config('company.logo_path', 'okelcor-logo.avif');
$_logoPath = public_path($_logoFile);
if ($_logoPath && file_exists($_logoPath)) {
    $_ext = strtolower(pathinfo($_logoPath, PATHINFO_EXTENSION));
    if ($_ext === 'avif' && function_exists('imagecreatefromavif')) {
        $_img = @imagecreatefromavif($_logoPath);
        if ($_img) {
            ob_start();
            imagepng($_img);
            $_logoSrc = 'data:image/png;base64,' . base64_encode(ob_get_clean());
            imagedestroy($_img);
        }
    } elseif (in_array($_ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'])) {
        $_mime = $_ext === 'jpg' ? 'jpeg' : $_ext;
        $_logoSrc = 'data:image/' . $_mime . ';base64,' . base64_encode(file_get_contents($_logoPath));
    }
}

// ── QR code (base64 SVG data URI — DomPDF img pipeline, more reliable than inline SVG) ──
$_qrSrc = null;
if (config('company.qr_enabled', true)) {
    $_docNum = isset($document) && $document
        ? $document->number
        : (isset($invoice) ? $invoice->invoice_number : '');
    $_qrText = 'https://www.okelcor.com/documents/verify/' . $_docNum;

    try {
        $_renderer = new \BaconQrCode\Renderer\ImageRenderer(
            new \BaconQrCode\Renderer\RendererStyle\RendererStyle(72),
            new \BaconQrCode\Renderer\Image\SvgImageBackEnd()
        );
        $_svgRaw = (new \BaconQrCode\Writer($_renderer))->writeString($_qrText);
        $_qrSrc = 'data:image/svg+xml;base64,' . base64_encode($_svgRaw);
    } catch (\Throwable $_e) {
        $_qrSrc = null;
    }
}
@endphp

<table style="width:100%;border-collapse:collapse;margin-bottom:20px;">
    <tr>
        {{-- Logo (left) --}}
        <td style="vertical-align:middle;width:40%;">
            @if ($_logoSrc)
            <img src="{{ $_logoSrc }}" style="height:38px;max-width:220px;" alt="Okelcor">
            @else
            <div class="ok-logo">OKELCOR</div>
            @endif
        </td>

        {{-- Date / Customer No / Contact (centre-right) --}}
        <td style="vertical-align:top;text-align:right;padding-right:{{ $_qrSrc ? '14px' : '0' }};">
            <div class="hdr-meta-lbl">DATE</div>
            <div class="hdr-meta-val">{{ $_hdrDate }}</div>
            <div class="hdr-meta-lbl">YOUR CUSTOMER NO.</div>
            <div class="hdr-meta-val">{{ $_hdrCustomerNo }}</div>
            <div class="hdr-meta-lbl">YOUR CONTACT</div>
            <div class="hdr-meta-val" style="margin-bottom:0;">{{ config('company.contact', 'Okelcor Support') }}</div>
        </td>

        {{-- QR code (far right, embedded as base64 data URI for reliable DomPDF rendering) --}}
        @if ($_qrSrc)
        <td style="vertical-align:top;text-align:right;width:80px;">
            <img src="{{ $_qrSrc }}" width="72" height="72" alt="">
        </td>
        @endif
    </tr>
</table>
