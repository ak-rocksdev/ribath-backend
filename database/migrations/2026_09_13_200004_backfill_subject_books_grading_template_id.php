<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfills subject_books.grading_template_id for every school whose
     * grading_templates were already installed (Task 3's
     * GradingDefaultsSeeder ran) before this column existed.
     *
     * Every book without a template gets "teori_kitab", except books under
     * the tahfizh fann (subject_categories.slug = 'tahfizh') or literally
     * titled "Tahfizh Al-Qur'an", which get "tahfizh".
     *
     * Schools that don't have grading_templates yet are left alone —
     * GradingDefaultsInstaller::assignDefaultTemplateToSubjectBooks()
     * (called from GradingDefaultsSeeder) covers them once the templates
     * are installed, including on a fresh test database where this
     * migration runs before any school/template exists.
     *
     * Data only — no schema change. down() is intentionally a no-op:
     * backfilled rows can't be told apart afterwards, and re-nulling them
     * would re-break grading.
     */
    public function up(): void
    {
        $schoolIds = DB::table('grading_templates')->distinct()->pluck('school_id');

        foreach ($schoolIds as $schoolId) {
            $templateIdsByCode = DB::table('grading_templates')
                ->where('school_id', $schoolId)
                ->pluck('id', 'code');

            $tahfizhTemplateId = $templateIdsByCode->get('tahfizh');
            $teoriKitabTemplateId = $templateIdsByCode->get('teori_kitab');

            if ($tahfizhTemplateId) {
                $tahfizhCategoryIds = DB::table('subject_categories')
                    ->where('school_id', $schoolId)
                    ->where('slug', 'tahfizh')
                    ->pluck('id');

                DB::table('subject_books')
                    ->where('school_id', $schoolId)
                    ->whereNull('grading_template_id')
                    ->where(function ($query) use ($tahfizhCategoryIds) {
                        $query->where('title', "Tahfizh Al-Qur'an");
                        if ($tahfizhCategoryIds->isNotEmpty()) {
                            $query->orWhereIn('subject_category_id', $tahfizhCategoryIds);
                        }
                    })
                    ->update(['grading_template_id' => $tahfizhTemplateId]);
            }

            if ($teoriKitabTemplateId) {
                DB::table('subject_books')
                    ->where('school_id', $schoolId)
                    ->whereNull('grading_template_id')
                    ->update(['grading_template_id' => $teoriKitabTemplateId]);
            }
        }
    }

    public function down(): void
    {
        // Intentionally empty — see up().
    }
};
