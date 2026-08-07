<?php

namespace App\Http\Requests\Admin;

use App\Rules\StrongPin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a partner organisation, optionally with its first user in the same
 * call — a partner with no users cannot log in, so making the owner optional
 * but same-request keeps the admin from having to remember a second step.
 */
class StorePartnerOrganisationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'             => ['required', 'string', 'max:150'],
            'country'          => ['required', 'string', 'max:100'],
            'country_code'     => ['nullable', 'string', 'size:2'],
            'default_currency' => ['required', 'string', 'size:3', Rule::in(config('partner.currencies', []))],
            'contact_email'    => ['nullable', 'email', 'max:255'],
            'contact_phone'    => ['nullable', 'string', 'max:30'],
            'status'           => ['nullable', Rule::in(['active', 'suspended'])],
            'notes'            => ['nullable', 'string', 'max:5000'],

            // First user — all-or-nothing.
            'owner'            => ['nullable', 'array'],
            'owner.name'       => ['required_with:owner', 'string', 'max:150'],
            'owner.phone'      => ['required_with:owner', 'string', 'max:30'],

            // The admin sets a starting PIN. It is known to at least one other
            // person by construction, so PartnerUser.must_change_pin defaults
            // to true and the app forces a change on first sign-in.
            'owner.pin'        => ['required_with:owner', 'string', new StrongPin()],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('default_currency')) {
            $this->merge(['default_currency' => strtoupper(trim((string) $this->input('default_currency')))]);
        }

        if ($this->has('country_code')) {
            $this->merge(['country_code' => strtoupper(trim((string) $this->input('country_code')))]);
        }
    }
}
