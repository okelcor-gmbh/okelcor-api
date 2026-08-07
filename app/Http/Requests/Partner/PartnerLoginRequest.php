<?php

namespace App\Http\Requests\Partner;

use Illuminate\Foundation\Http\FormRequest;

class PartnerLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:30'],

            // Deliberately NOT applying the StrongPin rule here. Login must
            // validate against what is stored, not against today's policy —
            // otherwise tightening the policy would lock out every existing
            // partner with a 422 instead of prompting them to change it.
            'pin'   => ['required', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.required' => 'Enter your phone number.',
            'pin.required'   => 'Enter your PIN.',
        ];
    }
}
