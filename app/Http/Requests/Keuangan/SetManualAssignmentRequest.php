<?php

namespace App\Http\Requests\Keuangan;

use App\Models\School;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetManualAssignmentRequest extends FormRequest
{
    private const CADENCES = ['monthly', 'quarterly', 'semesterly', 'yearly', 'once_at_enrollment'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $school = School::activeOrFail();

        return [
            'source_academic_year_id' => [
                'required', 'uuid',
                Rule::exists('academic_years', 'id')->where('school_id', $school->id),
            ],
            'items' => ['required', 'array', 'min:1'],
            'items.*.fee_type_id' => [
                'required', 'uuid',
                Rule::exists('fee_types', 'id')->where('school_id', $school->id),
            ],
            'items.*.locked_amount' => ['required', 'integer', 'min:0'],
            'items.*.cadence' => ['required', 'string', Rule::in(self::CADENCES)],
        ];
    }

    public function messages(): array
    {
        return [
            'source_academic_year_id.required' => 'Tahun ajaran referensi wajib dipilih.',
            'source_academic_year_id.exists' => 'Tahun ajaran tidak ditemukan untuk pesantren ini.',
            'items.required' => 'Minimal 1 jenis biaya harus diisi.',
            'items.min' => 'Minimal 1 jenis biaya harus diisi.',
            'items.*.fee_type_id.required' => 'Jenis biaya wajib dipilih.',
            'items.*.fee_type_id.exists' => 'Jenis biaya tidak ditemukan untuk pesantren ini.',
            'items.*.locked_amount.min' => 'Nominal tidak boleh negatif.',
            'items.*.cadence.in' => 'Frekuensi tagihan tidak valid.',
        ];
    }
}
