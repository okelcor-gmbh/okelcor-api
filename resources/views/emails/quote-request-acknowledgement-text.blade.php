OKELCOR
We received your quote request
================================================================================

Hello {{ explode(' ', trim($quote->full_name))[0] }},

Thank you for your enquiry. Our team will review your request and contact you
with a quotation, usually within 1 business day.

────────────────────────────────────────────────────────────────────────────────
YOUR REQUEST SUMMARY
────────────────────────────────────────────────────────────────────────────────
Quote reference:  {{ $quote->ref_number }}
Submitted:        {{ $quote->created_at?->format('d M Y, H:i') }} UTC
@if ($quote->tyre_category)
Category:         {{ strtoupper($quote->tyre_category) }}
@endif
@if ($quote->brand_preference)
Brand preference: {{ $quote->brand_preference }}
@endif
@if ($quote->tyre_condition)
Condition:        {{ ucfirst($quote->tyre_condition) }}
@endif

@if (!empty($quote->tyre_items) && count($quote->tyre_items) > 0)
Items requested:
  Size                          Quantity
  --------------------------------------------------------
@foreach ($quote->tyre_items as $item)
  {{ str_pad($item['size'] ?? '—', 30) }}{{ $item['quantity'] ?? '—' }}
@endforeach

@else
@if ($quote->tyre_size)
Size requested:   {{ $quote->tyre_size }}
@endif
@if ($quote->quantity)
Quantity:         {{ $quote->quantity }}
@endif

@endif
@if ($quote->delivery_location)
Destination:      {{ $quote->delivery_location }}
@endif
@if ($quote->incoterm)
Shipping term:    {{ strtoupper($quote->incoterm) }}
@endif
@if ($quote->delivery_timeline)
Timeline:         {{ $quote->delivery_timeline }}
@endif
@if ($quote->vat_number)
VAT number:       {{ $quote->vat_number }}@if ($quote->vat_valid) (verified)@endif
@endif

────────────────────────────────────────────────────────────────────────────────
WHAT HAPPENS NEXT?
────────────────────────────────────────────────────────────────────────────────
1. Our sourcing team will review availability and pricing for your requested tyres.
2. We will send you a detailed quote by email, usually within 1 business day.
3. Once you approve the quote, we will create an order and arrange payment and delivery.

================================================================================
Questions? Email us at support@okelcor.com
Okelcor — {{ date('Y') }}
