<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for the /tyre-wholesaler SEO/ads landing form.
 *
 * Accepts the landing form's own field names (name/company/interest/volume)
 * and is deliberately permissive about phone (the campaign form omits it).
 * Conversion-attribution fields are accepted but optional — they are stored
 * in lead_metadata and never block the submission.
 */
class StoreWholesalerLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Accept both the landing field names and the canonical names so the
     * frontend can post either shape.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'full_name'    => $this->input('full_name', $this->input('name')),
            'company_name' => $this->input('company_name', $this->input('company')),
        ]);
    }

    public function rules(): array
    {
        return [
            // Core lead fields
            'full_name'    => ['required', 'string', 'max:200'],
            'company_name' => ['required', 'string', 'max:200'],
            'email'        => ['required', 'email', 'max:255'],
            'country'      => ['required', 'string', 'max:100'],
            'phone'        => ['nullable', 'string', 'max:50'],

            // Landing-specific selections
            'interest'     => ['required', Rule::in(['PCR', 'TBR', 'OTR', 'Value', 'Mixed'])],
            'volume'       => ['required', Rule::in(['less-than-1', '1-to-5', '5-plus'])],
            'notes'        => ['nullable', 'string', 'max:2000'],

            // Conversion attribution (optional, stored in lead_metadata)
            'landing_page' => ['nullable', 'string', 'max:255'],
            'utm_source'   => ['nullable', 'string', 'max:255'],
            'utm_medium'   => ['nullable', 'string', 'max:255'],
            'utm_campaign' => ['nullable', 'string', 'max:255'],
            'utm_term'     => ['nullable', 'string', 'max:255'],
            'utm_content'  => ['nullable', 'string', 'max:255'],
            'gclid'        => ['nullable', 'string', 'max:512'],
            'fbclid'       => ['nullable', 'string', 'max:512'],
            'referrer'     => ['nullable', 'string', 'max:1024'],
        ];
    }
}
