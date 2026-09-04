<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>How did we do?</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 40px auto; background: #ffffff; border-radius: 8px; overflow: hidden; }
        .header { background: #171a20; padding: 30px; text-align: center; }
        .header h1 { color: #ffffff; margin: 0; font-size: 24px; }
        .body { padding: 40px 30px; }
        .body p { color: #444444; line-height: 1.6; margin: 0 0 16px; }
        .btn { display: inline-block; margin: 24px 0; padding: 14px 32px; background: #f4511e; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px; }
        .footer { padding: 20px 30px; background: #f9f9f9; text-align: center; }
        .footer p { color: #999999; font-size: 12px; margin: 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Okelcor</h1>
        </div>
        <div class="body">
            <p>Hello {{ $order->customer_name ?: 'there' }},</p>
            <p>Your order {{ $order->ref }} has been delivered. We hope everything arrived exactly as it should.</p>
            <p>If you have two minutes, a short review helps other buyers know what to expect from us, and helps us keep improving.</p>
            <p style="text-align:center;">
                <a href="{{ $reviewUrl }}" class="btn">Leave a review</a>
            </p>
            <p>If anything was not right with your delivery, reply to this e-mail instead and a person will pick it up.</p>
        </div>
        <div class="footer">
            <p>Okelcor GmbH, Landsberger Str. 155, 80687 Munich, Germany</p>
        </div>
    </div>
</body>
</html>
