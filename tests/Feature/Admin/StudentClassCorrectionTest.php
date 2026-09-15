<?php

use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\GradingFactor;
use App\Models\GradingTemplate;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentGrade;
use App\Models\SubjectBook;
use App\Models\SubjectCategory;
use App\Models\Teacher;
use App\Models\TeachingSchedule;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\Akademik\AcademicSemesterService;
use App\Services\Akademik\GradingDefaultsInstaller;
use Database\Seeders\ClassLevelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SchoolSeeder;
use Illuminate\Support\Carbon;

/*
 * Ticket 20 — Koreksi Kelas dan Program santri.
 *
 * A santri's Kelas and Program are corrected through PUT /students/{student}
 * (class_level slug → class_level_id), and the dialog first reads
 * GET /students/{student}/class-change-impact to warn when the santri already
 * has Penilaian records in the active Semester Akademik.
 *
 * "Today" is frozen at Wednesday 2025-09-10; the kitab meets on Mondays,
 * so 2025-09-08 is a recordable Pertemuan date.
 */
beforeEach(function () {
    Carbon::setTestNow('2025-09-10 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * The active school with grading defaults, an active academic year whose
 * semester 1 (2025-07-01 … 2025-12-31) is the active one, and one
 * teori_kitab kitab scheduled on Mondays for both "tamhidi" (the old class)
 * and "ibtida_1" (the new class).
 *
 * @return array{admin: User, pengurus: User, school: School, academicYear: AcademicYear, oldClass: ClassLevel, newClass: ClassLevel, subjectBook: SubjectBook, oldClassSchedule: TeachingSchedule}
 */
function setUpClassCorrectionContext(): array
{
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();
    (new ClassLevelSeeder)->run();

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $pengurus = User::factory()->create();
    $pengurus->assignRole('pengurus_pesantren');

    $school = School::where('is_active', true)->firstOrFail();
    app(GradingDefaultsInstaller::class)->installForSchool($school);

    $academicYear = AcademicYear::factory()->create([
        'school_id' => $school->id,
        'name' => '2025/2026',
        'start_date' => '2025-07-01',
        'end_date' => '2026-06-30',
        'is_active' => true,
        'active_semester' => 1,
    ]);
    app(AcademicSemesterService::class)->createSemestersForAcademicYear($academicYear);
    app(AcademicSemesterService::class)->updateSemester($academicYear, 1, [
        'start_date' => '2025-07-01',
        'end_date' => '2025-12-31',
    ]);

    $oldClass = ClassLevel::where('school_id', $school->id)->where('slug', 'tamhidi')->firstOrFail();
    $newClass = ClassLevel::where('school_id', $school->id)->where('slug', 'ibtida_1')->firstOrFail();

    $subjectBook = SubjectBook::factory()->create([
        'school_id' => $school->id,
        'subject_category_id' => SubjectCategory::factory()->create(['school_id' => $school->id])->id,
        'grading_template_id' => GradingTemplate::where('school_id', $school->id)->where('code', GradingTemplate::CODE_TEORI_KITAB)->value('id'),
        'title' => 'Safinatun Najah',
    ]);
    $teacher = Teacher::factory()->create(['school_id' => $school->id, 'status' => Teacher::STATUS_ACTIVE]);

    $oldClassSchedule = classCorrectionSchedule($school, $academicYear, $oldClass, $subjectBook, $teacher);
    classCorrectionSchedule($school, $academicYear, $newClass, $subjectBook, $teacher);

    return compact('admin', 'pengurus', 'school', 'academicYear', 'oldClass', 'newClass', 'subjectBook', 'oldClassSchedule');
}

function classCorrectionSchedule(School $school, AcademicYear $academicYear, ClassLevel $classLevel, SubjectBook $subjectBook, Teacher $teacher): TeachingSchedule
{
    return TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'semester' => 1,
        'day_of_week' => 'monday',
        'time_slot_id' => TimeSlot::factory()->create(['school_id' => $school->id])->id,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
        'is_active' => true,
    ]);
}

/**
 * Creates a santri through the real POST /students endpoint, so school_id
 * and class_level_id are resolved the way production resolves them.
 */
function classCorrectionCreateStudent($testCase, User $user, string $fullName, string $classLevelSlug = 'tamhidi', string $program = 'tahfidz'): Student
{
    $response = $testCase->actingAs($user)->postJson('/api/v1/students', [
        'full_name' => $fullName,
        'birth_date' => '2012-05-15',
        'gender' => 'L',
        'program' => $program,
        'entry_date' => '2025-07-01',
        'class_level' => $classLevelSlug,
        'address' => 'Jl. Contoh No. 1',
    ]);

    $response->assertCreated();

    return Student::findOrFail($response->json('data.id'));
}

function classCorrectionGridStudentIds($testCase, array $context, ClassLevel $classLevel): array
{
    return $testCase->actingAs($context['admin'])->getJson('/api/v1/student-grades?'.http_build_query([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $context['subjectBook']->id,
    ]))->assertOk()->json('data.students.*.id');
}

/**
 * Gives the santri one of every Penilaian record in semester 1 of the old
 * class, through the real endpoints: four grades, one Tugas score, one
 * Absensi, then a final Rapor (which needs all of them complete).
 */
function classCorrectionRecordFullSemester($testCase, array $context, Student $student): void
{
    $semesterSelection = [
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'class_level_id' => $context['oldClass']->id,
        'subject_book_id' => $context['subjectBook']->id,
    ];

    $testCase->actingAs($context['admin'])->putJson('/api/v1/student-grades/bulk', [
        ...$semesterSelection,
        'rows' => [['student_id' => $student->id, 'scores' => ['uts' => 80, 'uas' => 90, 'keaktifan' => 3, 'adab' => 4]]],
    ])->assertOk();

    $taskId = $testCase->actingAs($context['admin'])->postJson('/api/v1/class-tasks', [
        ...$semesterSelection,
        'title' => 'Tugas Bab 1',
        'task_date' => '2025-08-01',
        'description' => null,
    ])->assertCreated()->json('data.id');

    $testCase->actingAs($context['admin'])->putJson("/api/v1/class-tasks/{$taskId}/scores/bulk", [
        'rows' => [['student_id' => $student->id, 'score' => 70]],
    ])->assertOk();

    $testCase->actingAs($context['admin'])->postJson('/api/v1/class-sessions', [
        'teaching_schedule_id' => $context['oldClassSchedule']->id,
        'session_date' => '2025-09-08',
        'attendances' => [['student_id' => $student->id, 'status' => 'present', 'notes' => null]],
    ])->assertCreated();

    $testCase->actingAs($context['admin'])->postJson('/api/v1/report-cards/finalize', [
        'student_id' => $student->id,
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
    ])->assertOk();
}

function classCorrectionMoveToOtherSchool(Student $student): void
{
    $otherSchool = School::factory()->create(['is_active' => false]);
    $student->school_id = $otherSchool->id;
    $student->save();
}

// ── PUT /students/{student} — Kelas & Program ────────────────────────────

test('correcting kelas and program syncs the class relation', function () {
    $context = setUpClassCorrectionContext();
    $student = classCorrectionCreateStudent($this, $context['pengurus'], 'Santri Koreksi', 'tamhidi', 'tahfidz');

    $this->actingAs($context['pengurus'])
        ->putJson("/api/v1/students/{$student->id}", ['class_level' => 'ibtida_1', 'program' => 'regular'])
        ->assertOk()
        ->assertJsonPath('data.class_level', 'ibtida_1')
        ->assertJsonPath('data.class_level_id', $context['newClass']->id)
        ->assertJsonPath('data.program', 'regular');

    $student->refresh();
    expect($student->class_level_id)->toBe($context['newClass']->id)
        ->and($student->class_level)->toBe('ibtida_1')
        ->and($student->program)->toBe('regular');
});

test('after a class correction the santri moves from the old class grade grid to the new one', function () {
    $context = setUpClassCorrectionContext();
    $student = classCorrectionCreateStudent($this, $context['admin'], 'Santri Pindah Roster');

    expect(classCorrectionGridStudentIds($this, $context, $context['oldClass']))->toContain($student->id);
    expect(classCorrectionGridStudentIds($this, $context, $context['newClass']))->not->toContain($student->id);

    $this->actingAs($context['admin'])
        ->putJson("/api/v1/students/{$student->id}", ['class_level' => 'ibtida_1', 'program' => 'tahfidz'])
        ->assertOk();

    expect(classCorrectionGridStudentIds($this, $context, $context['oldClass']))->not->toContain($student->id);
    expect(classCorrectionGridStudentIds($this, $context, $context['newClass']))->toContain($student->id);
});

test('clearing the kelas also clears the class relation', function () {
    $context = setUpClassCorrectionContext();
    $student = classCorrectionCreateStudent($this, $context['admin'], 'Santri Tanpa Kelas');

    $this->actingAs($context['admin'])
        ->putJson("/api/v1/students/{$student->id}", ['class_level' => null])
        ->assertOk()
        ->assertJsonPath('data.class_level', null)
        ->assertJsonPath('data.class_level_id', null);

    expect(classCorrectionGridStudentIds($this, $context, $context['oldClass']))->not->toContain($student->id);
});

test('a kelas that only exists in another school is rejected', function () {
    $context = setUpClassCorrectionContext();
    $student = classCorrectionCreateStudent($this, $context['admin'], 'Santri Kelas Asing');
    $otherSchool = School::factory()->create(['is_active' => false]);
    ClassLevel::create([
        'school_id' => $otherSchool->id,
        'slug' => 'kelas_sekolah_lain',
        'label' => 'Kelas Sekolah Lain',
        'category' => 'akademik',
        'sort_order' => 99,
        'is_active' => true,
    ]);

    $this->actingAs($context['admin'])
        ->putJson("/api/v1/students/{$student->id}", ['class_level' => 'kelas_sekolah_lain'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.class_level.0', 'Kelas tidak ditemukan di pesantren ini.');

    expect($student->fresh()->class_level_id)->toBe($context['oldClass']->id);
});

test('correcting kelas and program validates the program', function () {
    $context = setUpClassCorrectionContext();
    $student = classCorrectionCreateStudent($this, $context['admin'], 'Santri Program Salah');

    $this->actingAs($context['admin'])
        ->putJson("/api/v1/students/{$student->id}", ['class_level' => 'ibtida_1', 'program' => 'kilat'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.program.0', 'Program harus tahfidz atau regular.');
});

test('correcting kelas and program requires edit-students', function () {
    $context = setUpClassCorrectionContext();
    $student = classCorrectionCreateStudent($this, $context['admin'], 'Santri Tanpa Izin');
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('view-students');

    $this->actingAs($viewer)
        ->putJson("/api/v1/students/{$student->id}", ['class_level' => 'ibtida_1'])
        ->assertForbidden();

    expect($student->fresh()->class_level_id)->toBe($context['oldClass']->id);
});

test('a santri of another school 404s on update', function () {
    $context = setUpClassCorrectionContext();
    $student = classCorrectionCreateStudent($this, $context['admin'], 'Santri Sekolah Lain');
    classCorrectionMoveToOtherSchool($student);

    $this->actingAs($context['admin'])
        ->putJson("/api/v1/students/{$student->id}", ['class_level' => 'ibtida_1'])
        ->assertNotFound();

    expect($student->fresh()->class_level_id)->toBe($context['oldClass']->id);
});

// ── GET /students/{student}/class-change-impact ──────────────────────────

test('class change impact of a santri without records reports the current class and no records', function () {
    $context = setUpClassCorrectionContext();
    $student = classCorrectionCreateStudent($this, $context['pengurus'], 'Santri Baru', 'tamhidi', 'tahfidz');

    $this->actingAs($context['pengurus'])
        ->getJson("/api/v1/students/{$student->id}/class-change-impact")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.class_level.id', $context['oldClass']->id)
        ->assertJsonPath('data.class_level.slug', 'tamhidi')
        ->assertJsonPath('data.class_level.label', 'Tamhidi')
        ->assertJsonPath('data.program', 'tahfidz')
        ->assertJsonPath('data.academic_semester.academic_year_id', $context['academicYear']->id)
        ->assertJsonPath('data.academic_semester.academic_year_name', '2025/2026')
        ->assertJsonPath('data.academic_semester.semester', 1)
        ->assertJsonPath('data.record_counts', ['grades' => 0, 'task_scores' => 0, 'attendances' => 0, 'report_cards' => 0])
        ->assertJsonPath('data.has_records', false);
});

test('class change impact counts the santri records of the active semester only', function () {
    $context = setUpClassCorrectionContext();
    $student = classCorrectionCreateStudent($this, $context['admin'], 'Santri Bernilai');
    classCorrectionRecordFullSemester($this, $context, $student);
    $classmate = classCorrectionCreateStudent($this, $context['admin'], 'Teman Sekelas');

    // Not counted: a classmate's grade, a grade from semester 2, and a
    // grade that was saved and then cleared (score NULL, row kept).
    $this->actingAs($context['admin'])->putJson('/api/v1/student-grades/bulk', [
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'class_level_id' => $context['oldClass']->id,
        'subject_book_id' => $context['subjectBook']->id,
        'rows' => [['student_id' => $classmate->id, 'scores' => ['uts' => 75]]],
    ])->assertOk();
    StudentGrade::create([
        'school_id' => $context['school']->id,
        'student_id' => $student->id,
        'subject_book_id' => $context['subjectBook']->id,
        'grading_factor_id' => GradingFactor::where('school_id', $context['school']->id)->where('code', 'uts')->value('id'),
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 2,
        'class_level_id' => $context['oldClass']->id,
        'score' => 60,
    ]);
    StudentGrade::where('student_id', $student->id)
        ->where('semester', 1)
        ->where('grading_factor_id', GradingFactor::where('school_id', $context['school']->id)->where('code', 'uts')->value('id'))
        ->update(['score' => null]);

    $this->actingAs($context['admin'])
        ->getJson("/api/v1/students/{$student->id}/class-change-impact")
        ->assertOk()
        ->assertJsonPath('data.record_counts', ['grades' => 3, 'task_scores' => 1, 'attendances' => 1, 'report_cards' => 1])
        ->assertJsonPath('data.has_records', true);
});

test('class change impact without an active academic year has no semester and no records', function () {
    $context = setUpClassCorrectionContext();
    $student = classCorrectionCreateStudent($this, $context['admin'], 'Santri Tanpa Semester');
    $context['academicYear']->update(['is_active' => false]);

    $this->actingAs($context['admin'])
        ->getJson("/api/v1/students/{$student->id}/class-change-impact")
        ->assertOk()
        ->assertJsonPath('data.class_level.slug', 'tamhidi')
        ->assertJsonPath('data.academic_semester', null)
        ->assertJsonPath('data.record_counts', ['grades' => 0, 'task_scores' => 0, 'attendances' => 0, 'report_cards' => 0])
        ->assertJsonPath('data.has_records', false);
});

test('class change impact requires authentication', function () {
    $context = setUpClassCorrectionContext();
    $student = classCorrectionCreateStudent($this, $context['admin'], 'Santri Anonim');
    auth()->forgetGuards();

    $this->getJson("/api/v1/students/{$student->id}/class-change-impact")->assertUnauthorized();
});

test('class change impact requires edit-students', function () {
    $context = setUpClassCorrectionContext();
    $student = classCorrectionCreateStudent($this, $context['admin'], 'Santri Dilihat Saja');
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('view-students');

    $this->actingAs($viewer)
        ->getJson("/api/v1/students/{$student->id}/class-change-impact")
        ->assertForbidden();
});

test('a santri of another school 404s on class change impact', function () {
    $context = setUpClassCorrectionContext();
    $student = classCorrectionCreateStudent($this, $context['admin'], 'Santri Asing');
    classCorrectionMoveToOtherSchool($student);

    $this->actingAs($context['admin'])
        ->getJson("/api/v1/students/{$student->id}/class-change-impact")
        ->assertNotFound();
});
