Hello {{ $customer->first_name }},

Good news — your Okelcor B2B wholesale account has been APPROVED.

@if ($requiresEmailVerification)
One last step: please verify your email address first. Once verified, you can log in with the password you chose at registration.
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
