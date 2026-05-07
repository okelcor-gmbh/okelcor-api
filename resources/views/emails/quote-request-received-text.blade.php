OKELCOR — Admin Notification
New Quote Request Received
================================================================================

Ref: {{ $quote->ref_number }}
Date: {{ $quote->created_at?->format('d M Y, H:i') }} UTC

Reply to this email to contact the customer directly.

────────────────────────────────────────────────────────────────────────────────
CONTACT
────────────────────────────────────────────────────────────────────────────────
Name:             {{ $quote->full_name }}
@if ($quote->contact_person)
Contact person:   {{ $quote->contact_person }}
@endif
@if ($quote->company_name)
Company:          {{ $quote->company_name }}
@endif
@if ($quote->company_address || $quote->company_city)
Company address:  @if ($quote->company_address){{ $quote->company_address }}, @endif@if ($quote->company_postal_code){{ $quote->company_postal_code }} @endif{{ $quote->company_city }}
@endif
Email:            {{ $quote->email }}
@if ($quote->phone)
Phone:            {{ $quote->phone }}
@endif
Country:          {{ $quote->country }}
@if ($quote->business_type)
Business type:    {{ ucwords(str_replace('_', ' ', $quote->business_type)) }}
@endif
@if ($quote->vat_number)
VAT number:       {{ $quote->vat_number }}@if ($quote->vat_valid !== null) ({{ $quote->vat_valid ? 'VERIFIED' : 'NOT VERIFIED' }})@endif
@endif

────────────────────────────────────────────────────────────────────────────────
TYRE REQUEST
────────────────────────────────────────────────────────────────────────────────
@if ($quote->tyre_category)
Category:         {{ strtoupper($quote->tyre_category) }}
@endif
@if ($quote->brand_preference)
Brand:            {{ $quote->brand_preference }}
@endif
@if ($quote->tyre_condition)
Condition:        {{ ucfirst($quote->tyre_condition) }}
@endif
@if ($quote->tyre_condition === 'used' && $quote->used_tyre_grade)
Used grade:       {{ ucwords(str_replace('_', ' ', $quote->used_tyre_grade)) }}
@endif
@if ($quote->tyre_condition === 'used' && $quote->used_tyre_notes)
Condition notes:  {{ $quote->used_tyre_notes }}
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
Size:             {{ $quote->tyre_size }}
@endif
@if ($quote->quantity)
Quantity:         {{ $quote->quantity }}
@endif
@endif

────────────────────────────────────────────────────────────────────────────────
LOGISTICS
────────────────────────────────────────────────────────────────────────────────
@if ($quote->delivery_location)
Destination:      {{ $quote->delivery_location }}
@endif
@if ($quote->delivery_address || $quote->delivery_city)
Delivery address: @if ($quote->delivery_address){{ $quote->delivery_address }}, @endif@if ($quote->delivery_postal_code){{ $quote->delivery_postal_code }} @endif{{ $quote->delivery_city }}
@endif
@if ($quote->delivery_timeline)
Timeline:         {{ $quote->delivery_timeline }}
@endif
@if ($quote->incoterm)
Incoterm:         {{ strtoupper($quote->incoterm) }}@if ($quote->incoterm_type) ({{ ucwords(str_replace('_', ' ', $quote->incoterm_type)) }})@endif
@endif
@if ($quote->budget_range)
Budget range:     {{ $quote->budget_range }}
@endif

@if ($quote->notes)
────────────────────────────────────────────────────────────────────────────────
NOTES FROM CUSTOMER
────────────────────────────────────────────────────────────────────────────────
{{ $quote->notes }}

@endif
@if ($quote->attachment_original_name)
────────────────────────────────────────────────────────────────────────────────
ATTACHMENT
────────────────────────────────────────────────────────────────────────────────
Filename: {{ $quote->attachment_original_name }}@if ($quote->attachment_size) ({{ round($quote->attachment_size / 1024) }} KB)@endif
Available in the admin panel.

@endif
================================================================================
Okelcor admin notification — {{ date('Y') }}
