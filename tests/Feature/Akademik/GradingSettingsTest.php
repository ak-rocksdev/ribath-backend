<?php

use App\Models\AcademicYear;
use App\Models\GradingFactor;
use App\Models\GradingTemplate;
use App\Models\GradingTemplateFactor;
use App\Models\School;
use App\Models\SubjectBook;
use App\Models\SubjectCategory;
use App\Models\User;
use App\Services\Akademik\AcademicSemesterService;
use App\Services\Akademik\GradingDefaultsInstaller;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SchoolSeeder;

function createSchoolAndUserForGrading(): array
{
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();

    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $school = School::where('is_active', true)->first();

    return [$user, $school];
}

/**
 * Creates an academic year (via the real endpoint's service, so its two
 * semesters are auto-created) and returns it fresh with semesters loaded.
 */
function createAcademicYearWithSemesters(School $school, string $name, string $startDate, string $endDate): AcademicYear
{
    $academicYear = AcademicYear::factory()->create([
        'school_id' => $school->id,
        'name' => $name,
        'start_date' => $startDate,
        'end_date' => $endDate,
    ]);

    app(AcademicSemesterService::class)->createSemestersForAcademicYear($academicYear);

    return $academicYear->fresh('semesters');
}

// ── Installer ────────────────────────────────────────────────────────────

test('installForSchool creates 2 templates and 10 factors', function () {
    [, $school] = createSchoolAndUserForGrading();

    app(GradingDefaultsInstaller::class)->installForSchool($school);

    expect(GradingTemplate::where('school_id', $school->id)->count())->toBe(2);
    expect(GradingFactor::where('school_id', $school->id)->count())->toBe(10);

    $this->assertDatabaseHas('grading_templates', ['school_id' => $school->id, 'code' => 'teori_kitab', 'name' => 'Teori/Kitab']);
    $this->assertDatabaseHas('grading_templates', ['school_id' => $school->id, 'code' => 'tahfizh', 'name' => 'Tahfizh']);

    $uts = GradingFactor::where('school_id', $school->id)->where('code', 'uts')->first();
    expect($uts->input_type)->toBe('manual_once');
    expect($uts->score_scale)->toBe('percent');
    expect($uts->is_midterm_exam)->toBeTrue();
    expect($uts->sort_order)->toBe(1);

    $keaktifan = GradingFactor::where('school_id', $school->id)->where('code', 'keaktifan')->first();
    expect($keaktifan->score_scale)->toBe('level_1_4');
    expect($keaktifan->scale_levels)->toHaveCount(4);
    expect($keaktifan->scale_levels[0])->toMatchArray(['level' => 1, 'label' => 'Kurang', 'score' => 60]);
});

test('installForSchool is idempotent', function () {
    [, $school] = createSchoolAndUserForGrading();

    $installer = app(GradingDefaultsInstaller::class);
    $installer->installForSchool($school);
    $installer->installForSchool($school);

    expect(GradingTemplate::where('school_id', $school->id)->count())->toBe(2);
    expect(GradingFactor::where('school_id', $school->id)->count())->toBe(10);
});

test('ensureWeightsForSemester uses default weights when no earlier semester has rows', function () {
    [, $school] = createSchoolAndUserForGrading();

    app(GradingDefaultsInstaller::class)->installForSchool($school);

    $academicYear = createAcademicYearWithSemesters($school, '2025/2026', '2025-07-01', '2026-06-30');
    $semesterOne = $academicYear->semester(1);

    expect(GradingTemplateFactor::where('academic_year_id', $academicYear->id)->where('semester', 1)->count())->toBe(10);

    $template = GradingTemplate::where('school_id', $school->id)->where('code', 'teori_kitab')->first();
    $uasFactor = GradingFactor::where('school_id', $school->id)->where('code', 'uas')->first();

    $row = GradingTemplateFactor::where([
        'grading_template_id' => $template->id,
        'grading_factor_id' => $uasFactor->id,
        'academic_year_id' => $academicYear->id,
        'semester' => 1,
    ])->first();

    expect((float) $row->weight)->toBe(30.0);
    expect($row->is_active)->toBeTrue();
});

