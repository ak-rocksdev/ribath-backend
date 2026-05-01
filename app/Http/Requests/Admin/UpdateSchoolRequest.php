<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSchoolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'    => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'phone'   => ['nullable', 'string', 'max:20'],
            'email'   => ['nullable', 'email', 'max:255'],
        ];
    }
}
