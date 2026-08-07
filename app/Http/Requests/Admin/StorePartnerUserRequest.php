<?php

namespace App\Http\Requests\Admin;

use App\Rules\StrongPin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePartnerUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'  => ['required', 'string', 'max:150'],

            // Uniqueness is checked in the controller against the NORMALISED
            // phone, not here: "+233 24 123 4567" and "0241234567" must collide,
            // and a `unique:` rule on the raw input would let both through.
            'phone' => ['required', 'string', 'max:30'],

            'pin'   => ['required', 'string', new StrongPin()],
            'role'  => ['nullable', Rule::in(['owner', 'staff'])],
        ];
    }
}
