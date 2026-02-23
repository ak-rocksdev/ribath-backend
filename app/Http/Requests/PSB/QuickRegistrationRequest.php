<?php

namespace App\Http\Requests\PSB;

use Illuminate\Foundation\Http\FormRequest;

class QuickRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'registrant_type' => ['required', 'in:guardian,student'],
            'full_name' => ['required', 'string', 'max:100'],
            'birth_place' => ['nullable', 'string', 'max:100'],
            'birth_date' => ['required', 'date', 'before:today'],
            'gender' => ['required', 'in:L,P'],
            'preferred_program' => ['required', 'in:tahfidz,regular'],
            'guardian_name' => ['nullable', 'required_if:registrant_type,guardian', 'string', 'max:100'],
            'guardian_phone' => ['required', 'string', 'max:20', 'regex:/^(\+62|62|0)8[1-9][0-9]{7,10}$/'],
            'guardian_email' => ['nullable', 'email', 'max:255'],
            'info_source' => ['nullable', 'string', 'max:50'],
        ];
    }
}
