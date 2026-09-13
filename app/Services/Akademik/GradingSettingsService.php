<?php

namespace App\Services\Akademik;

use App\Models\GradingFactor;
use App\Models\GradingTemplate;
use App\Models\GradingTemplateFactor;
use App\Models\School;
use App\Models\StudentGrade;
use App\Models\SubjectBook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class GradingSettingsService
{
    public function listTemplates(): Collection
    {
        $school = School::activeOrFail();

        return GradingTemplate::where('school_id', $school->id)->orderBy('code')->get();
    }

    public function listFactors(): Collection
    {
        $school = School::activeOrFail();

        return GradingFactor::where('school_id', $school->id)->orderBy('sort_order')->get();
    }

    /**
     * Edits a factor's name and, for level_1_4 factors, its scale_levels.
     * Rejection of scale_levels for a percent-scale factor and the
     * exactly-levels-1-to-4 / score-range checks happen in
     * UpdateGradingFactorRequest before this is called.
     */
    public function updateFactor(GradingFactor $factor, array $data): GradingFactor
    {
        $attributes = [];

        if (array_key_exists('name', $data)) {
            $attributes['name'] = $data['name'];
        }

        if (array_key_exists('scale_levels', $data)) {
            $attributes['scale_levels'] = $data['scale_levels'];
        }

        if (! empty($attributes)) {
            $factor->fill($attributes);
            if ($factor->isDirty()) {
                $factor->save();
            }
        }

        return $factor->fresh();
    }

    /**
     * Returns every grading_template of the active school for the given
     * (academic_year_id, semester), each with its assigned factors' current
     * weight/is_active and a has_grades flag.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSemesterWeights(string $academicYearId, int $semester): array
    {
        $school = School::activeOrFail();

        $templates = GradingTemplate::where('school_id', $school->id)->orderBy('code')->get();

        return $templates
            ->map(fn (GradingTemplate $template) => $this->buildTemplateWeightsPayload($template, $school->id, $academicYearId, $semester))
            ->values()
            ->all();
    }

    /**
     * Replaces the weight/is_active of the given template's
     * grading_template_factors rows for (academic_year_id, semester). The
     * submitted factor list must be exactly the template's currently
     * assigned factors (422 otherwise), and the sum of weights among rows
     * left active must equal 100.00 (tolerance 0.001).
     *
     * @param  array<int, array{grading_factor_id: string, weight: float|int|string, is_active?: bool}>  $rows
     * @return array<string, mixed>
     */
    public function replaceSemesterWeights(string $academicYearId, int $semester, string $gradingTemplateId, array $rows): array
    {
        $school = School::activeOrFail();

        $template = GradingTemplate::where('school_id', $school->id)->findOrFail($gradingTemplateId);

        $existingRowsByFactorId = GradingTemplateFactor::where('school_id', $school->id)
            ->where('grading_template_id', $template->id)
            ->where('academic_year_id', $academicYearId)
            ->where('semester', $semester)
            ->get()
            ->keyBy('grading_factor_id');

        $expectedFactorIds = $existingRowsByFactorId->keys()->sort()->values()->all();
        $submittedFactorIds = collect($rows)->pluck('grading_factor_id')->sort()->values()->all();

        if ($expectedFactorIds !== $submittedFactorIds) {
            abort(422, 'Daftar faktor tidak sesuai dengan template ini.');
        }

        $activeWeightSum = collect($rows)
            ->filter(fn (array $row) => $row['is_active'] ?? true)
            ->sum(fn (array $row) => (float) $row['weight']);

        if (abs($activeWeightSum - 100.0) > 0.001) {
            abort(422, 'Jumlah bobot faktor aktif harus 100%.');
        }

        DB::transaction(function () use ($rows, $existingRowsByFactorId) {
            foreach ($rows as $row) {
                $existingRowsByFactorId->get($row['grading_factor_id'])->update([
                    'weight' => $row['weight'],
                    'is_active' => $row['is_active'] ?? true,
                ]);
            }
        });

        $payload = $this->buildTemplateWeightsPayload($template, $school->id, $academicYearId, $semester);
        $payload['recalculated_grades_count'] = $this->recordedGradesQuery($school->id, $template->id, $academicYearId, $semester)->count();

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTemplateWeightsPayload(GradingTemplate $template, string $schoolId, string $academicYearId, int $semester): array
    {
        $rows = GradingTemplateFactor::where('school_id', $schoolId)
            ->where('grading_template_id', $template->id)
            ->where('academic_year_id', $academicYearId)
            ->where('semester', $semester)
            ->with('gradingFactor')
            ->get()
            ->sortBy(fn (GradingTemplateFactor $row) => $row->gradingFactor?->sort_order ?? 0)
            ->values();

        return [
            'id' => $template->id,
            'code' => $template->code,
            'name' => $template->name,
            'has_grades' => $this->semesterHasRecordedGrades($schoolId, $template->id, $academicYearId, $semester),
            'factors' => $rows->map(function (GradingTemplateFactor $row) {
                $factor = $row->gradingFactor;

                return [
                    'grading_template_factor_id' => $row->id,
                    'grading_factor_id' => $row->grading_factor_id,
                    'code' => $factor?->code,
                    'name' => $factor?->name,
                    'input_type' => $factor?->input_type,
                    'score_scale' => $factor?->score_scale,
                    'is_midterm_exam' => $factor?->is_midterm_exam,
                    'weight' => (float) $row->weight,
                    'is_active' => $row->is_active,
                    'updated_at' => $row->updated_at,
                ];
            })->values(),
        ];
    }

    /**
     * Whether this semester already has recorded student grades (a
     * student_grades row with a non-NULL score) for a kitab using this
     * template — used to warn the user that changing weights recalculates
     * existing recaps (R1).
     */
    private function semesterHasRecordedGrades(string $schoolId, string $gradingTemplateId, string $academicYearId, int $semester): bool
    {
        return $this->recordedGradesQuery($schoolId, $gradingTemplateId, $academicYearId, $semester)->exists();
    }

    /**
     * student_grades rows with a non-NULL score, in this semester, for a
     * kitab using this template — shared by the has_grades check and the
     * weight-replacement response's recalculated_grades_count.
     */
    private function recordedGradesQuery(string $schoolId, string $gradingTemplateId, string $academicYearId, int $semester): Builder
    {
        return StudentGrade::query()
            ->where('school_id', $schoolId)
            ->where('academic_year_id', $academicYearId)
            ->where('semester', $semester)
            ->whereNotNull('score')
            ->whereIn('subject_book_id', SubjectBook::query()
                ->select('id')
                ->where('school_id', $schoolId)
                ->where('grading_template_id', $gradingTemplateId));
    }
}
