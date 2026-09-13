<?php

namespace App\Http\Requests\Akademik;

use App\Models\School;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /class-sessions — record a held Pertemuan with every expected
 * santri's attendance. Structural validation only; see ClassSessionRules.
 */
class StoreClassSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge([
            'teaching_schedule_id' => ClassSessionRules::activeTeachingScheduleRules(School::activeOrFail()),
            'session_date' => ClassSessionRules::sessionDateRules(),
        ], ClassSessionRules::attendanceRowsRules());
    }

    public function messages(): array
    {
        return ClassSessionRules::messages();
    }
}
