<?php

namespace App\Http\Requests\Akademik;

use App\Traits\EnsuresActiveSchoolTenancy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /class-sessions/{classSession}/attendances. Tenancy is checked here,
 * before the service reads the route-bound session's class and rows.
 */
class UpdateClassSessionAttendancesRequest extends FormRequest
{
    use EnsuresActiveSchoolTenancy;

    public function authorize(): bool
    {
        $classSession = $this->route('classSession');

        if ($classSession) {
            $this->ensureBelongsToActiveSchool($classSession);
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
