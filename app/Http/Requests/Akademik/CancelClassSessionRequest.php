<?php

namespace App\Http\Requests\Akademik;

use App\Models\School;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /class-sessions/cancel — mark a schedule's date as a Pertemuan
 * Dibatalkan with a reason.
 */
class CancelClassSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'teaching_schedule_id' => ClassSessionRules::activeTeachingScheduleRules(School::activeOrFail()),
            'session_date' => ClassSessionRules::sessionDateRules(),
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return array_merge(ClassSessionRules::messages(), [
            'reason.required' => 'Alasan pembatalan wajib diisi.',
            'reason.string' => 'Alasan pembatalan tidak valid.',
            'reason.max' => 'Alasan pembatalan maksimal 255 karakter.',
        ]);
    }
}
