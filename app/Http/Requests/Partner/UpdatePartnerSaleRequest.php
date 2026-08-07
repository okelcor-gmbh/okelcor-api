<?php

namespace App\Http\Requests\Partner;

use App\Models\PartnerSale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH of an existing sale, once it has a server id.
 *
 * Every field is `sometimes` — the client sends only what changed. Bounds are
 * identical to StorePartnerSaleRequest: an edit must not be a way around a
 * validation rule that create enforces.
 */
class UpdatePartnerSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxBackdate = (int) config('partner.max_backdate_days', 730);

        return [
            'client_revision' => ['nullable', 'integer', 'min:1', 'max:100000'],

            'sold_at' => [
                'sometimes',
                'required',
                'date_format:Y-m-d',
                'before_or_equal:today',
                'after_or_equal:' . now()->subDays($maxBackdate)->toDateString(),
            ],

            'size'      => ['sometimes', 'required', 'string', 'max:50'],
            'brand'     => ['sometimes', 'nullable', 'string', 'max:100'],
            'tyre_type' => ['sometimes', 'nullable', Rule::in(PartnerSale::TYRE_TYPES)],

            'quantity'   => ['sometimes', 'required', 'integer', 'min:1', 'max:100000'],
            'unit_price' => ['sometimes', 'required', 'numeric', 'min:0', 'max:99999999.99'],

            'currency' => [
                'sometimes',
                'required',
                'string',
                'size:3',
                Rule::in(config('partner.currencies', [])),
            ],

            'customer_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'notes'         => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('currency')) {
            $this->merge(['currency' => strtoupper(trim((string) $this->input('currency')))]);
        }

        if ($this->has('tyre_type')) {
            $this->merge(['tyre_type' => strtolower(trim((string) $this->input('tyre_type')))]);
        }
    }

    public function messages(): array
    {
        return (new StorePartnerSaleRequest())->messages();
    }
}
