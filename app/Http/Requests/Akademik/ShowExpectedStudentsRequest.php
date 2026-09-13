<?php

namespace App\Http\Requests\Akademik;

use App\Traits\EnsuresActiveSchoolTenancy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /teaching-schedules/{teachingSchedule}/expected-students?session_date=
 * Tenancy is checked here, before the service reads the schedule's class.
 */
class ShowExpectedStudentsRequest extends FormRequest
{
    use EnsuresActiveSchoolTenancy;

    public function authorize(): bool
    {
        $teachingSchedule = $this->route('teachingSchedule');

        if ($teachingSchedule) {
            $this->ensureBelongsToActiveSchool($teachingSchedule);
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'session_date' => ClassSessionRules::sessionDateRules(),
        ];
    }

    public function messages(): array
    {
        return ClassSessionRules::messages();
    }
}
