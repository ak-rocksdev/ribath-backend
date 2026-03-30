<?php

namespace App\Http\Requests\Public;

use App\Models\StudentDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StudentDocumentUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document_type' => ['required', 'string', Rule::in(StudentDocument::ALLOWED_TYPES)],
            'file' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,pdf'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.max' => 'Ukuran file maksimal 5MB',
            'file.mimes' => 'Format file harus JPG, PNG, atau PDF',
            'document_type.in' => 'Tipe dokumen tidak valid',
        ];
    }
}
