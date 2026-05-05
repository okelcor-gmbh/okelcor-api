<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ConvertQuoteToOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'delivery'              => ['nullable', 'array'],
            'delivery.address'      => ['nullable', 'string', 'max:300'],
            'delivery.city'         => ['nullable', 'string', 'max:100'],
            'delivery.postal_code'  => ['nullable', 'string', 'max:20'],
            'delivery.country'      => ['nullable', 'string', 'max:100'],
            'delivery.phone'        => ['nullable', 'string', 'max:50'],

            'items'                 => ['required', 'array', 'min:1'],
            'items.*.name'          => ['required', 'string', 'max:200'],
            'items.*.brand'         => ['required', 'string', 'max:100'],
            'items.*.size'          => ['required', 'string', 'max:50'],
            'items.*.sku'           => ['nullable', 'string', 'max:50'],
            'items.*.unit_price'    => ['required', 'numeric', 'min:0'],
            'items.*.quantity'      => ['required', 'integer', 'min:1'],

            'delivery_cost'         => ['nullable', 'numeric', 'min:0'],
            'payment_method'        => ['nullable', 'string', 'max:100'],
            'admin_notes'           => ['nullable', 'string'],
            'promo_code'            => ['nullable', 'string', 'max:50'],
        ];
    }
}
