<?php

namespace App\Http\Requests\Admin;

use App\Models\Teacher;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTeacherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $teacher = $this->route('teacher');

        return [
            'school_id' => ['sometimes', 'uuid', 'exists:schools,id'],
            'code' => [
                'sometimes',
                'string',
                'max:10',
                'regex:/^[A-Z]{2,3}[0-9]?$/',
                Rule::unique('teachers')->where(function ($query) use ($teacher) {
                    return $query->where('school_id', $this->input('school_id', $teacher->school_id));
                })->ignore($teacher->id),
            ],
            'full_name' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(Teacher::STATUSES)],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
