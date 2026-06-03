<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Proposal from Okelcor</title>
<style>
  body { margin:0; padding:0; background:#f4f4f4; font-family:Arial,Helvetica,sans-serif; }
  .wrapper { max-width:620px; margin:30px auto; background:#ffffff; border-top:3px solid #E87722; }
  .header  { padding:28px 36px 16px; border-bottom:1px solid #eee; }
  .logo    { font-size:22px; font-weight:700; color:#E87722; letter-spacing:1px; }
  .body    { padding:28px 36px; }
  h2       { margin:0 0 16px; font-size:20px; color:#1a1a1a; }
  p        { margin:0 0 14px; font-size:14px; color:#444; line-height:1.6; }
  .info-table { width:100%; border-collapse:collapse; margin:20px 0; font-size:13px; }
  .info-table th { text-align:left; color:#888; font-weight:600; padding:6px 0; width:40%; }
  .info-table td { color:#1a1a1a; padding:6px 0; }
  .items-table { width:100%; border-collapse:collapse; margin:20px 0; font-size:13px; }
  .items-table th { background:#f7f7f7; text-align:left; padding:8px 10px; color:#555; border-bottom:2px solid #eee; }
  .items-table td { padding:8px 10px; border-bottom:1px solid #f0f0f0; color:#333; }
  .items-table .r { text-align:right; }
  .total-row td { font-weight:700; color:#1a1a1a; border-top:2px solid #eee; }
  .btn-accept { display:inline-block; background:#E87722; color:#ffffff; text-decoration:none;
                padding:14px 32px; border-radius:4px; font-weight:700; font-size:15px; margin:4px 8px 4px 0; }
  .btn-reject { display:inline-block; background:#ffffff; color:#666; text-decoration:none;
                padding:13px 28px; border-radius:4px; font-weight:600; font-size:14px;
                border:1px solid #ccc; margin:4px 0; }
  .expiry     { font-size:12px; color:#999; margin-top:10px; }
  .admin-msg  { background:#fff8f0; border-left:3px solid #E87722; padding:12px 16px; margin:20px 0; font-size:13px; color:#555; }
  .footer     { padding:18px 36px; border-top:1px solid #eee; font-size:12px; color:#aaa; }
</style>
</head>
<body>
<div class="wrapper">

  <div class="header">
    <div class="logo">OKELCOR</div>
  </div>

  <div class="body">
    <h2>Your Proposal — {{ $quote->proposal_number }}</h2>

    <p>Dear {{ $quote->full_name ?? 'Valued Customer' }},</p>
    <p>
      Thank you for your enquiry. Please find below our proposal in response to your request.
    </p>

    @if ($adminMessage)
    <div class="admin-msg">{{ $adminMessage }}</div>
    @endif

    <table class="info-table">
      <tr>
        <th>Proposal No.</th>
        <td>{{ $quote->proposal_number }}</td>
      </tr>
      <tr>
        <th>Quote Reference</th>
        <td>{{ $quote->ref_number }}</td>
      </tr>
      @if ($quote->company_name)
      <tr>
        <th>Company</th>
        <td>{{ $quote->company_name }}</td>
      </tr>
      @endif
      <tr>
        <th>Date</th>
        <td>{{ now()->format('d M Y') }}</td>
      </tr>
      @if ($quote->proposal_expires_at)
      <tr>
        <th>Valid Until</th>
        <td>{{ $quote->proposal_expires_at->format('d M Y') }}</td>
      </tr>
      @endif
    </table>

    @if ($quote->proposal_items && count($quote->proposal_items) > 0)
    <table class="items-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Description</th>
          <th class="r">Qty</th>
          <th class="r">Unit Price</th>
          <th class="r">Line Total</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($quote->proposal_items as $i => $item)
        <tr>
          <td>{{ $i + 1 }}</td>
          <td>
            <strong>{{ $item['brand'] ?? '' }} {{ $item['name'] }}</strong>
            @if (!empty($item['size']))
              <div style="font-size:11px;color:#777;">{{ $item['size'] }}</div>
            @endif
            @if (!empty($item['sku']))
              <div style="font-size:11px;color:#aaa;">SKU: {{ $item['sku'] }}</div>
            @endif
          </td>
          <td class="r">{{ number_format($item['quantity']) }}</td>
          <td class="r">{{ $quote->proposal_currency }} {{ number_format($item['unit_price'], 2) }}</td>
          <td class="r">{{ $quote->proposal_currency }} {{ number_format($item['line_total'], 2) }}</td>
        </tr>
        @endforeach
      </tbody>
      <tfoot>
        <tr class="total-row">
          <td colspan="4" class="r">Total</td>
          <td class="r">{{ $quote->proposal_currency }} {{ number_format($quote->proposal_total, 2) }}</td>
        </tr>
      </tfoot>
    </table>
    @endif

    <p style="margin-top:24px;">
      Please review this proposal and let us know your decision using the buttons below:
    </p>

    <div style="margin:24px 0;">
      <a href="{{ $acceptUrl }}" class="btn-accept">Accept Proposal</a>
      <a href="{{ str_replace('/accept/', '/reject/', $acceptUrl) }}" class="btn-reject">Decline</a>
    </div>

    @if ($quote->proposal_expires_at)
    <p class="expiry">This proposal is valid until {{ $quote->proposal_expires_at->format('d M Y') }}.</p>
    @endif

    <p style="margin-top:24px;">
      If you have any questions, please contact us at
      <a href="mailto:support@okelcor.com" style="color:#E87722;">support@okelcor.com</a>
      and reference proposal number <strong>{{ $quote->proposal_number }}</strong>.
    </p>

    <p>Kind regards,<br/>
    <strong>{{ $sender ? ($sender->first_name . ' ' . $sender->last_name) : 'The Okelcor Team' }}</strong><br/>
    Okelcor</p>
  </div>

  <div class="footer">
    &copy; {{ date('Y') }} Okelcor. All rights reserved. &mdash;
    <a href="mailto:support@okelcor.com" style="color:#aaa;">support@okelcor.com</a>
  </div>

</div>
</body>
</html>