test('a new academic years first semester copies weights from the most recent earlier semester', function () {
    [, $school] = createSchoolAndUserForGrading();

    app(GradingDefaultsInstaller::class)->installForSchool($school);

    $yearOne = createAcademicYearWithSemesters($school, '2025/2026', '2025-07-01', '2026-06-30');

    $template = GradingTemplate::where('school_id', $school->id)->where('code', 'teori_kitab')->first();

    // Change the weights of year one's SECOND semester (the most recent
    // semester chronologically before the next academic year's first
    // semester) so we can prove the copy source is chosen correctly.
    $factorRows = GradingTemplateFactor::where('grading_template_id', $template->id)
        ->where('academic_year_id', $yearOne->id)
        ->where('semester', 2)
        ->get()
        ->keyBy(fn ($row) => GradingFactor::find($row->grading_factor_id)->code);

    $factorRows['uts']->update(['weight' => 15, 'is_active' => true]);
    $factorRows['uas']->update(['weight' => 25, 'is_active' => true]);
    $factorRows['tugas']->update(['weight' => 20, 'is_active' => true]);
    $factorRows['keaktifan']->update(['weight' => 15, 'is_active' => true]);
    $factorRows['adab']->update(['weight' => 15, 'is_active' => true]);
    $factorRows['absensi']->update(['weight' => 10, 'is_active' => true]);

    $yearTwo = createAcademicYearWithSemesters($school, '2026/2027', '2026-07-01', '2027-06-30');

    $utsFactor = GradingFactor::where('school_id', $school->id)->where('code', 'uts')->first();

    $copiedRow = GradingTemplateFactor::where([
        'grading_template_id' => $template->id,
        'grading_factor_id' => $utsFactor->id,
        'academic_year_id' => $yearTwo->id,
        'semester' => 1,
    ])->first();

    expect((float) $copiedRow->weight)->toBe(15.0);

    // Year one's semester 1 (untouched, still default weights) must NOT be
    // the source — this proves ordering picks the chronologically closer
    // semester (year one's semester 2), not merely "an earlier semester".
    $yearOneSemesterOneRow = GradingTemplateFactor::where([
        'grading_template_id' => $template->id,
        'grading_factor_id' => $utsFactor->id,
        'academic_year_id' => $yearOne->id,
        'semester' => 1,
    ])->first();

    expect((float) $yearOneSemesterOneRow->weight)->toBe(20.0);
});

test('ensureWeightsForSemester does nothing when the school has no templates installed yet', function () {
    [, $school] = createSchoolAndUserForGrading();

    // No installForSchool() call — templates/factors don't exist yet.
    $academicYear = createAcademicYearWithSemesters($school, '2025/2026', '2025-07-01', '2026-06-30');

    expect(GradingTemplateFactor::where('academic_year_id', $academicYear->id)->count())->toBe(0);
});

test('ensureWeightsForAllSemesters seeds every existing semester of the school', function () {
    [, $school] = createSchoolAndUserForGrading();

    createAcademicYearWithSemesters($school, '2025/2026', '2025-07-01', '2026-06-30');
    createAcademicYearWithSemesters($school, '2026/2027', '2026-07-01', '2027-06-30');

    $installer = app(GradingDefaultsInstaller::class);
    $installer->installForSchool($school);
    $installer->ensureWeightsForAllSemesters($school);

    expect(GradingTemplateFactor::where('school_id', $school->id)->count())->toBe(4 * 10);
});

test('assignDefaultTemplateToSubjectBooks assigns teori_kitab to a book without a template', function () {
    [, $school] = createSchoolAndUserForGrading();
    app(GradingDefaultsInstaller::class)->installForSchool($school);

    $category = SubjectCategory::factory()->create(['school_id' => $school->id, 'slug' => 'nahwu']);
    $book = SubjectBook::factory()->create(['school_id' => $school->id, 'subject_category_id' => $category->id]);

    app(GradingDefaultsInstaller::class)->assignDefaultTemplateToSubjectBooks($school);

    $teoriKitab = GradingTemplate::where('school_id', $school->id)->where('code', 'teori_kitab')->firstOrFail();

    expect($book->fresh()->grading_template_id)->toBe($teoriKitab->id);
});

