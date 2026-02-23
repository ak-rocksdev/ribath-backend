<?php

namespace App\Http\Requests\PSB;

use Illuminate\Foundation\Http\FormRequest;

class StorePsbPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'year' => ['required', 'string', 'max:20'],
            'gelombang' => ['required', 'integer', 'min:1'],
            'pendaftaran_buka' => ['required', 'date'],
            'pendaftaran_tutup' => ['required', 'date', 'after:pendaftaran_buka'],
            'tanggal_masuk' => ['required', 'date', 'after:pendaftaran_tutup'],
            'biaya_pendaftaran' => ['required', 'numeric', 'min:0'],
            'biaya_spp_bulanan' => ['required', 'numeric', 'min:0'],
            'kuota_santri' => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
