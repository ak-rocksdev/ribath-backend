<?php

namespace App\Http\Requests\Keuangan;

use App\Models\CashBookEntry;
use App\Models\School;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCashBookEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $school = School::activeOrFail();

        return [
            // Compare against Asia/Jakarta date explicitly because config('app.timezone')
            // is UTC, which mis-rejects same-day WIB submissions during 00:00–07:00.
            'transaction_date' => ['sometimes', 'required', 'date', 'before_or_equal:'.now('Asia/Jakarta')->toDateString()],
            'type' => ['sometimes', 'required', 'string', Rule::in(CashBookEntry::TYPES)],
            'category_id' => [
                'sometimes',
                'required',
                'uuid',
                Rule::exists('cash_book_categories', 'id')
                    ->where('school_id', $school->id)
                    ->where('is_active', true),
            ],
            'description' => ['sometimes', 'required', 'string', 'max:5000'],
            'counterparty' => ['sometimes', 'nullable', 'string', 'max:255'],
            'amount' => ['sometimes', 'required', 'integer', 'min:1'],
            'proof' => ['sometimes', 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'remove_proof' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'transaction_date.before_or_equal' => 'Tanggal transaksi tidak boleh di masa depan.',
            'category_id.exists' => 'Kategori tidak valid atau sedang nonaktif.',
            'amount.min' => 'Jumlah harus lebih dari 0.',
            'proof.mimes' => 'Format file bukti harus JPG, PNG, WebP, atau PDF.',
            'proof.max' => 'Ukuran file maksimal 5MB.',
        ];
    }
}
