<?php

namespace App\Http\Requests\Keuangan;

use App\Models\StudentFeeException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFeeExceptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', Rule::in(StudentFeeException::KINDS)],
            'discount_amount' => [
                'nullable',
                'required_if:kind,'.StudentFeeException::KIND_PARTIAL_NOMINAL,
                'prohibited_if:kind,'.StudentFeeException::KIND_FULL_WAIVER,
                'integer', 'min:1',
            ],
            'reason' => ['required', 'string', 'max:500'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ];
    }

    public function messages(): array
    {
        return [
            'kind.required' => 'Jenis pengecualian wajib dipilih.',
            'kind.in' => 'Jenis pengecualian tidak valid.',
            'discount_amount.required_if' => 'Nominal potongan wajib diisi untuk potongan nominal.',
            'discount_amount.prohibited_if' => 'Beasiswa penuh tidak memakai nominal potongan.',
            'discount_amount.min' => 'Nominal potongan harus lebih dari 0.',
            'reason.required' => 'Alasan wajib diisi.',
            'reason.max' => 'Alasan maksimal 500 karakter.',
            'effective_from.required' => 'Tanggal mulai berlaku wajib diisi.',
            'effective_until.after_or_equal' => 'Tanggal selesai harus sama atau setelah tanggal mulai.',
        ];
    }
}
