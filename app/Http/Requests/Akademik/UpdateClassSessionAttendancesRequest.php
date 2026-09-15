<?php

namespace App\Http\Requests\Akademik;

use App\Services\Akademik\ClassSessionService;
use App\Traits\EnsuresActiveSchoolTenancy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /class-sessions/{classSession}/attendances. Tenancy and the Cakupan
 * Mengajar are checked here, so a Pertemuan of another school or outside
 * the user's Cakupan Mengajar is not found (404) before the body is
 * validated and before the service reads its class and rows. The service
 * repeats the Cakupan Mengajar check.
 */
class UpdateClassSessionAttendancesRequest extends FormRequest
{
    use EnsuresActiveSchoolTenancy;

    public function authorize(ClassSessionService $classSessionService): bool
    {
        $classSession = $this->route('classSession');

        if ($classSession) {
            $this->ensureBelongsToActiveSchool($classSession);
            $classSessionService->ensureSessionWithinTeachingScope($classSession, 'manage-attendance');
        }

        return true;
    }

    public function rules(): array
    {
        return ClassSessionRules::attendanceRowsRules();
    }

    public function messages(): array
    {
        return ClassSessionRules::messages();
    }
}
