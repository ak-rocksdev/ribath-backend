<?php

namespace App\Services\Akademik;

use App\Models\AcademicSemester;
use App\Models\GradingFactor;
use App\Models\GradingTemplate;
use App\Models\GradingTemplateFactor;
use App\Models\School;
use App\Models\SubjectBook;
use App\Models\SubjectCategory;
use Illuminate\Support\Facades\DB;

/**
 * Installs the fixed catalogue of grading templates/factors for a school
 * and keeps grading_template_factors (the per-semester weights) populated
 * as new academic semesters are created.
 */
class GradingDefaultsInstaller
{
    private const TEMPLATE_DEFINITIONS = [
        GradingTemplate::CODE_TEORI_KITAB => 'Teori/Kitab',
        GradingTemplate::CODE_TAHFIZH => 'Tahfizh',
    ];

    /** Default scale_levels for every level_1_4 factor (global-constraints.md). */
    private const DEFAULT_SCALE_LEVELS = [
        ['level' => 1, 'label' => 'Kurang', 'description' => 'Menunggu rumusan kurikulum', 'score' => 60],
        ['level' => 2, 'label' => 'Cukup', 'description' => 'Menunggu rumusan kurikulum', 'score' => 75],
        ['level' => 3, 'label' => 'Baik', 'description' => 'Menunggu rumusan kurikulum', 'score' => 85],
        ['level' => 4, 'label' => 'Sangat Baik', 'description' => 'Menunggu rumusan kurikulum', 'score' => 100],
    ];

    /**
     * Fixed catalogue of grading factors (global-constraints.md's fixed
     * values table), keyed by factor code. sort_order matches the listed
     * order (1..10) and is used both at install time and for display.
     */
    private const FACTOR_DEFINITIONS = [
        'uts' => [
            'name' => 'UTS', 'input_type' => GradingFactor::INPUT_TYPE_MANUAL_ONCE, 'score_scale' => GradingFactor::SCORE_SCALE_PERCENT,
            'is_midterm_exam' => true, 'sort_order' => 1, 'template_code' => GradingTemplate::CODE_TEORI_KITAB, 'default_weight' => 20,
        ],
        'uas' => [
            'name' => 'UAS', 'input_type' => GradingFactor::INPUT_TYPE_MANUAL_ONCE, 'score_scale' => GradingFactor::SCORE_SCALE_PERCENT,
            'is_midterm_exam' => false, 'sort_order' => 2, 'template_code' => GradingTemplate::CODE_TEORI_KITAB, 'default_weight' => 30,
        ],
        'tugas' => [
            'name' => 'Tugas', 'input_type' => GradingFactor::INPUT_TYPE_MANUAL_PERIODIC, 'score_scale' => GradingFactor::SCORE_SCALE_PERCENT,
            'is_midterm_exam' => false, 'sort_order' => 3, 'template_code' => GradingTemplate::CODE_TEORI_KITAB, 'default_weight' => 20,
        ],
        'keaktifan' => [
            'name' => 'Keaktifan', 'input_type' => GradingFactor::INPUT_TYPE_END_OF_SEMESTER_BULK, 'score_scale' => GradingFactor::SCORE_SCALE_LEVEL_1_4,
            'is_midterm_exam' => false, 'sort_order' => 4, 'template_code' => GradingTemplate::CODE_TEORI_KITAB, 'default_weight' => 10,
        ],
        'adab' => [
            'name' => 'Adab', 'input_type' => GradingFactor::INPUT_TYPE_END_OF_SEMESTER_BULK, 'score_scale' => GradingFactor::SCORE_SCALE_LEVEL_1_4,
            'is_midterm_exam' => false, 'sort_order' => 5, 'template_code' => GradingTemplate::CODE_TEORI_KITAB, 'default_weight' => 10,
        ],
        'absensi' => [
            'name' => 'Absensi', 'input_type' => GradingFactor::INPUT_TYPE_AUTO_FROM_ATTENDANCE, 'score_scale' => GradingFactor::SCORE_SCALE_PERCENT,
            'is_midterm_exam' => false, 'sort_order' => 6, 'template_code' => GradingTemplate::CODE_TEORI_KITAB, 'default_weight' => 10,
        ],
        'target_hafalan' => [
            'name' => 'Pencapaian Target', 'input_type' => GradingFactor::INPUT_TYPE_AUTO_FROM_LOG, 'score_scale' => GradingFactor::SCORE_SCALE_PERCENT,
            'is_midterm_exam' => false, 'sort_order' => 7, 'template_code' => GradingTemplate::CODE_TAHFIZH, 'default_weight' => 20,
        ],
        'kualitas_setoran' => [
            'name' => 'Kualitas Setoran', 'input_type' => GradingFactor::INPUT_TYPE_AUTO_FROM_LOG, 'score_scale' => GradingFactor::SCORE_SCALE_PERCENT,
            'is_midterm_exam' => false, 'sort_order' => 8, 'template_code' => GradingTemplate::CODE_TAHFIZH, 'default_weight' => 20,
        ],
        'murajaah' => [
            'name' => 'Murajaah', 'input_type' => GradingFactor::INPUT_TYPE_AUTO_FROM_LOG, 'score_scale' => GradingFactor::SCORE_SCALE_PERCENT,
            'is_midterm_exam' => false, 'sort_order' => 9, 'template_code' => GradingTemplate::CODE_TAHFIZH, 'default_weight' => 20,
        ],
        'uas_tahfizh' => [
            'name' => 'UAS Tahfizh', 'input_type' => GradingFactor::INPUT_TYPE_MANUAL_ONCE, 'score_scale' => GradingFactor::SCORE_SCALE_PERCENT,
            'is_midterm_exam' => false, 'sort_order' => 10, 'template_code' => GradingTemplate::CODE_TAHFIZH, 'default_weight' => 40,
        ],
    ];

