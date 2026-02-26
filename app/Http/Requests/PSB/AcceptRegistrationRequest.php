<?php

namespace App\Http\Requests\PSB;

use Illuminate\Foundation\Http\FormRequest;

class AcceptRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'class_level' => ['required', 'string', 'exists:class_levels,slug'],
        ];
    }
}
