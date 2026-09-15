<?php

namespace App\Http\Requests\Akademik;

use App\Services\Akademik\ClassSessionService;
use App\Traits\EnsuresActiveSchoolTenancy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /teaching-schedules/{teachingSchedule}/expected-students?session_date=
 * Tenancy and the Cakupan Mengajar are checked here, so a schedule of
 * another school or outside the user's Cakupan Mengajar is not found (404)
 * before the query is validated and before the service reads its class.
 * The service repeats the Cakupan Mengajar check.
 */
class ShowExpectedStudentsRequest extends FormRequest
{
    use EnsuresActiveSchoolTenancy;

    public function authorize(ClassSessionService $classSessionService): bool
    {
        $teachingSchedule = $this->route('teachingSchedule');

        if ($teachingSchedule) {
            $this->ensureBelongsToActiveSchool($teachingSchedule);
            $classSessionService->ensureScheduleWithinTeachingScope($teachingSchedule, 'view-attendance');
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
