<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subjectLine ?? '' }}</title>
</head>
<body style="font-family: Arial, sans-serif; color: #1f2937; line-height: 1.6;">
    {!! $bodyHtml !!}

    <hr style="margin-top: 32px; border: none; border-top: 1px solid #e5e7eb;">
    <p style="font-size: 12px; color: #6b7280;">
        You are receiving this email from Okelcor.
        <a href="{{ $unsubscribeUrl }}">Unsubscribe</a>
    </p>
</body>
</html>
