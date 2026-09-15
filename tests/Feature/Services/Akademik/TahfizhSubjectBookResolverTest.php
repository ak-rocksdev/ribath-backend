<?php

use App\Models\GradingTemplate;
use App\Models\School;
use App\Models\SubjectBook;
use App\Models\SubjectCategory;
use App\Services\Akademik\GradingDefaultsInstaller;
use App\Services\Akademik\TahfizhSubjectBookResolver;
use Database\Seeders\SchoolSeeder;
use Illuminate\Support\Carbon;

/*
 * A school with more than one Kitab Tahfizh resolves to the same one every
 * time: the oldest (created_at, then id), never whichever row the database
 * happens to return first.
 */

test('the oldest Kitab Tahfizh of the active school is resolved, whatever order the rows were stored in', function () {
    (new SchoolSeeder)->run();
    $school = School::where('is_active', true)->firstOrFail();
    app(GradingDefaultsInstaller::class)->installForSchool($school);
    $tahfizhTemplate = GradingTemplate::where('school_id', $school->id)->where('code', GradingTemplate::CODE_TAHFIZH)->firstOrFail();
    $subjectCategory = SubjectCategory::factory()->create(['school_id' => $school->id]);

    $makeTahfizhBook = fn (string $title, string $createdAt) => SubjectBook::factory()->create([
        'school_id' => $school->id,
        'subject_category_id' => $subjectCategory->id,
        'grading_template_id' => $tahfizhTemplate->id,
        'title' => $title,
        'created_at' => Carbon::parse($createdAt),
    ]);

    // The newer one is stored first.
    $makeTahfizhBook('Tahfizh Baru', '2025-09-01 08:00:00');
    $olderTahfizhBook = $makeTahfizhBook('Tahfizh Lama', '2025-07-01 08:00:00');

    expect(app(TahfizhSubjectBookResolver::class)->resolve()?->id)->toBe($olderTahfizhBook->id);
});
