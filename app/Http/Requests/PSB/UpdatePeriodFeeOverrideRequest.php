<?php

namespace App\Http\Requests\PSB;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePeriodFeeOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // fee_type_id intentionally absent — immutable post-create
        // (admin hapus + buat baru kalau salah pilih jenis biaya).
        //
        // `filled` on reason rejects empty strings when the field is present
        // (Laravel's `sometimes` alone would still accept `""`, blanking the
        // audit trail). The field is optional on PATCH overall, but if sent
        // it must carry a real value.
        return [
            'amount' => ['sometimes', 'integer', 'min:0', 'max:999999999999'],
            'reason' => ['sometimes', 'string', 'filled'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.integer' => 'Nominal override harus berupa angka bulat.',
            'amount.min' => 'Nominal override tidak boleh negatif.',
            'reason.filled' => 'Alasan keputusan komite tidak boleh dikosongkan.',
        ];
    }
}
