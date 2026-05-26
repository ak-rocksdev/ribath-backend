<?php

namespace App\Http\Requests\Admin;

use App\Models\School;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAcademicYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'sometimes', 'string', 'max:20',
                Rule::unique('academic_years')
                    ->where('school_id', School::activeOrFail()->id)
                    ->ignore($this->route('academicYear')?->id),
            ],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'date', function ($attribute, $value, $fail) {
                $startDate = $this->input('start_date', $this->route('academicYear')?->start_date?->toDateString());
                if ($startDate && $value <= $startDate) {
                    $fail('Tanggal selesai harus setelah tanggal mulai.');
                }
            }],
            'active_semester' => ['sometimes', 'integer', 'in:1,2'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.max' => 'Nama tahun ajaran maksimal 20 karakter.',
            'name.unique' => 'Nama tahun ajaran sudah dipakai di pesantren ini.',
            'active_semester.in' => 'Semester aktif harus 1 atau 2.',
        ];
    }
}