test('assignDefaultTemplateToSubjectBooks assigns tahfizh to the tahfizh fanns book', function () {
    [, $school] = createSchoolAndUserForGrading();
    app(GradingDefaultsInstaller::class)->installForSchool($school);

    $tahfizhCategory = SubjectCategory::factory()->create(['school_id' => $school->id, 'slug' => 'tahfizh']);
    $book = SubjectBook::factory()->create([
        'school_id' => $school->id,
        'subject_category_id' => $tahfizhCategory->id,
        'title' => "Tahfizh Al-Qur'an",
    ]);

    app(GradingDefaultsInstaller::class)->assignDefaultTemplateToSubjectBooks($school);

    $tahfizhTemplate = GradingTemplate::where('school_id', $school->id)->where('code', 'tahfizh')->firstOrFail();

    expect($book->fresh()->grading_template_id)->toBe($tahfizhTemplate->id);
});

test('assignDefaultTemplateToSubjectBooks does nothing when no templates are installed', function () {
    [, $school] = createSchoolAndUserForGrading();

    $category = SubjectCategory::factory()->create(['school_id' => $school->id]);
    $book = SubjectBook::factory()->create(['school_id' => $school->id, 'subject_category_id' => $category->id]);

    app(GradingDefaultsInstaller::class)->assignDefaultTemplateToSubjectBooks($school);

    expect($book->fresh()->grading_template_id)->toBeNull();
});

test('assignDefaultTemplateToSubjectBooks does not overwrite a books existing template', function () {
    [, $school] = createSchoolAndUserForGrading();
    app(GradingDefaultsInstaller::class)->installForSchool($school);

    $tahfizhTemplate = GradingTemplate::where('school_id', $school->id)->where('code', 'tahfizh')->firstOrFail();
    $category = SubjectCategory::factory()->create(['school_id' => $school->id, 'slug' => 'nahwu']);
    $book = SubjectBook::factory()->create([
        'school_id' => $school->id,
        'subject_category_id' => $category->id,
        'grading_template_id' => $tahfizhTemplate->id,
    ]);

    app(GradingDefaultsInstaller::class)->assignDefaultTemplateToSubjectBooks($school);

    expect($book->fresh()->grading_template_id)->toBe($tahfizhTemplate->id);
});

// ── GET /grading-templates ────────────────────────────────────────────────

test('can list grading templates', function () {
    [$user, $school] = createSchoolAndUserForGrading();
    app(GradingDefaultsInstaller::class)->installForSchool($school);

    $response = $this->actingAs($user)->getJson('/api/v1/grading-templates');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(2, 'data');
});

test('listing grading templates requires permission', function () {
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();
    $school = School::where('is_active', true)->first();
    app(GradingDefaultsInstaller::class)->installForSchool($school);

    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/v1/grading-templates')->assertForbidden();
});

// ── GET /grading-factors ─────────────────────────────────────────────────

test('can list grading factors ordered by sort_order', function () {
    [$user, $school] = createSchoolAndUserForGrading();
    app(GradingDefaultsInstaller::class)->installForSchool($school);

    $response = $this->actingAs($user)->getJson('/api/v1/grading-factors');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('data.0.code', 'uts')
        ->assertJsonPath('data.9.code', 'uas_tahfizh');
});

test('listing grading factors requires permission', function () {
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();
    $school = School::where('is_active', true)->first();
    app(GradingDefaultsInstaller::class)->installForSchool($school);

    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/v1/grading-factors')->assertForbidden();
});

// ── PUT /grading-factors/{gradingFactor} ─────────────────────────────────

