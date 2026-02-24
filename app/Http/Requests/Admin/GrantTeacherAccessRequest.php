<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class GrantTeacherAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'unique:users,email,NULL,id,deleted_at,NULL'],
            'password' => ['required', 'string', 'min:8'],
        ];
    }
}
