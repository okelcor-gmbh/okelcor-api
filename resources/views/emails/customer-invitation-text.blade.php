{{ __('emails.invitation.greeting', ['name' => $customer->first_name]) }}

{{ __('emails.invitation.approved') }}

{{ __('emails.invitation.cta_intro') }}

{{ $activationUrl }}

{{ __('emails.invitation.expiry') }} {{ __('emails.invitation.expiry_note') }}

{{ __('emails.invitation.access_intro') }}
- {{ __('emails.invitation.access_orders') }}
- {{ __('emails.invitation.access_quotes') }}
- {{ __('emails.invitation.access_docs') }}
- {{ __('emails.invitation.access_addresses') }}

{{ __('emails.invitation.questions') }}

{{ __('emails.invitation.signoff') }}
{{ __('emails.invitation.team') }}