    /**
     * Idempotently creates the 2 grading_templates and 10 grading_factors
     * for a school. Safe to call more than once (matched by code).
     */
    public function installForSchool(School $school): void
    {
        DB::transaction(function () use ($school) {
            foreach (self::TEMPLATE_DEFINITIONS as $code => $name) {
                GradingTemplate::firstOrCreate(
                    ['school_id' => $school->id, 'code' => $code],
                    ['name' => $name, 'is_active' => true]
                );
            }

            foreach (self::FACTOR_DEFINITIONS as $code => $definition) {
                GradingFactor::firstOrCreate(
                    ['school_id' => $school->id, 'code' => $code],
                    [
                        'name' => $definition['name'],
                        'input_type' => $definition['input_type'],
                        'score_scale' => $definition['score_scale'],
                        'scale_levels' => $definition['score_scale'] === GradingFactor::SCORE_SCALE_LEVEL_1_4
                            ? self::DEFAULT_SCALE_LEVELS
                            : null,
                        'is_midterm_exam' => $definition['is_midterm_exam'],
                        'sort_order' => $definition['sort_order'],
                    ]
                );
            }
        });
    }

    /**
     * Creates grading_template_factors rows for every template x factor of
     * the semester's school, skipping ones that already exist. New rows
     * copy weight/is_active from the most recent earlier semester of the
     * same school that has rows (ordered by academic_years.start_date then
     * semester); if none exists yet, DEFAULT_WEIGHTS (FACTOR_DEFINITIONS'
     * default_weight, all active) is used instead.
     *
     * Does nothing if the school has no grading_templates installed yet —
     * callers (AcademicSemesterService, this class's own
     * ensureWeightsForAllSemesters) are expected to have called
     * installForSchool() first when grading should apply.
     */
    public function ensureWeightsForSemester(AcademicSemester $academicSemester): void
    {
        $templatesByCode = GradingTemplate::where('school_id', $academicSemester->school_id)->get()->keyBy('code');

        if ($templatesByCode->isEmpty()) {
            return;
        }

        $factorsByCode = GradingFactor::where('school_id', $academicSemester->school_id)->get()->keyBy('code');

        $sourceSemester = $this->findMostRecentEarlierSemesterWithWeights($academicSemester);

        $sourceRowsByTemplateAndFactor = collect();
        if ($sourceSemester !== null) {
            $sourceRowsByTemplateAndFactor = GradingTemplateFactor::where('school_id', $academicSemester->school_id)
                ->where('academic_year_id', $sourceSemester['academic_year_id'])
                ->where('semester', $sourceSemester['semester'])
                ->get()
                ->keyBy(fn (GradingTemplateFactor $row) => $row->grading_template_id.'|'.$row->grading_factor_id);
        }

        DB::transaction(function () use ($academicSemester, $templatesByCode, $factorsByCode, $sourceRowsByTemplateAndFactor) {
            foreach (self::FACTOR_DEFINITIONS as $factorCode => $definition) {
                $template = $templatesByCode->get($definition['template_code']);
                $factor = $factorsByCode->get($factorCode);

                if (! $template || ! $factor) {
                    continue;
                }

                $alreadyExists = GradingTemplateFactor::where([
                    'grading_template_id' => $template->id,
                    'grading_factor_id' => $factor->id,
                    'academic_year_id' => $academicSemester->academic_year_id,
                    'semester' => $academicSemester->semester,
                ])->exists();

                if ($alreadyExists) {
                    continue;
                }

                $sourceRow = $sourceRowsByTemplateAndFactor->get($template->id.'|'.$factor->id);

                GradingTemplateFactor::create([
                    'school_id' => $academicSemester->school_id,
                    'grading_template_id' => $template->id,
                    'grading_factor_id' => $factor->id,
                    'academic_year_id' => $academicSemester->academic_year_id,
                    'semester' => $academicSemester->semester,
                    'weight' => $sourceRow?->weight ?? $definition['default_weight'],
                    'is_active' => $sourceRow?->is_active ?? true,
                ]);
            }
        });
    }

