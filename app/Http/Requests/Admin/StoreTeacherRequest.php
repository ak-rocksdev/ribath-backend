<?php

namespace App\Http\Requests\Admin;

use App\Models\Teacher;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTeacherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'school_id' => ['required', 'uuid', 'exists:schools,id'],
            'code' => [
                'required',
                'string',
                'max:10',
                'regex:/^[A-Z]{2,3}[0-9]?$/',
                Rule::unique('teachers')->where(function ($query) {
                    return $query->where('school_id', $this->input('school_id'));
                }),
            ],
            'full_name' => ['required', 'string', 'max:100'],
            'status' => ['required', Rule::in(Teacher::STATUSES)],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
