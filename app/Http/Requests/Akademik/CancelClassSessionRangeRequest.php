<?php

namespace App\Http\Requests\Akademik;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * POST /class-sessions/cancel-range — libur massal: create a Pertemuan
 * Dibatalkan for every matching date of every active schedule of the
 * active academic year/semester in [start_date, end_date]. Structural
 * validation only; the semester-configured check, the clamp to the
 * semester range and the actor date rules live in
 * ClassSessionService::cancelDateRange.
 */
class CancelClassSessionRangeRequest extends FormRequest
{
    public const MAX_RANGE_DAYS = 62;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('start_date') || $validator->errors()->has('end_date')) {
                return;
            }

            $days = Carbon::parse($this->input('start_date'))->diffInDays(Carbon::parse($this->input('end_date'))) + 1;

            if ($days > self::MAX_RANGE_DAYS) {
                $validator->errors()->add('end_date', 'Rentang tanggal maksimal '.self::MAX_RANGE_DAYS.' hari.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'start_date.required' => 'Tanggal mulai wajib diisi.',
            'start_date.date_format' => 'Format tanggal mulai harus YYYY-MM-DD.',
            'end_date.required' => 'Tanggal akhir wajib diisi.',
            'end_date.date_format' => 'Format tanggal akhir harus YYYY-MM-DD.',
            'end_date.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal mulai.',
            'reason.required' => 'Alasan libur wajib diisi.',
            'reason.string' => 'Alasan libur tidak valid.',
            'reason.max' => 'Alasan libur maksimal 255 karakter.',
        ];
    }
}
