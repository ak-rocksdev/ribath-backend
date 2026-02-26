<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:100'],
            'birth_place' => ['nullable', 'string', 'max:100'],
            'birth_date' => ['required', 'date', 'before:today'],
            'gender' => ['required', Rule::in(['L', 'P'])],
            'program' => ['required', Rule::in(['tahfidz', 'regular'])],
            'entry_date' => ['required', 'date'],
            'class_level' => ['nullable', 'string', 'exists:class_levels,slug'],
            'address' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'guardian_user_id' => ['nullable', 'exists:users,id'],
        ];
    }
}
