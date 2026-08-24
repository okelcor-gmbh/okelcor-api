From {{ $senderName }} <{{ $senderEmail }}>
@if ($forwardedContext)

{{ $forwardedContext }}
@endif

{{ $bodyText }}

Open in the admin panel: {{ $panelUrl }}

--
An internal message from a colleague at Okelcor. Replying to this e-mail goes
straight to {{ $senderName }}'s mailbox; replying in the admin panel keeps it on
the thread where everyone can see it.
