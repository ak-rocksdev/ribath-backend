<?php

namespace App\Http\Requests\Akademik;

use App\Services\Akademik\ClassTaskService;
use App\Traits\EnsuresActiveSchoolTenancy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /class-tasks/{classTask}. Only title, task_date and description can
 * be changed — the class × kitab × semester a task belongs to is fixed at
 * creation. authorize() hides a task of another school or outside the
 * user's Cakupan Mengajar behind a 404 before the body is validated, and
 * before ClassTaskService::update() can read the task's own
 * academic_semesters row for the date-range check. The service repeats the
 * Cakupan Mengajar check.
 */
class UpdateClassTaskRequest extends FormRequest
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
            'title' => ['sometimes', 'required', 'string', 'max:150'],
            'task_date' => ['sometimes', 'required', 'date'],
            'description' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Judul tugas wajib diisi.',
            'title.max' => 'Judul tugas maksimal 150 karakter.',
            'task_date.required' => 'Tanggal tugas wajib diisi.',
            'task_date.date' => 'Tanggal tugas tidak valid.',
            'description.string' => 'Deskripsi tidak valid.',
        ];
    }
}
