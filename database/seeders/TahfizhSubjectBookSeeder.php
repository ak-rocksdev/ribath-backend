<?php

namespace Database\Seeders;

use App\Models\ClassLevel;
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
     * grading_template_id is intentionally left unset here — Task 4's
     * backfill sets it once the grading_templates table exists.
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

        SubjectBook::firstOrCreate(
            ['school_id' => $school->id, 'title' => "Tahfizh Al-Qur'an"],
            [
                'subject_category_id' => $tahfizhCategory->id,
                'class_levels' => $allClassLevelSlugs,
                'semesters' => [1, 2],
                'sessions_per_week' => 6,
            ]
        );
    }
}
