<?php

namespace Database\Seeders;

use App\Models\School;
use App\Services\Akademik\GradingDefaultsInstaller;
use Illuminate\Database\Seeder;

class GradingDefaultsSeeder extends Seeder
{
    /**
     * Installs the fixed grading templates/factors for the active school,
     * ensures every one of its existing academic_semesters has
     * grading_template_factors weights, and backfills grading_template_id
     * on any subject_books left over from before this feature existed
     * (idempotent — safe to re-run, including via `--seed` in production).
     */
    public function run(): void
    {
        $school = School::activeOrFail();

        $installer = app(GradingDefaultsInstaller::class);
        $installer->installForSchool($school);
        $installer->ensureWeightsForAllSemesters($school);
        $installer->assignDefaultTemplateToSubjectBooks($school);
    }
}
