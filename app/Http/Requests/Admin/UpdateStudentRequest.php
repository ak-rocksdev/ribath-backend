<?php

namespace App\Http\Requests\Admin;

use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'full_name' => ['sometimes', 'string', 'max:100'],
            'birth_place' => ['nullable', 'string', 'max:100'],
            'birth_date' => ['sometimes', 'date', 'before:today'],
            'gender' => ['sometimes', Rule::in(['L', 'P'])],
            'program' => ['sometimes', Rule::in(['tahfidz', 'regular'])],
            'entry_date' => ['sometimes', 'date'],
            'class_level' => ['nullable', Rule::in(Student::CLASS_LEVELS)],
            'address' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'guardian_user_id' => ['nullable', 'exists:users,id'],
        ];
    }
}
