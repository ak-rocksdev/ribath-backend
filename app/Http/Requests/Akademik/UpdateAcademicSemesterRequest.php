<?php

namespace App\Http\Requests\Akademik;

use App\Models\AcademicSemester;
use App\Traits\EnsuresActiveSchoolTenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateAcademicSemesterRequest extends FormRequest
{
    use EnsuresActiveSchoolTenancy;

    /**
     * Cross-tenant records must be hidden behind a 404 before any of their
     * data is read (global constraint 1). This has to happen here — in
     * authorize(), which runs before rules()/withValidator() — rather than
     * only in the controller, because withValidator()'s cross-field checks
     * already read the OTHER school's stored semester (via
     * mergedWithExisting()) to validate the submitted body. If tenancy were
     * only checked in the controller (which runs after validation), a
     * request against a foreign academic year would surface as 422 or 200
     * depending on that other school's stored dates — an oracle that leaks
     * their data. Checking here makes every foreign academic year 404,
     * uniformly, before any of its semester data is touched.
     */
    public function authorize(): bool
    {
        $academicYear = $this->route('academicYear');

        if ($academicYear) {
            $this->ensureBelongsToActiveSchool($academicYear);
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date'],
            'midterm_exam_date' => ['sometimes', 'nullable', 'date'],
            'uts_enabled' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'start_date.date' => 'Tanggal mulai tidak valid.',
            'end_date.date' => 'Tanggal selesai tidak valid.',
            'midterm_exam_date.date' => 'Tanggal UTS tidak valid.',
            'uts_enabled.boolean' => 'UTS diadakan harus berupa nilai benar atau salah.',
        ];
    }

    /**
     * The PUT body may be partial. To validate cross-field date rules
     * correctly we merge each submitted date with the value already
     * stored for this semester (a field the client didn't send keeps its
     * stored value for the purpose of these checks).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            ['start_date' => $startDate, 'end_date' => $endDate, 'midterm_exam_date' => $midtermDate] = $this->mergedWithExisting();

            if ($startDate && $endDate && strtotime($endDate) < strtotime($startDate)) {
                $validator->errors()->add('end_date', 'Tanggal selesai harus sama dengan atau setelah tanggal mulai.');
            }

            if ($midtermDate) {
                if ($startDate && strtotime($midtermDate) < strtotime($startDate)) {
                    $validator->errors()->add('midterm_exam_date', 'Tanggal UTS harus di antara tanggal mulai dan tanggal selesai.');
                }

                if ($endDate && strtotime($midtermDate) > strtotime($endDate)) {
                    $validator->errors()->add('midterm_exam_date', 'Tanggal UTS harus di antara tanggal mulai dan tanggal selesai.');
                }
            }
        });
    }

    /**
     * @return array{start_date: ?string, end_date: ?string, midterm_exam_date: ?string}
     */
    private function mergedWithExisting(): array
    {
        $academicYear = $this->route('academicYear');
        $semester = (int) $this->route('semester');

        $existing = $academicYear
            ? AcademicSemester::findByPair($academicYear->id, $semester)
            : null;

        return [
            'start_date' => $this->resolveDate('start_date', $existing?->start_date?->toDateString()),
            'end_date' => $this->resolveDate('end_date', $existing?->end_date?->toDateString()),
            'midterm_exam_date' => $this->resolveDate('midterm_exam_date', $existing?->midterm_exam_date?->toDateString()),
        ];
    }

    private function resolveDate(string $field, ?string $fallback): ?string
    {
        return $this->has($field) ? $this->input($field) : $fallback;
    }
}
