OKELCOR — PROPOSAL {{ $quote->proposal_number }}
======================================================

Dear {{ $quote->full_name ?? 'Valued Customer' }},

Thank you for your enquiry. Please find below our proposal.

{{ $adminMessage ? "Message from Okelcor:\n" . $adminMessage . "\n\n" : '' }}PROPOSAL DETAILS
----------------
Proposal No. : {{ $quote->proposal_number }}
Quote Ref.   : {{ $quote->ref_number }}
{{ $quote->company_name ? 'Company      : ' . $quote->company_name . "\n" : '' }}Date         : {{ now()->format('d M Y') }}
{{ $quote->proposal_expires_at ? 'Valid Until  : ' . $quote->proposal_expires_at->format('d M Y') . "\n" : '' }}
LINE ITEMS
----------
@foreach ($quote->proposal_items ?? [] as $i => $item)
{{ $i + 1 }}. {{ $item['brand'] ?? '' }} {{ $item['name'] }}{{ !empty($item['size']) ? ' (' . $item['size'] . ')' : '' }}
   Qty: {{ $item['quantity'] }}  x  {{ $quote->proposal_currency }} {{ number_format($item['unit_price'], 2) }}  =  {{ $quote->proposal_currency }} {{ number_format($item['line_total'], 2) }}
@endforeach

TOTAL: {{ $quote->proposal_currency }} {{ number_format($quote->proposal_total, 2) }}

------------------------------------------------------
ACCEPT THIS PROPOSAL:
{{ $acceptUrl }}

DECLINE:
{{ str_replace('/accept/', '/reject/', $acceptUrl) }}
------------------------------------------------------

@if ($quote->proposal_expires_at)
This proposal is valid until {{ $quote->proposal_expires_at->format('d M Y') }}.
@endif

Questions? Email us at support@okelcor.com quoting proposal {{ $quote->proposal_number }}.

Kind regards,
{{ $sender ? ($sender->first_name . ' ' . $sender->last_name) : 'The Okelcor Team' }}
Okelcor
