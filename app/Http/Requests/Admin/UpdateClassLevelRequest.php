<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClassLevelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $classLevelId = $this->route('classLevel')->id ?? $this->route('classLevel');

        return [
            'slug' => [
                'sometimes',
                'string',
                'max:30',
                'regex:/^[a-z0-9_]+$/',
                Rule::unique('class_levels', 'slug')->ignore($classLevelId),
            ],
            'label' => ['sometimes', 'string', 'max:50'],
            'category' => ['sometimes', 'string', 'in:akademik,tahfidz,takhassus'],
            'sort_order' => ['sometimes', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'Slug may only contain lowercase letters, numbers, and underscores.',
            'slug.unique' => 'This slug is already taken.',
        ];
    }
}
