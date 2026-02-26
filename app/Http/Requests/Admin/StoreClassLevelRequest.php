<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreClassLevelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'max:30', 'regex:/^[a-z0-9_]+$/', 'unique:class_levels,slug'],
            'label' => ['required', 'string', 'max:50'],
            'category' => ['required', 'string', 'in:akademik,tahfidz,takhassus'],
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
