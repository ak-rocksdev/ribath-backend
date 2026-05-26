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
        return [
            'amount' => ['sometimes', 'integer', 'min:0', 'max:999999999999'],
            'reason' => ['sometimes', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.integer' => 'Nominal override harus berupa angka bulat.',
            'amount.min' => 'Nominal override tidak boleh negatif.',
        ];
    }
}
