<?php

namespace Database\Seeders;

use App\Models\ClassLevel;
use App\Models\GradingTemplate;
use App\Models\School;
use App\Models\SubjectBook;
use App\Models\SubjectCategory;
use Illuminate\Database\Seeder;

class TahfizhSubjectBookSeeder extends Seeder
{
    /**
     * Creates the "Tahfizh Al-Qur'an" subject book for the active school,
     * under the `tahfizh` fann seeded by SubjectCategorySeeder. It applies
     * to every class level of the school, both semesters, 6 sessions/week.
     *
     * Sets grading_template_id to the school's "tahfizh" template when one
     * already exists (DatabaseSeeder runs GradingDefaultsSeeder first, so
     * on a fresh seed it always does). Otherwise it's left null —
     * GradingDefaultsInstaller::assignDefaultTemplateToSubjectBooks()
     * (called from GradingDefaultsSeeder) backfills it once templates are
     * installed.
     */
    public function run(): void
    {
        $school = School::activeOrFail();

        $tahfizhCategory = SubjectCategory::where('school_id', $school->id)
            ->where('slug', 'tahfizh')
            ->first();

        if (! $tahfizhCategory) {
            return;
        }

        $allClassLevelSlugs = ClassLevel::where('school_id', $school->id)
            ->pluck('slug')
            ->all();

        $tahfizhTemplate = GradingTemplate::where('school_id', $school->id)
            ->where('code', GradingTemplate::CODE_TAHFIZH)
            ->first();

        SubjectBook::firstOrCreate(
            ['school_id' => $school->id, 'title' => "Tahfizh Al-Qur'an"],
            [
                'subject_category_id' => $tahfizhCategory->id,
                'class_levels' => $allClassLevelSlugs,
                'semesters' => [1, 2],
                'sessions_per_week' => 6,
                'grading_template_id' => $tahfizhTemplate?->id,
            ]
        );
    }
}
