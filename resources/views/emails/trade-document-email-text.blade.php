OKELCOR
================================================================================

{{ strtoupper($documentLabel) }} — ORDER {{ $order->ref }}

Please find your {{ $documentLabel }} for order {{ $order->ref }} attached
to this email.

--------------------------------------------------------------------------------
DOCUMENT DETAILS
--------------------------------------------------------------------------------
Document type   : {{ $documentLabel }}
@if ($document->number)
Document number : {{ $document->number }}
@endif
Order reference : {{ $order->ref }}
Date issued     : {{ $document->issued_at?->format('d M Y') ?? date('d M Y') }}
@if ($document->original_filename)
Filename        : {{ $document->original_filename }}
@endif

@if ($adminMessage)
--------------------------------------------------------------------------------
NOTE FROM OKELCOR

{{ $adminMessage }}

@endif
================================================================================
Questions? Email us at support@okelcor.com
Okelcor — {{ date('Y') }}
