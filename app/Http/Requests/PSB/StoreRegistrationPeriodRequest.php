<?php

namespace App\Http\Requests\PSB;

use App\Models\School;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRegistrationPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $school = School::activeOrFail();

        return [
            'academic_year_id' => [
                'required',
                'uuid',
                Rule::exists('academic_years', 'id')->where('school_id', $school->id),
            ],
            'name' => ['required', 'string', 'max:100'],
            'wave' => ['required', 'integer', 'min:1'],
            'registration_open' => ['required', 'date'],
            'registration_close' => ['required', 'date', 'after:registration_open'],
            'entry_date' => ['required', 'date', 'after:registration_close'],
            'student_quota' => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'academic_year_id.required' => 'Tahun ajaran wajib dipilih.',
            'academic_year_id.exists' => 'Tahun ajaran tidak ditemukan untuk pesantren ini.',
            'name.required' => 'Nama periode wajib diisi.',
            'wave.required' => 'Gelombang wajib diisi.',
            'registration_open.required' => 'Tanggal buka pendaftaran wajib diisi.',
            'registration_close.required' => 'Tanggal tutup pendaftaran wajib diisi.',
            'registration_close.after' => 'Tanggal tutup harus setelah tanggal buka.',
            'entry_date.required' => 'Tanggal masuk wajib diisi.',
            'entry_date.after' => 'Tanggal masuk harus setelah tanggal tutup pendaftaran.',
        ];
    }
}
