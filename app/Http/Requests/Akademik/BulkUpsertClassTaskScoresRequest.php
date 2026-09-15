<?php

namespace App\Http\Requests\Akademik;

use App\Services\Akademik\ClassTaskService;
use App\Traits\EnsuresActiveSchoolTenancy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Structural validation of PUT /class-tasks/{classTask}/scores/bulk.
 * Per-student checks (in the class, not duplicated) and the score's
 * type/range live in ClassTaskService::upsertScores() so errors are keyed
 * "<student_id>" instead of a row index. authorize() hides a task of
 * another school or outside the user's Cakupan Mengajar behind a 404
 * before the body is validated; the service repeats the Cakupan Mengajar
 * check.
 */
class BulkUpsertClassTaskScoresRequest extends FormRequest
{
    use EnsuresActiveSchoolTenancy;

    public function authorize(ClassTaskService $classTaskService): bool
    {
        $classTask = $this->route('classTask');

        if ($classTask) {
            $this->ensureBelongsToActiveSchool($classTask);
            $classTaskService->ensureWithinTeachingScope($classTask, 'manage-grades');
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'rows' => ['required', 'array', 'min:1'],
            'rows.*' => ['array'],
            'rows.*.student_id' => ['required', 'uuid'],
            'rows.*.score' => ['present'],
        ];
    }

    public function messages(): array
    {
        return [
            'rows.required' => 'Daftar nilai santri wajib diisi.',
            'rows.array' => 'Daftar nilai santri tidak valid.',
            'rows.min' => 'Daftar nilai santri tidak boleh kosong.',
            'rows.*.array' => 'Baris nilai santri tidak valid.',
            'rows.*.student_id.required' => 'Santri wajib diisi.',
            'rows.*.student_id.uuid' => 'Santri tidak valid.',
            'rows.*.score.present' => 'Nilai santri wajib dikirim.',
        ];
    }
}
