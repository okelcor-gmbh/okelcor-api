<?php

namespace App\Http\Requests\Partner;

use App\Models\PartnerSale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for a reported sale line.
 *
 * Messages are written for a non-technical partner on a phone, not for a
 * developer reading a schema error — this is a mandated tool replacing a paper
 * form, and an entry that is slower or more confusing than paper is the whole
 * failure mode.
 */
class StorePartnerSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxBackdate = (int) config('partner.max_backdate_days', 730);

        return [
            // The offline dedupe key, minted on the device before first send.
            'client_generated_id' => ['required', 'string', 'min:8', 'max:64'],

            // Optional monotonic edit counter. When the client sends it, the
            // server refuses to apply a lower revision over a higher one, so a
            // late-arriving retry of an old version cannot revert a correction.
            'client_revision'     => ['nullable', 'integer', 'min:1', 'max:100000'],

            'sold_at'  => [
                'required',
                'date_format:Y-m-d',
                'before_or_equal:today',
                'after_or_equal:' . now()->subDays($maxBackdate)->toDateString(),
            ],

            'size'      => ['required', 'string', 'max:50'],
            'brand'     => ['nullable', 'string', 'max:100'],
            'tyre_type' => ['nullable', Rule::in(PartnerSale::TYRE_TYPES)],

            'quantity'   => ['required', 'integer', 'min:1', 'max:100000'],
            'unit_price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],

            // `total_amount` is deliberately absent: it is computed server-side
            // from quantity × unit_price and never accepted from the client, so
            // a stored total cannot disagree with the line it came from.

            'currency' => [
                'required',
                'string',
                'size:3',
                Rule::in(config('partner.currencies', [])),
            ],

            'customer_name' => ['nullable', 'string', 'max:150'],
            'notes'         => ['nullable', 'string', 'max:2000'],
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
        return [
            'client_generated_id.required' => 'This entry is missing its device reference. Please try again.',
            'sold_at.required'             => 'Choose the date this was sold.',
            'sold_at.before_or_equal'      => 'The date sold cannot be in the future.',
            'sold_at.after_or_equal'       => 'That date is too far in the past. Contact Okelcor to enter it.',
            'size.required'                => 'Enter the tyre size.',
            'quantity.required'            => 'Enter how many pieces were sold.',
            'quantity.min'                 => 'Quantity must be at least 1.',
            'unit_price.required'          => 'Enter the price per tyre.',
            'currency.required'            => 'Choose the currency.',
            'currency.in'                  => 'That currency is not set up yet. Contact Okelcor to add it.',
        ];
    }
}
