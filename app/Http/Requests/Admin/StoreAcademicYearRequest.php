<?php

namespace App\Http\Requests\Admin;

use App\Models\School;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAcademicYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:20',
                Rule::unique('academic_years')->where('school_id', School::activeOrFail()->id),
            ],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'active_semester' => ['sometimes', 'integer', 'in:1,2'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama tahun ajaran wajib diisi.',
            'name.max' => 'Nama tahun ajaran maksimal 20 karakter.',
            'name.unique' => 'Nama tahun ajaran sudah dipakai di pesantren ini.',
            'start_date.required' => 'Tanggal mulai wajib diisi.',
            'end_date.required' => 'Tanggal selesai wajib diisi.',
            'end_date.after' => 'Tanggal selesai harus setelah tanggal mulai.',
            'active_semester.in' => 'Semester aktif harus 1 atau 2.',
        ];
    }
}
