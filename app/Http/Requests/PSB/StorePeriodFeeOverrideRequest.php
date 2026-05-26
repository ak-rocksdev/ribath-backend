<?php

namespace App\Http\Requests\PSB;

use App\Models\School;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePeriodFeeOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $school = School::activeOrFail();
        $period = $this->route('registrationPeriod');

        return [
            'fee_type_id' => [
                'required',
                'uuid',
                Rule::exists('fee_types', 'id')->where('school_id', $school->id),
                // Composite uniqueness with the route's period — admin can't add
                // two overrides for the same fee_type within one periode.
                $period
                    ? Rule::unique('registration_period_fee_overrides', 'fee_type_id')
                        ->where(fn ($q) => $q->where('registration_period_id', $period->id))
                    : 'unique:registration_period_fee_overrides,fee_type_id',
            ],
            'amount' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'reason' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'fee_type_id.required' => 'Jenis biaya wajib dipilih.',
            'fee_type_id.exists' => 'Jenis biaya tidak ditemukan untuk pesantren ini.',
            'fee_type_id.unique' => 'Sudah ada override untuk jenis biaya ini di gelombang yang sama.',
            'amount.required' => 'Nominal override wajib diisi.',
            'amount.integer' => 'Nominal override harus berupa angka bulat.',
            'amount.min' => 'Nominal override tidak boleh negatif.',
            'reason.required' => 'Alasan keputusan komite wajib diisi.',
        ];
    }
}
