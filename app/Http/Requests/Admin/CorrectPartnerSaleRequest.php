<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Partner\StorePartnerSaleRequest;
use App\Models\PartnerSale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Okelcor-side correction of a partner's reported sale, after the partner's
 * own edit window has closed.
 *
 * Bounds are deliberately identical to the partner's own create and update
 * rules — an admin correcting a figure must not be a way around a validation
 * rule the partner is held to. The one addition is `reason`, which is
 * required: an unexplained rewrite of someone else's reported revenue is
 * worse for the books than leaving the wrong figure visible.
 */
class CorrectPartnerSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxBackdate = (int) config('partner.max_backdate_days', 730);

        return [
            'reason' => ['required', 'string', 'min:5', 'max:2000'],

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
        return (new StorePartnerSaleRequest())->messages() + [
            'reason.required' => 'Say why this entry is being corrected — the partner reported these figures, and the trail has to show who changed them and why.',
            'reason.min'      => 'Give a real reason, not a placeholder — this is what the partner and finance will read.',
        ];
    }
}
