<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>{{ $senderName }} — Okelcor</title>
</head>
<body style="margin:0;padding:0;background-color:#f5f5f5;font-family:Arial,Helvetica,sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f5f5f5;">
<tr><td align="center" style="padding:32px 16px;">

<table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background-color:#ffffff;">

    <tr>
        <td style="background-color:#f4511e;height:3px;font-size:0;line-height:0;">&nbsp;</td>
    </tr>

    <tr>
        <td style="padding:28px 36px 20px 36px;border-bottom:1px solid #eeeeee;">
            <span style="font-family:Arial,Helvetica,sans-serif;font-size:18px;font-weight:700;letter-spacing:2px;color:#171a20;text-transform:uppercase;">OKELCOR</span>
        </td>
    </tr>

    <tr>
        <td style="padding:32px 36px;font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#171a20;line-height:1.6;">
            {{-- Already sanitized (RichEmailHtmlSanitizer) before this Mailable was ever constructed. --}}
            {!! $bodyHtml !!}

            @if($signatureHtml)
                <div style="margin-top:24px;padding-top:16px;border-top:1px solid #eeeeee;">
                    {!! $signatureHtml !!}
                </div>
            @endif
        </td>
    </tr>

    <tr>
        <td style="padding:20px 36px 28px 36px;border-top:1px solid #eeeeee;">
            <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:11px;color:#9a9c9f;">
                Sent via Okelcor by {{ $senderName }}. Reply to this e-mail to reach them directly.
            </p>
        </td>
    </tr>

</table>

</td></tr>
</table>

</body>
</html>
