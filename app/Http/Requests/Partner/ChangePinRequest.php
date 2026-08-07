<?php

namespace App\Http\Requests\Partner;

use App\Rules\StrongPin;
use Illuminate\Foundation\Http\FormRequest;

class ChangePinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_pin' => ['required', 'string', 'max:20'],
            'new_pin'     => ['required', 'string', new StrongPin()],
        ];
    }

    public function messages(): array
    {
        return [
            'current_pin.required' => 'Enter your current PIN.',
            'new_pin.required'     => 'Enter your new PIN.',
        ];
    }
}