test('can update a level_1_4 factors name and scale_levels', function () {
    [$user, $school] = createSchoolAndUserForGrading();
    app(GradingDefaultsInstaller::class)->installForSchool($school);

    $adab = GradingFactor::where('school_id', $school->id)->where('code', 'adab')->first();

    $newScaleLevels = [
        ['level' => 1, 'label' => 'Kurang Baik', 'description' => 'Perlu bimbingan intensif', 'score' => 55],
        ['level' => 2, 'label' => 'Cukup', 'description' => 'Sudah cukup baik', 'score' => 70],
        ['level' => 3, 'label' => 'Baik', 'description' => 'Adab terjaga', 'score' => 85],
        ['level' => 4, 'label' => 'Sangat Baik', 'description' => 'Teladan bagi santri lain', 'score' => 100],
    ];

    $response = $this->actingAs($user)->putJson("/api/v1/grading-factors/{$adab->id}", [
        'name' => 'Adab & Akhlak',
        'scale_levels' => $newScaleLevels,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.name', 'Adab & Akhlak')
        ->assertJsonPath('data.scale_levels.0.label', 'Kurang Baik');

    $this->assertDatabaseHas('grading_factors', ['id' => $adab->id, 'name' => 'Adab & Akhlak']);
});

test('updating a factor allows a partial name-only change', function () {
    [$user, $school] = createSchoolAndUserForGrading();
    app(GradingDefaultsInstaller::class)->installForSchool($school);

    $uts = GradingFactor::where('school_id', $school->id)->where('code', 'uts')->first();

    $response = $this->actingAs($user)->putJson("/api/v1/grading-factors/{$uts->id}", [
        'name' => 'UTS (Ujian Tengah Semester)',
    ]);

    $response->assertOk()->assertJsonPath('data.name', 'UTS (Ujian Tengah Semester)');
});

test('updating scale_levels on a percent-scale factor is rejected', function () {
    [$user, $school] = createSchoolAndUserForGrading();
    app(GradingDefaultsInstaller::class)->installForSchool($school);

    $uts = GradingFactor::where('school_id', $school->id)->where('code', 'uts')->first();

    $response = $this->actingAs($user)->putJson("/api/v1/grading-factors/{$uts->id}", [
        'scale_levels' => [
            ['level' => 1, 'label' => 'Kurang', 'description' => 'x', 'score' => 60],
            ['level' => 2, 'label' => 'Cukup', 'description' => 'x', 'score' => 75],
            ['level' => 3, 'label' => 'Baik', 'description' => 'x', 'score' => 85],
            ['level' => 4, 'label' => 'Sangat Baik', 'description' => 'x', 'score' => 100],
        ],
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors(['scale_levels']);
});

test('updating scale_levels requires exactly levels 1 through 4', function () {
    [$user, $school] = createSchoolAndUserForGrading();
    app(GradingDefaultsInstaller::class)->installForSchool($school);

    $adab = GradingFactor::where('school_id', $school->id)->where('code', 'adab')->first();

    $response = $this->actingAs($user)->putJson("/api/v1/grading-factors/{$adab->id}", [
        'scale_levels' => [
            ['level' => 1, 'label' => 'Kurang', 'description' => 'x', 'score' => 60],
            ['level' => 2, 'label' => 'Cukup', 'description' => 'x', 'score' => 75],
            ['level' => 2, 'label' => 'Cukup Lagi', 'description' => 'x', 'score' => 80],
        ],
    ]);

    $response->assertUnprocessable();
});

test('updating scale_levels rejects a score outside 0 to 100', function () {
    [$user, $school] = createSchoolAndUserForGrading();
    app(GradingDefaultsInstaller::class)->installForSchool($school);

    $adab = GradingFactor::where('school_id', $school->id)->where('code', 'adab')->first();

    $response = $this->actingAs($user)->putJson("/api/v1/grading-factors/{$adab->id}", [
        'scale_levels' => [
            ['level' => 1, 'label' => 'Kurang', 'description' => 'x', 'score' => -5],
            ['level' => 2, 'label' => 'Cukup', 'description' => 'x', 'score' => 75],
            ['level' => 3, 'label' => 'Baik', 'description' => 'x', 'score' => 85],
            ['level' => 4, 'label' => 'Sangat Baik', 'description' => 'x', 'score' => 105],
        ],
    ]);

    $response->assertUnprocessable();
});

test('updating a factor requires manage-grading-settings permission', function () {
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();
    $school = School::where('is_active', true)->first();
    app(GradingDefaultsInstaller::class)->installForSchool($school);
    $uts = GradingFactor::where('school_id', $school->id)->where('code', 'uts')->first();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson("/api/v1/grading-factors/{$uts->id}", ['name' => 'x'])
        ->assertForbidden();
});

test('updating a factor from another school returns 404', function () {
    [$user] = createSchoolAndUserForGrading();

    $otherSchool = School::factory()->create();
    app(GradingDefaultsInstaller::class)->installForSchool($otherSchool);
    $otherFactor = GradingFactor::where('school_id', $otherSchool->id)->where('code', 'uts')->first();

    $this->actingAs($user)
        ->putJson("/api/v1/grading-factors/{$otherFactor->id}", ['name' => 'x'])
        ->assertNotFound();
});

// ── GET /grading-template-factors ────────────────────────────────────────

test('can get semester weights grouped by template', function () {
    [$user, $school] = createSchoolAndUserForGrading();
    app(GradingDefaultsInstaller::class)->installForSchool($school);
    $academicYear = createAcademicYearWithSemesters($school, '2025/2026', '2025-07-01', '2026-06-30');

    $response = $this->actingAs($user)->getJson('/api/v1/grading-template-factors?'.http_build_query([
        'academic_year_id' => $academicYear->id,
        'semester' => 1,
    ]));

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(2, 'data');

    $teoriKitab = collect($response->json('data'))->firstWhere('code', 'teori_kitab');
    expect($teoriKitab['has_grades'])->toBeFalse();
    expect($teoriKitab['factors'])->toHaveCount(6);
    expect($teoriKitab['factors'][0]['code'])->toBe('uts');
    expect($teoriKitab['factors'][0]['weight'])->toEqual(20.0);

    $tahfizh = collect($response->json('data'))->firstWhere('code', 'tahfizh');
    expect($tahfizh['factors'])->toHaveCount(4);
});

test('getting semester weights requires an academic_year_id and semester', function () {
    [$user, $school] = createSchoolAndUserForGrading();
    app(GradingDefaultsInstaller::class)->installForSchool($school);

    $this->actingAs($user)
        ->getJson('/api/v1/grading-template-factors')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['academic_year_id', 'semester']);
});

test('getting semester weights for another schools academic year is rejected', function () {
    [$user] = createSchoolAndUserForGrading();

    $otherSchool = School::factory()->create();
    app(GradingDefaultsInstaller::class)->installForSchool($otherSchool);
    $otherAcademicYear = createAcademicYearWithSemesters($otherSchool, '2025/2026', '2025-07-01', '2026-06-30');

    $this->actingAs($user)
        ->getJson('/api/v1/grading-template-factors?'.http_build_query([
            'academic_year_id' => $otherAcademicYear->id,
            'semester' => 1,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['academic_year_id']);
});

test('getting semester weights requires view-grades permission', function () {
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();
    $school = School::where('is_active', true)->first();
    app(GradingDefaultsInstaller::class)->installForSchool($school);
    $academicYear = createAcademicYearWithSemesters($school, '2025/2026', '2025-07-01', '2026-06-30');

    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/grading-template-factors?'.http_build_query([
            'academic_year_id' => $academicYear->id,
            'semester' => 1,
        ]))
        ->assertForbidden();
});

// ── PUT /grading-template-factors ────────────────────────────────────────

test('can replace semester weights for a template when the active sum is 100', function () {
    [$user, $school] = createSchoolAndUserForGrading();
    app(GradingDefaultsInstaller::class)->installForSchool($school);
    $academicYear = createAcademicYearWithSemesters($school, '2025/2026', '2025-07-01', '2026-06-30');

    $template = GradingTemplate::where('school_id', $school->id)->where('code', 'teori_kitab')->first();
    $factorsByCode = GradingFactor::where('school_id', $school->id)->get()->keyBy('code');

    $response = $this->actingAs($user)->putJson('/api/v1/grading-template-factors', [
        'academic_year_id' => $academicYear->id,
        'semester' => 1,
        'grading_template_id' => $template->id,
        'factors' => [
            ['grading_factor_id' => $factorsByCode['uts']->id, 'weight' => 25, 'is_active' => true],
            ['grading_factor_id' => $factorsByCode['uas']->id, 'weight' => 25, 'is_active' => true],
            ['grading_factor_id' => $factorsByCode['tugas']->id, 'weight' => 20, 'is_active' => true],
            ['grading_factor_id' => $factorsByCode['keaktifan']->id, 'weight' => 10, 'is_active' => true],
            ['grading_factor_id' => $factorsByCode['adab']->id, 'weight' => 10, 'is_active' => true],
            ['grading_factor_id' => $factorsByCode['absensi']->id, 'weight' => 10, 'is_active' => true],
        ],
    ]);

    $response->assertOk()->assertJsonPath('success', true);

    $this->assertDatabaseHas('grading_template_factors', [
        'grading_template_id' => $template->id,
        'grading_factor_id' => $factorsByCode['uts']->id,
        'academic_year_id' => $academicYear->id,
        'semester' => 1,
        'weight' => 25.00,
    ]);
});

test('replacing semester weights allows an inactive factor to keep its stored weight out of the sum', function () {
    [$user, $school] = createSchoolAndUserForGrading();
    app(GradingDefaultsInstaller::class)->installForSchool($school);
    $academicYear = createAcademicYearWithSemesters($school, '2025/2026', '2025-07-01', '2026-06-30');

    $template = GradingTemplate::where('school_id', $school->id)->where('code', 'teori_kitab')->first();
    $factorsByCode = GradingFactor::where('school_id', $school->id)->get()->keyBy('code');

    // absensi deactivated; its 10 must NOT count toward the 100 sum, so the
    // remaining active factors are re-balanced to still add up to 100.
    $response = $this->actingAs($user)->putJson('/api/v1/grading-template-factors', [
        'academic_year_id' => $academicYear->id,
        'semester' => 1,
        'grading_template_id' => $template->id,
        'factors' => [
            ['grading_factor_id' => $factorsByCode['uts']->id, 'weight' => 25, 'is_active' => true],
            ['grading_factor_id' => $factorsByCode['uas']->id, 'weight' => 35, 'is_active' => true],
            ['grading_factor_id' => $factorsByCode['tugas']->id, 'weight' => 20, 'is_active' => true],
            ['grading_factor_id' => $factorsByCode['keaktifan']->id, 'weight' => 10, 'is_active' => true],
            ['grading_factor_id' => $factorsByCode['adab']->id, 'weight' => 10, 'is_active' => true],
            ['grading_factor_id' => $factorsByCode['absensi']->id, 'weight' => 40, 'is_active' => false],
        ],
    ]);

    $response->assertOk();

    $this->assertDatabaseHas('grading_template_factors', [
        'grading_template_id' => $template->id,
        'grading_factor_id' => $factorsByCode['absensi']->id,
        'is_active' => false,
        'weight' => 40.00,
    ]);
});

test('replacing semester weights rejects an active weight sum that is not 100', function () {
    [$user, $school] = createSchoolAndUserForGrading();
    app(GradingDefaultsInstaller::class)->installForSchool($school);
    $academicYear = createAcademicYearWithSemesters($school, '2025/2026', '2025-07-01', '2026-06-30');

    $template = GradingTemplate::where('school_id', $school->id)->where('code', 'teori_kitab')->first();
    $factorsByCode = GradingFactor::where('school_id', $school->id)->get()->keyBy('code');

    $response = $this->actingAs($user)->putJson('/api/v1/grading-template-factors', [
        'academic_year_id' => $academicYear->id,
        'semester' => 1,
        'grading_template_id' => $template->id,
        'factors' => [
            ['grading_factor_id' => $factorsByCode['uts']->id, 'weight' => 20, 'is_active' => true],
            ['grading_factor_id' => $factorsByCode['uas']->id, 'weight' => 30, 'is_active' => true],
            ['grading_factor_id' => $factorsByCode['tugas']->id, 'weight' => 20, 'is_active' => true],
            ['grading_factor_id' => $factorsByCode['keaktifan']->id, 'weight' => 10, 'is_active' => true],
            ['grading_factor_id' => $factorsByCode['adab']->id, 'weight' => 10, 'is_active' => true],
            ['grading_factor_id' => $factorsByCode['absensi']->id, 'weight' => 5, 'is_active' => true],
        ],
    ]);

    $response->assertUnprocessable()
        ->assertJsonPath('message', 'Jumlah bobot faktor aktif harus 100%.');
});

test('replacing semester weights rejects a factor list that does not exactly match the template', function () {
    [$user, $school] = createSchoolAndUserForGrading();
    app(GradingDefaultsInstaller::class)->installForSchool($school);
    $academicYear = createAcademicYearWithSemesters($school, '2025/2026', '2025-07-01', '2026-06-30');

    $template = GradingTemplate::where('school_id', $school->id)->where('code', 'teori_kitab')->first();
    $factorsByCode = GradingFactor::where('school_id', $school->id)->get()->keyBy('code');

    // Missing 'absensi', and includes a tahfizh factor that doesn't belong
    // to this template at all.
    $response = $this->actingAs($user)->putJson('/api/v1/grading-template-factors', [
        'academic_year_id' => $academicYear->id,
        'semester' => 1,
        'grading_template_id' => $template->id,
        'factors' => [
            ['grading_factor_id' => $factorsByCode['uts']->id, 'weight' => 20, 'is_active' => true],
            ['grading_factor_id' => $factorsByCode['uas']->id, 'weight' => 30, 'is_active' => true],
            ['grading_factor_id' => $factorsByCode['tugas']->id, 'weight' => 20, 'is_active' => true],
            ['grading_factor_id' => $factorsByCode['keaktifan']->id, 'weight' => 10, 'is_active' => true],
            ['grading_factor_id' => $factorsByCode['adab']->id, 'weight' => 20, 'is_active' => true],
        ],
    ]);

    $response->assertUnprocessable()
        ->assertJsonPath('message', 'Daftar faktor tidak sesuai dengan template ini.');
});

test('replacing semester weights rejects a weight with more than 2 decimal places', function () {
    [$user, $school] = createSchoolAndUserForGrading();
    app(GradingDefaultsInstaller::class)->installForSchool($school);
    $academicYear = createAcademicYearWithSemesters($school, '2025/2026', '2025-07-01', '2026-06-30');

    $template = GradingTemplate::where('school_id', $school->id)->where('code', 'teori_kitab')->first();
    $factorsByCode = GradingFactor::where('school_id', $school->id)->get()->keyBy('code');

    $response = $this->actingAs($user)->putJson('/api/v1/grading-template-factors', [
        'academic_year_id' => $academicYear->id,
        'semester' => 1,
        'grading_template_id' => $template->id,
        'factors' => [
            ['grading_factor_id' => $factorsByCode['uts']->id, 'weight' => 20.123, 'is_active' => true],
            ['grading_factor_id' => $factorsByCode['uas']->id, 'weight' => 30, 'is_active' => true],
            ['grading_factor_id' => $factorsByCode['tugas']->id, 'weight' => 20, 'is_active' => true],
            ['grading_factor_id' => $factorsByCode['keaktifan']->id, 'weight' => 10, 'is_active' => true],
            ['grading_factor_id' => $factorsByCode['adab']->id, 'weight' => 9.877, 'is_active' => true],
            ['grading_factor_id' => $factorsByCode['absensi']->id, 'weight' => 10, 'is_active' => true],
        ],
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors(['factors.0.weight']);
});

test('replacing semester weights for another schools academic year is rejected', function () {
    [$user, $school] = createSchoolAndUserForGrading();
    app(GradingDefaultsInstaller::class)->installForSchool($school);

    $otherSchool = School::factory()->create();
    app(GradingDefaultsInstaller::class)->installForSchool($otherSchool);
    $otherAcademicYear = createAcademicYearWithSemesters($otherSchool, '2025/2026', '2025-07-01', '2026-06-30');

    $template = GradingTemplate::where('school_id', $school->id)->where('code', 'teori_kitab')->first();
    $factorsByCode = GradingFactor::where('school_id', $school->id)->get()->keyBy('code');

    $response = $this->actingAs($user)->putJson('/api/v1/grading-template-factors', [
        'academic_year_id' => $otherAcademicYear->id,
        'semester' => 1,
        'grading_template_id' => $template->id,
        'factors' => [
            ['grading_factor_id' => $factorsByCode['uts']->id, 'weight' => 100, 'is_active' => true],
        ],
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors(['academic_year_id']);
});

test('replacing semester weights requires manage-grading-settings permission', function () {
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();
    $school = School::where('is_active', true)->first();
    app(GradingDefaultsInstaller::class)->installForSchool($school);
    $academicYear = createAcademicYearWithSemesters($school, '2025/2026', '2025-07-01', '2026-06-30');
    $template = GradingTemplate::where('school_id', $school->id)->where('code', 'teori_kitab')->first();
    $factorsByCode = GradingFactor::where('school_id', $school->id)->get()->keyBy('code');

    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/api/v1/grading-template-factors', [
        'academic_year_id' => $academicYear->id,
        'semester' => 1,
        'grading_template_id' => $template->id,
        'factors' => [
            ['grading_factor_id' => $factorsByCode['uts']->id, 'weight' => 100, 'is_active' => true],
        ],
    ])->assertForbidden();
});
