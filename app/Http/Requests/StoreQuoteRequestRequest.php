<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreQuoteRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Contact
            'full_name'         => ['required', 'string', 'max:200'],
            'contact_person'    => ['nullable', 'string', 'max:150'],
            'company_name'      => ['nullable', 'string', 'max:200'],
            'company_address'   => ['nullable', 'string', 'max:300'],
            'company_city'      => ['nullable', 'string', 'max:100'],
            'company_postal_code' => ['nullable', 'string', 'max:30'],
            'email'             => ['required', 'email', 'max:255'],
            'phone'             => ['nullable', 'string', 'max:50'],
            'country'           => ['required', 'string', 'max:100'],
            'business_type'     => ['nullable', 'string', 'max:100'],
            'customer_type'     => ['nullable', 'string', 'in:b2b,b2c'],

            // Product
            'tyre_category'     => ['required', 'string', 'max:100'],
            'brand_preference'  => ['nullable', 'string', 'max:200'],
            'tyre_size'         => ['nullable', 'string', 'max:100'],   // legacy — kept for BC
            'quantity'          => ['required', 'string', 'max:100'],   // legacy — kept for BC
            'tyre_condition'    => ['nullable', 'string', 'in:new,used'],
            'used_tyre_grade'   => ['nullable', 'string', 'in:grade_a,grade_b,mixed'],
            'used_tyre_notes'   => ['nullable', 'string', 'max:500'],

            // Multi-row tyre items
            'tyre_items'            => ['nullable', 'array'],
            'tyre_items.*.size'     => ['required_with:tyre_items', 'string', 'max:100'],
            'tyre_items.*.quantity' => ['required_with:tyre_items', 'string', 'max:100'],

            // Delivery & logistics
            'budget_range'          => ['nullable', 'string', 'max:100'],
            'delivery_location'     => ['required', 'string', 'max:300'],
            'delivery_timeline'     => ['nullable', 'string', 'max:100'],
            'delivery_address'      => ['nullable', 'string', 'max:300'],
            'delivery_city'         => ['nullable', 'string', 'max:100'],
            'delivery_postal_code'  => ['nullable', 'string', 'max:30'],
            'incoterm'              => ['nullable', 'string', 'in:DAP,DDP,EXW,FOB,CIF,Custom'],
            'incoterm_type'         => ['nullable', 'string', 'in:delivery_terms,shipping_terms'],

            // Other
            'notes'         => ['required', 'string', 'max:2000'],
            'vat_number'    => ['nullable', 'string', 'min:4', 'max:20'],
            'attachment'    => ['nullable', 'file', 'mimes:pdf,csv,xls,xlsx', 'max:10240'],
        ];
    }
}
