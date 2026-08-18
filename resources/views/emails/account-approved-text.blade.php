Hello {{ $customer->first_name }},

Good news — your Okelcor B2B wholesale account has been APPROVED.

@if ($requiresEmailVerification)
One last step: please confirm your email address. Once confirmed, you can log in with the password you chose at registration.
@if (! empty($verifyUrl))

Confirm your email address:
{{ $verifyUrl }}

This link is valid for 24 hours. If it has expired by the time you use it, reset your password at {{ $loginUrl }} — that confirms your address as well.
@endif
@else
You can now log in and start using your account:
{{ $loginUrl }}
@endif

With your approved account you can:
- Place and track wholesale tyre orders
- Submit and manage quote requests
- View invoices and trade documents
- Manage your delivery addresses

If you have any questions, contact us at {{ $supportEmail }}.

Welcome aboard,
The Okelcor Team
