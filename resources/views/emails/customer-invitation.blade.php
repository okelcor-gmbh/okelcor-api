<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Okelcor account invitation</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 40px auto; background: #ffffff; border-radius: 8px; overflow: hidden; }
        .header { background: #1a1a1a; padding: 30px; text-align: center; }
        .header h1 { color: #ffffff; margin: 0; font-size: 24px; }
        .body { padding: 40px 30px; }
        .body p { color: #444444; line-height: 1.6; margin: 0 0 16px; }
        .body ul { color: #444444; line-height: 1.8; padding-left: 20px; margin: 0 0 16px; }
        .btn { display: inline-block; margin: 24px 0; padding: 14px 32px; background: #e63946; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: bold; font-size: 16px; }
        .highlight { background: #f9f9f9; border-left: 4px solid #e63946; padding: 16px 20px; margin: 20px 0; border-radius: 0 4px 4px 0; }
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
            <p>Hello {{ $customer->first_name }},</p>
            <p>Your Okelcor B2B wholesale account has been approved. You're one step away from accessing our platform.</p>
            <p>Click the button below to set your password and activate your account:</p>
            <p style="text-align:center;">
                <a href="{{ $activationUrl }}" class="btn">Activate My Account</a>
            </p>
            <div class="highlight">
                <p style="margin:0;"><strong>This invitation link expires in 48 hours.</strong><br>
                If it expires, please contact our team to request a new one.</p>
            </div>
            <p>Once activated, you'll have access to:</p>
            <ul>
                <li>Place and track wholesale tyre orders</li>
                <li>Submit and manage quote requests</li>
                <li>View invoices and trade documents</li>
                <li>Manage your delivery addresses</li>
            </ul>
            <p>If you have any questions before activating, reply to this email and our team will be happy to help.</p>
            <p>Welcome aboard,<br><strong>The Okelcor Team</strong></p>
        </div>
        <div class="footer">
            <p>If you're having trouble clicking the button, copy and paste the URL below into your browser:<br>
            <a href="{{ $activationUrl }}" style="color:#e63946;word-break:break-all;">{{ $activationUrl }}</a></p>
        </div>
    </div>
</body>
</html>
