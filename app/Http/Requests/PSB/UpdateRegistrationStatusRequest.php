<?php

namespace App\Http\Requests\PSB;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRegistrationStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:dihubungi,interview,waitlist,batal'],
            'admin_notes' => ['nullable', 'string'],
            'interviewed_at' => ['nullable', 'date'],
        ];
    }
}
