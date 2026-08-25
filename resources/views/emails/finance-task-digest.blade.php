<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:Helvetica,Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:32px 0;">
<tr><td align="center">
<table width="640" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.06);">
  <tr>
    <td style="background:#171a20;padding:28px 36px 22px;">
      <div style="display:inline-block;width:36px;height:4px;background:#f4511e;border-radius:2px;margin-bottom:14px;"></div>
      <h1 style="margin:0;color:#ffffff;font-size:20px;font-weight:700;letter-spacing:-0.02em;">Your finance tasks</h1>
      <p style="margin:6px 0 0;color:rgba(255,255,255,0.55);font-size:13px;">
        {{ $summary['open'] }} open
        @if(($summary['overdue'] ?? 0) > 0) &middot; <span style="color:#fca5a5;font-weight:700;">{{ $summary['overdue'] }} overdue</span> @endif
        @if(($summary['due_today'] ?? 0) > 0) &middot; {{ $summary['due_today'] }} due today @endif
      </p>
    </td>
  </tr>
  <tr>
    <td style="padding:26px 36px 8px;">
      <p style="margin:0 0 14px;font-size:15px;font-weight:600;color:#171a20;">Hi {{ $recipientName }},</p>
      <p style="margin:0 0 18px;font-size:14px;line-height:1.6;color:#5c5e62;">
        This is everything currently tagged to you on the finance snapshot, in one report.
        Update each item's status from your My Work page — finance is notified automatically.
      </p>
      <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px;">
        <tr>
          <th align="left"  style="padding:7px 8px;border-bottom:2px solid #e5e7eb;color:#8c8f94;font-size:11px;text-transform:uppercase;letter-spacing:0.05em;">Ref</th>
          <th align="left"  style="padding:7px 8px;border-bottom:2px solid #e5e7eb;color:#8c8f94;font-size:11px;text-transform:uppercase;letter-spacing:0.05em;">What</th>
          <th align="right" style="padding:7px 8px;border-bottom:2px solid #e5e7eb;color:#8c8f94;font-size:11px;text-transform:uppercase;letter-spacing:0.05em;">Amount</th>
          <th align="left"  style="padding:7px 8px;border-bottom:2px solid #e5e7eb;color:#8c8f94;font-size:11px;text-transform:uppercase;letter-spacing:0.05em;">Due</th>
        </tr>
        @foreach($tasks as $t)
        <tr>
          <td style="padding:8px;border-bottom:1px solid #f0f0f0;font-weight:700;color:#171a20;white-space:nowrap;">{{ $t['ref'] }}</td>
          <td style="padding:8px;border-bottom:1px solid #f0f0f0;color:#5c5e62;">
            {{ ucwords(strtolower($t['category'])) }}@if($t['client']) — {{ $t['client'] }}@endif
            @if($t['comment'])<br><span style="color:#9ca3af;font-style:italic;font-size:12px;">{{ $t['comment'] }}</span>@endif
          </td>
          <td align="right" style="padding:8px;border-bottom:1px solid #f0f0f0;font-weight:700;color:#171a20;white-space:nowrap;">{{ number_format($t['amount'], 2) }}</td>
          <td style="padding:8px;border-bottom:1px solid #f0f0f0;white-space:nowrap;">
            @if(($t['overdue_days'] ?? 0) > 0)
              <span style="color:#dc2626;font-weight:700;">{{ $t['date'] }} ({{ $t['overdue_days'] }}d overdue)</span>
            @elseif($t['date'])
              <span style="color:#5c5e62;">{{ $t['date'] }}</span>
            @else
              <span style="color:#c2c6cc;">—</span>
            @endif
          </td>
        </tr>
        @endforeach
      </table>
    </td>
  </tr>
  <tr>
    <td style="padding:20px 36px 30px;">
      <table cellpadding="0" cellspacing="0"><tr>
        <td style="background:#f4511e;border-radius:100px;">
          <a href="{{ $panelUrl }}" style="display:inline-block;padding:12px 30px;color:#ffffff;font-size:14px;font-weight:700;text-decoration:none;">Open My Work →</a>
        </td>
      </tr></table>
    </td>
  </tr>
  <tr>
    <td style="background:#f5f5f5;padding:16px 36px;border-top:1px solid #efefef;">
      <p style="margin:0;font-size:12px;color:#8c8f94;">
        One report per day, only while you have open finance tasks. Complete or cancel a task and it drops out.
      </p>
    </td>
  </tr>
</table>
</td></tr>
</table>
</body>
</html>
