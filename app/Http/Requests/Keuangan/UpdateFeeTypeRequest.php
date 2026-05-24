<?php

namespace App\Http\Requests\Keuangan;

use App\Models\FeeType;
use App\Models\School;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFeeTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $school = School::activeOrFail();

        // `code` intentionally absent — immutable post-create. The service
        // silently drops `code` from the payload if a client still sends it.
        return [
            'label' => ['sometimes', 'string', 'max:100'],
            'default_cadence' => ['sometimes', 'string', Rule::in(FeeType::CADENCES)],
            'cash_book_category_id' => [
                'sometimes',
                'uuid',
                Rule::exists('cash_book_categories', 'id')->where('school_id', $school->id),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'label.max' => 'Label maksimal 100 karakter.',
            'default_cadence.in' => 'Cadence tidak valid.',
            'cash_book_category_id.exists' => 'Kategori Buku Kas tidak ditemukan untuk pesantren ini.',
        ];
    }
}
