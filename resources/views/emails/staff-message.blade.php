<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>{{ $senderName }} — Okelcor internal</title>
</head>
<body style="margin:0;padding:0;background-color:#f5f5f5;font-family:Arial,Helvetica,sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f5f5f5;">
<tr><td align="center" style="padding:32px 16px;">

<table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background-color:#ffffff;">

    <tr>
        <td style="background-color:#171a20;height:3px;font-size:0;line-height:0;">&nbsp;</td>
    </tr>

    <tr>
        <td style="padding:28px 36px 20px 36px;border-bottom:1px solid #eeeeee;">
            <span style="font-family:Arial,Helvetica,sans-serif;font-size:18px;font-weight:700;letter-spacing:2px;color:#171a20;text-transform:uppercase;">OKELCOR</span>
            <span style="display:inline-block;margin-left:10px;padding:3px 8px;background-color:#eef0f3;border-radius:3px;font-size:10px;font-weight:700;letter-spacing:1px;color:#5c5e62;text-transform:uppercase;">Internal</span>
        </td>
    </tr>

    <tr>
        <td style="padding:24px 36px 0 36px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#5c5e62;">
            From <strong style="color:#171a20;">{{ $senderName }}</strong> &lt;{{ $senderEmail }}&gt;
        </td>
    </tr>

    @if ($forwardedContext)
    <tr>
        <td style="padding:16px 36px 0 36px;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f8f9fa;border-left:3px solid #f4511e;">
                <tr><td style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#5c5e62;">
                    {{ $forwardedContext }}
                </td></tr>
            </table>
        </td>
    </tr>
    @endif

    <tr>
        <td style="padding:24px 36px 32px 36px;font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#171a20;line-height:1.6;">
            {{-- Already sanitized (RichEmailHtmlSanitizer) before this Mailable was constructed. Signature, if any, is already appended. --}}
            {!! $bodyHtml !!}
        </td>
    </tr>

    <tr>
        <td style="padding:0 36px 32px 36px;">
            <a href="{{ $panelUrl }}" style="display:inline-block;padding:11px 22px;background-color:#171a20;color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;text-decoration:none;">Open in the admin panel</a>
        </td>
    </tr>

    <tr>
        <td style="padding:20px 36px 28px 36px;border-top:1px solid #eeeeee;">
            <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:11px;color:#9a9c9f;">
                An internal message from a colleague at Okelcor. Replying to this
                e-mail goes straight to {{ $senderName }}'s mailbox; replying in the
                admin panel keeps it on the thread where everyone can see it.
            </p>
        </td>
    </tr>

</table>

</td></tr>
</table>

</body>
</html>