    /**
     * Loops every academic_semester of the school (chronologically, so each
     * semester can copy forward from the one right before it) and ensures
     * its weights exist.
     */
    public function ensureWeightsForAllSemesters(School $school): void
    {
        $semesters = AcademicSemester::query()
            ->join('academic_years', 'academic_years.id', '=', 'academic_semesters.academic_year_id')
            ->where('academic_semesters.school_id', $school->id)
            ->orderBy('academic_years.start_date')
            ->orderBy('academic_semesters.semester')
            ->select('academic_semesters.*')
            ->get();

        foreach ($semesters as $semester) {
            $this->ensureWeightsForSemester($semester);
        }
    }

    /**
     * Backfills subject_books.grading_template_id for the school: every
     * book still missing one gets "teori_kitab", except books under the
     * tahfizh fann (subject_categories.slug = 'tahfizh') or literally
     * titled "Tahfizh Al-Qur'an", which get "tahfizh". Idempotent — never
     * overwrites a book that already has a template. No-op if the school
     * has no grading_templates installed yet.
     */
    public function assignDefaultTemplateToSubjectBooks(School $school): void
    {
        $templatesByCode = GradingTemplate::where('school_id', $school->id)->get()->keyBy('code');

        if ($templatesByCode->isEmpty()) {
            return;
        }

        $tahfizhTemplate = $templatesByCode->get(GradingTemplate::CODE_TAHFIZH);
        $teoriKitabTemplate = $templatesByCode->get(GradingTemplate::CODE_TEORI_KITAB);

        if ($tahfizhTemplate) {
            $tahfizhCategoryIds = SubjectCategory::where('school_id', $school->id)
                ->where('slug', 'tahfizh')
                ->pluck('id');

            SubjectBook::where('school_id', $school->id)
                ->whereNull('grading_template_id')
                ->where(function ($query) use ($tahfizhCategoryIds) {
                    $query->where('title', "Tahfizh Al-Qur'an");
                    if ($tahfizhCategoryIds->isNotEmpty()) {
                        $query->orWhereIn('subject_category_id', $tahfizhCategoryIds);
                    }
                })
                ->update(['grading_template_id' => $tahfizhTemplate->id]);
        }

        if ($teoriKitabTemplate) {
            SubjectBook::where('school_id', $school->id)
                ->whereNull('grading_template_id')
                ->update(['grading_template_id' => $teoriKitabTemplate->id]);
        }
    }

    /**
     * Finds the (academic_year_id, semester) pair — strictly before the
     * given semester in (academic_years.start_date, semester) order, within
     * the same school — that is the most recent one with at least one
     * grading_template_factors row. Returns null if none exists (the given
     * semester is the earliest with grading data).
     *
     * @return array{academic_year_id: string, semester: int}|null
     */
    private function findMostRecentEarlierSemesterWithWeights(AcademicSemester $academicSemester): ?array
    {
        $targetStartDate = $academicSemester->academicYear?->start_date?->toDateString();

        if ($targetStartDate === null) {
            return null;
        }

        $row = GradingTemplateFactor::query()
            ->join('academic_years', 'academic_years.id', '=', 'grading_template_factors.academic_year_id')
            ->where('grading_template_factors.school_id', $academicSemester->school_id)
            ->where(function ($query) use ($targetStartDate, $academicSemester) {
                $query->where('academic_years.start_date', '<', $targetStartDate)
                    ->orWhere(function ($query) use ($targetStartDate, $academicSemester) {
                        $query->where('academic_years.start_date', '=', $targetStartDate)
                            ->where('grading_template_factors.semester', '<', $academicSemester->semester);
                    });
            })
            ->orderByDesc('academic_years.start_date')
            ->orderByDesc('grading_template_factors.semester')
            ->select('grading_template_factors.academic_year_id', 'grading_template_factors.semester')
            ->first();

        return $row ? ['academic_year_id' => $row->academic_year_id, 'semester' => (int) $row->semester] : null;
    }
}
