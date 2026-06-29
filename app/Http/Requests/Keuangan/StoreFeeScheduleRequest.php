<?php

namespace App\Http\Requests\Keuangan;

use App\Models\FeeType;
use App\Models\School;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFeeScheduleRequest extends FormRequest
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
            'fee_type_id' => [
                'required',
                'uuid',
                Rule::exists('fee_types', 'id')->where('school_id', $school->id),
                // Composite uniqueness with academic_year_id — enforced both at DB
                // level (unique index) and here so the client sees a friendly 422
                // before hitting the DB constraint violation.
                Rule::unique('fee_schedules', 'fee_type_id')
                    ->where(fn ($q) => $q->where('academic_year_id', $this->input('academic_year_id'))),
            ],
            'amount' => ['required', 'integer', 'min:0', 'max:999999999999'],
        ];
    }

    public function messages(): array
    {
        return [
            'academic_year_id.required' => 'Tahun ajaran wajib dipilih.',
            'academic_year_id.exists' => 'Tahun ajaran tidak ditemukan untuk pesantren ini.',
            'fee_type_id.required' => 'Jenis biaya wajib dipilih.',
            'fee_type_id.exists' => 'Jenis biaya tidak ditemukan untuk pesantren ini.',
            'fee_type_id.unique' => 'Tarif untuk kombinasi ini sudah ada.',
            'amount.required' => 'Nominal tarif wajib diisi.',
            'amount.integer' => 'Nominal tarif harus berupa angka bulat.',
            'amount.min' => 'Nominal tarif tidak boleh negatif.',
        ];
    }
}
