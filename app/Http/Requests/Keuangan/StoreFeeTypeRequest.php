<?php

namespace App\Http\Requests\Keuangan;

use App\Models\FeeType;
use App\Models\School;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFeeTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $school = School::activeOrFail();

        return [
            'code' => [
                'required',
                'string',
                'regex:/^[a-z0-9_]{1,50}$/',
                Rule::unique('fee_types', 'code')->where('school_id', $school->id),
            ],
            'label' => ['required', 'string', 'max:100'],
            'default_cadence' => ['required', 'string', Rule::in(FeeType::CADENCES)],
            'cash_book_category_id' => [
                'required',
                'uuid',
                Rule::exists('cash_book_categories', 'id')->where('school_id', $school->id),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'Kode jenis biaya wajib diisi.',
            'code.regex' => 'Kode hanya boleh huruf kecil, angka, dan underscore (maks 50 karakter).',
            'code.unique' => 'Kode sudah dipakai di pesantren ini.',
            'label.required' => 'Label jenis biaya wajib diisi.',
            'label.max' => 'Label maksimal 100 karakter.',
            'default_cadence.required' => 'Cadence default wajib dipilih.',
            'default_cadence.in' => 'Cadence tidak valid.',
            'cash_book_category_id.required' => 'Kategori Buku Kas wajib dipilih.',
            'cash_book_category_id.exists' => 'Kategori Buku Kas tidak ditemukan untuk pesantren ini.',
        ];
    }
}
