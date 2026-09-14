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
use Illuminate\Support\Collection;

/**
 * Seeds roles, the active school, its class levels and grading defaults,
 * creates an academic year with both semesters (and their weight rows),
 * and schedules one teori_kitab kitab for the "tamhidi" class in
 * semester 1.
 *
 * @return array{user: User, school: School, academicYear: AcademicYear, classLevel: ClassLevel, subjectBook: SubjectBook, teacher: Teacher, factorsByCode: Collection}
 */
function setUpStudentGradeContext(): array
{
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();
    (new ClassLevelSeeder)->run();

    $user = User::factory()->create(['name' => 'Ustadz Penilai']);
    $user->assignRole('super_admin');

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

    $classLevel = ClassLevel::where('school_id', $school->id)->where('slug', 'tamhidi')->firstOrFail();
    $teoriKitabTemplate = GradingTemplate::where('school_id', $school->id)->where('code', 'teori_kitab')->firstOrFail();

    $subjectBook = createGradableSubjectBook($school, 'Safinatun Najah', $teoriKitabTemplate->id);
    $teacher = Teacher::factory()->create(['school_id' => $school->id, 'full_name' => 'Ustadz Ahmad']);

    scheduleSubjectBookForClass($school, $academicYear, 1, $classLevel, $subjectBook, $teacher);

    $factorsByCode = GradingFactor::where('school_id', $school->id)->get()->keyBy('code');

    return compact('user', 'school', 'academicYear', 'classLevel', 'subjectBook', 'teacher', 'factorsByCode');
}

function createGradableSubjectBook(School $school, string $title, ?string $gradingTemplateId): SubjectBook
{
    $subjectCategory = SubjectCategory::factory()->create(['school_id' => $school->id]);

    return SubjectBook::factory()->create([
        'school_id' => $school->id,
        'subject_category_id' => $subjectCategory->id,
        'grading_template_id' => $gradingTemplateId,
        'title' => $title,
    ]);
}

function scheduleSubjectBookForClass(
    School $school,
    AcademicYear $academicYear,
    int $semester,
    ClassLevel $classLevel,
    SubjectBook $subjectBook,
    Teacher $teacher,
    bool $isActive = true,
): TeachingSchedule {
    return TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'semester' => $semester,
        'time_slot_id' => TimeSlot::factory()->create(['school_id' => $school->id])->id,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
        'is_active' => $isActive,
    ]);
}

/**
 * Creates a student through the real POST /students endpoint (so school_id
 * and class_level_id are resolved the same way production does it).
 */
function createStudentThroughEndpointForGrading($testCase, User $user, string $fullName, ?string $classLevelSlug = 'tamhidi'): Student
{
    $response = $testCase->actingAs($user)->postJson('/api/v1/students', [
        'full_name' => $fullName,
        'birth_date' => '2012-05-15',
        'gender' => 'L',
        'program' => 'regular',
        'entry_date' => '2025-07-01',
        'class_level' => $classLevelSlug,
        'address' => 'Jl. Contoh No. 1',
    ]);

    $response->assertCreated();

    return Student::findOrFail($response->json('data.id'));
}

function studentGradeGridQuery(array $context): string
{
    return '/api/v1/student-grades?'.http_build_query([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'class_level_id' => $context['classLevel']->id,
        'subject_book_id' => $context['subjectBook']->id,
    ]);
}

function studentGradeBulkPayload(array $context, array $rows): array
{
    return [
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'class_level_id' => $context['classLevel']->id,
        'subject_book_id' => $context['subjectBook']->id,
        'rows' => $rows,
    ];
}

// ── GET /gradable-subjects ───────────────────────────────────────────────

test('gradable subjects lists class and kitab pairs from active schedules of the semester', function () {
    $context = setUpStudentGradeContext();

    $ibtidaClass = ClassLevel::where('school_id', $context['school']->id)->where('slug', 'ibtida_1')->firstOrFail();
    $untemplatedBook = createGradableSubjectBook($context['school'], 'Kitab Tanpa Template', null);
    $secondTeacher = Teacher::factory()->create(['school_id' => $context['school']->id, 'full_name' => 'Ustadz Bakar']);

    // Same pair taught by a second teacher → still one pair, two teachers.
    scheduleSubjectBookForClass($context['school'], $context['academicYear'], 1, $context['classLevel'], $context['subjectBook'], $secondTeacher);
    scheduleSubjectBookForClass($context['school'], $context['academicYear'], 1, $ibtidaClass, $untemplatedBook, $context['teacher']);

    // Not listed: inactive schedule and a schedule of the other semester.
    $inactiveBook = createGradableSubjectBook($context['school'], 'Kitab Jadwal Nonaktif', $context['subjectBook']->grading_template_id);
    scheduleSubjectBookForClass($context['school'], $context['academicYear'], 1, $context['classLevel'], $inactiveBook, $context['teacher'], false);
    $semesterTwoBook = createGradableSubjectBook($context['school'], 'Kitab Semester Dua', $context['subjectBook']->grading_template_id);
    scheduleSubjectBookForClass($context['school'], $context['academicYear'], 2, $context['classLevel'], $semesterTwoBook, $context['teacher']);

    $response = $this->actingAs($context['user'])->getJson('/api/v1/gradable-subjects?'.http_build_query([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
    ]));

    $response->assertOk()->assertJsonPath('success', true)->assertJsonCount(2, 'data');

    $pairs = collect($response->json('data'));

    $tamhidiPair = $pairs->firstWhere('subject_book_id', $context['subjectBook']->id);
    expect($tamhidiPair['class_level_id'])->toBe($context['classLevel']->id);
    expect($tamhidiPair['class_level']['label'])->toBe('Tamhidi');
    expect($tamhidiPair['subject_book']['title'])->toBe('Safinatun Najah');
    expect($tamhidiPair['grading_template']['code'])->toBe('teori_kitab');
    expect($tamhidiPair['is_gradable'])->toBeTrue();
    expect(collect($tamhidiPair['teachers'])->pluck('full_name')->sort()->values()->all())->toBe(['Ustadz Ahmad', 'Ustadz Bakar']);

    $untemplatedPair = $pairs->firstWhere('subject_book_id', $untemplatedBook->id);
    expect($untemplatedPair['grading_template'])->toBeNull();
    expect($untemplatedPair['is_gradable'])->toBeFalse();
});

test('gradable subjects can be filtered by class level', function () {
    $context = setUpStudentGradeContext();

    $ibtidaClass = ClassLevel::where('school_id', $context['school']->id)->where('slug', 'ibtida_1')->firstOrFail();
    $ibtidaBook = createGradableSubjectBook($context['school'], 'Jurumiyah', $context['subjectBook']->grading_template_id);
    scheduleSubjectBookForClass($context['school'], $context['academicYear'], 1, $ibtidaClass, $ibtidaBook, $context['teacher']);

    $response = $this->actingAs($context['user'])->getJson('/api/v1/gradable-subjects?'.http_build_query([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'class_level_id' => $ibtidaClass->id,
    ]));

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.subject_book_id', $ibtidaBook->id);
});

test('gradable subjects requires academic_year_id and semester', function () {
    $context = setUpStudentGradeContext();

    $this->actingAs($context['user'])
        ->getJson('/api/v1/gradable-subjects')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['academic_year_id', 'semester']);
});

test('gradable subjects requires view-grades permission', function () {
    $context = setUpStudentGradeContext();

    $this->actingAs(User::factory()->create())
        ->getJson('/api/v1/gradable-subjects?'.http_build_query([
            'academic_year_id' => $context['academicYear']->id,
            'semester' => 1,
        ]))
        ->assertForbidden();
});

test('gradable subjects rejects another schools academic year and never lists its schedules', function () {
    $context = setUpStudentGradeContext();

    $otherSchool = School::factory()->create();
    $otherAcademicYear = AcademicYear::factory()->create(['school_id' => $otherSchool->id]);
    $otherClassLevel = ClassLevel::factory()->create(['school_id' => $otherSchool->id]);
    $otherBook = createGradableSubjectBook($otherSchool, 'Kitab Sekolah Lain', null);
    $otherTeacher = Teacher::factory()->create(['school_id' => $otherSchool->id]);
    scheduleSubjectBookForClass($otherSchool, $otherAcademicYear, 1, $otherClassLevel, $otherBook, $otherTeacher);

    $this->actingAs($context['user'])
        ->getJson('/api/v1/gradable-subjects?'.http_build_query([
            'academic_year_id' => $otherAcademicYear->id,
            'semester' => 1,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['academic_year_id']);

    // A foreign schedule on the active school's own academic year id is
    // still excluded by the school_id scope.
    scheduleSubjectBookForClass($otherSchool, $context['academicYear'], 1, $otherClassLevel, $otherBook, $otherTeacher);

    $this->actingAs($context['user'])
        ->getJson('/api/v1/gradable-subjects?'.http_build_query([
            'academic_year_id' => $context['academicYear']->id,
            'semester' => 1,
        ]))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

// ── GET /student-grades ──────────────────────────────────────────────────

test('grid lists the class students and the templates manual factors', function () {
    $context = setUpStudentGradeContext();

    $zaid = createStudentThroughEndpointForGrading($this, $context['user'], 'Zaid');
    $ali = createStudentThroughEndpointForGrading($this, $context['user'], 'Ali');
    $withdrawn = createStudentThroughEndpointForGrading($this, $context['user'], 'Bilal Keluar');
    $withdrawn->update(['status' => Student::STATUS_WITHDRAWN]);
    $deleted = createStudentThroughEndpointForGrading($this, $context['user'], 'Hamzah Dihapus');
    $deleted->delete();
    createStudentThroughEndpointForGrading($this, $context['user'], 'Umar Kelas Lain', 'ibtida_1');

    $response = $this->actingAs($context['user'])->getJson(studentGradeGridQuery($context));

    $response->assertOk()->assertJsonPath('success', true);

    $students = $response->json('data.students');
    expect(collect($students)->pluck('full_name')->all())->toBe(['Ali', 'Zaid', 'Bilal Keluar']);
    expect($students[0]['id'])->toBe($ali->id);
    expect($students[0]['is_active_student'])->toBeTrue();
    expect($students[0]['entry_date'])->toBe('2025-07-01');
    expect($students[1]['id'])->toBe($zaid->id);
    expect($students[2]['id'])->toBe($withdrawn->id);
    expect($students[2]['status'])->toBe('withdrawn');
    expect($students[2]['is_active_student'])->toBeFalse();

    $factors = $response->json('data.factors');
    expect(collect($factors)->pluck('code')->all())->toBe(['uts', 'uas', 'keaktifan', 'adab']);
    expect($factors[0])->toMatchArray([
        'grading_factor_id' => $context['factorsByCode']['uts']->id,
        'name' => 'UTS',
        'input_type' => 'manual_once',
        'score_scale' => 'percent',
        'is_midterm_exam' => true,
        'is_active' => true,
    ]);
    expect($factors[0]['weight'])->toEqual(20.0);
    expect($factors[2]['score_scale'])->toBe('level_1_4');
    expect($factors[2]['scale_levels'])->toHaveCount(4);

    expect($response->json('data.grading_template.code'))->toBe('teori_kitab');
    expect($response->json('data.subject_book.title'))->toBe('Safinatun Najah');
    expect($response->json('data.class_level.label'))->toBe('Tamhidi');
    expect($response->json('data.grades'))->toBe([]);
});

test('grid returns existing grades keyed by student and factor code', function () {
    $context = setUpStudentGradeContext();
    $student = createStudentThroughEndpointForGrading($this, $context['user'], 'Ali');

    $this->actingAs($context['user'])
        ->putJson('/api/v1/student-grades/bulk', studentGradeBulkPayload($context, [
            ['student_id' => $student->id, 'scores' => ['uts' => 85.5]],
        ]))
        ->assertOk();

    $response = $this->actingAs($context['user'])->getJson(studentGradeGridQuery($context));

    $response->assertOk();
    $grade = $response->json("data.grades.{$student->id}.uts");
    expect($grade['score'])->toEqual(85.5);
    expect($grade['student_id'])->toBe($student->id);
    expect($grade['grading_factor_id'])->toBe($context['factorsByCode']['uts']->id);
    expect($grade['updated_by'])->toBe($context['user']->id);
    expect($grade['updated_by_name'])->toBe('Ustadz Penilai');
    expect($grade['updated_at'])->not->toBeNull();
    expect($response->json("data.grades.{$student->id}.uas"))->toBeNull();
});

test('grid requires all query params', function () {
    $context = setUpStudentGradeContext();

    $this->actingAs($context['user'])
        ->getJson('/api/v1/student-grades')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['academic_year_id', 'semester', 'class_level_id', 'subject_book_id']);
});

test('grid rejects a kitab that is not scheduled for the class in that semester', function () {
    $context = setUpStudentGradeContext();
    $ibtidaClass = ClassLevel::where('school_id', $context['school']->id)->where('slug', 'ibtida_1')->firstOrFail();

    $this->actingAs($context['user'])
        ->getJson('/api/v1/student-grades?'.http_build_query([
            'academic_year_id' => $context['academicYear']->id,
            'semester' => 1,
            'class_level_id' => $ibtidaClass->id,
            'subject_book_id' => $context['subjectBook']->id,
        ]))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Kitab ini tidak dijadwalkan untuk kelas tersebut pada semester ini.');
});

test('grid requires view-grades permission', function () {
    $context = setUpStudentGradeContext();

    $this->actingAs(User::factory()->create())
        ->getJson(studentGradeGridQuery($context))
        ->assertForbidden();
});

test('grid rejects another schools class level and kitab', function () {
    $context = setUpStudentGradeContext();

    $otherSchool = School::factory()->create();
    $otherClassLevel = ClassLevel::factory()->create(['school_id' => $otherSchool->id]);
    $otherBook = createGradableSubjectBook($otherSchool, 'Kitab Sekolah Lain', null);

    $this->actingAs($context['user'])
        ->getJson('/api/v1/student-grades?'.http_build_query([
            'academic_year_id' => $context['academicYear']->id,
            'semester' => 1,
            'class_level_id' => $otherClassLevel->id,
            'subject_book_id' => $otherBook->id,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['class_level_id', 'subject_book_id']);
});

// ── PUT /student-grades/bulk ─────────────────────────────────────────────

test('bulk upsert creates grades and a resubmit updates them without duplicates', function () {
    $context = setUpStudentGradeContext();
    $ali = createStudentThroughEndpointForGrading($this, $context['user'], 'Ali');
    $zaid = createStudentThroughEndpointForGrading($this, $context['user'], 'Zaid');

    $payload = studentGradeBulkPayload($context, [
        ['student_id' => $ali->id, 'scores' => ['uts' => 80, 'uas' => 90]],
        ['student_id' => $zaid->id, 'scores' => ['uts' => 70, 'uas' => 75.25]],
    ]);

    $this->actingAs($context['user'])->putJson('/api/v1/student-grades/bulk', $payload)
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(4, 'data');

    expect(StudentGrade::count())->toBe(4);

    $payload['rows'][0]['scores']['uts'] = 88;
    $this->actingAs($context['user'])->putJson('/api/v1/student-grades/bulk', $payload)->assertOk();

    expect(StudentGrade::count())->toBe(4);

    $aliUts = StudentGrade::where('student_id', $ali->id)->where('grading_factor_id', $context['factorsByCode']['uts']->id)->firstOrFail();
    expect((float) $aliUts->score)->toBe(88.0);
    expect($aliUts->school_id)->toBe($context['school']->id);
    expect($aliUts->class_level_id)->toBe($context['classLevel']->id);
    expect($aliUts->academic_year_id)->toBe($context['academicYear']->id);
    expect($aliUts->semester)->toBe(1);
    expect($aliUts->created_by)->toBe($context['user']->id);
    expect($aliUts->updated_by)->toBe($context['user']->id);
    expect($aliUts->scale_level)->toBeNull();
});

test('bulk upsert keeps NULL as NULL and 0 as 0', function () {
    $context = setUpStudentGradeContext();
    $student = createStudentThroughEndpointForGrading($this, $context['user'], 'Ali');
    $utsFactorId = $context['factorsByCode']['uts']->id;
    $uasFactorId = $context['factorsByCode']['uas']->id;

    $this->actingAs($context['user'])
        ->putJson('/api/v1/student-grades/bulk', studentGradeBulkPayload($context, [
            ['student_id' => $student->id, 'scores' => ['uts' => 0, 'uas' => null]],
        ]))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $uts = StudentGrade::where('student_id', $student->id)->where('grading_factor_id', $utsFactorId)->firstOrFail();
    expect($uts->score)->not->toBeNull();
    expect((float) $uts->score)->toBe(0.0);
    // A null for a factor without an existing row creates no empty row.
    expect(StudentGrade::where('grading_factor_id', $uasFactorId)->exists())->toBeFalse();

    // Clearing an existing score keeps the row with score NULL.
    $clearer = User::factory()->create(['name' => 'Pengurus Lain']);
    $clearer->assignRole('super_admin');

    $response = $this->actingAs($clearer)
        ->putJson('/api/v1/student-grades/bulk', studentGradeBulkPayload($context, [
            ['student_id' => $student->id, 'scores' => ['uts' => null]],
        ]))
        ->assertOk();

    $uts->refresh();
    expect($uts->score)->toBeNull();
    expect($uts->updated_by)->toBe($clearer->id);
    expect($uts->created_by)->toBe($context['user']->id);
    expect($response->json('data.0.score'))->toBeNull();
    expect($response->json('data.0.updated_by_name'))->toBe('Pengurus Lain');
});

test('bulk upsert leaves factors absent from scores untouched', function () {
    $context = setUpStudentGradeContext();
    $student = createStudentThroughEndpointForGrading($this, $context['user'], 'Ali');

    $this->actingAs($context['user'])
        ->putJson('/api/v1/student-grades/bulk', studentGradeBulkPayload($context, [
            ['student_id' => $student->id, 'scores' => ['uts' => 60, 'uas' => 80]],
        ]))
        ->assertOk();

    $this->actingAs($context['user'])
        ->putJson('/api/v1/student-grades/bulk', studentGradeBulkPayload($context, [
            ['student_id' => $student->id, 'scores' => ['uts' => 65]],
        ]))
        ->assertOk();

    $uas = StudentGrade::where('student_id', $student->id)->where('grading_factor_id', $context['factorsByCode']['uas']->id)->firstOrFail();
    expect((float) $uas->score)->toBe(80.0);
});

test('bulk upsert response rows carry updated_at and updated_by', function () {
    $context = setUpStudentGradeContext();
    $student = createStudentThroughEndpointForGrading($this, $context['user'], 'Ali');

    $response = $this->actingAs($context['user'])
        ->putJson('/api/v1/student-grades/bulk', studentGradeBulkPayload($context, [
            ['student_id' => $student->id, 'scores' => ['uts' => 77.75]],
        ]))
        ->assertOk();

    $row = $response->json('data.0');
    expect($row)->toHaveKeys(['id', 'student_id', 'grading_factor_id', 'code', 'score', 'scale_level', 'updated_at', 'updated_by', 'updated_by_name']);
    expect($row['student_id'])->toBe($student->id);
    expect($row['code'])->toBe('uts');
    expect($row['score'])->toEqual(77.75);
    expect($row['updated_by'])->toBe($context['user']->id);
    expect($row['updated_by_name'])->toBe('Ustadz Penilai');
    expect($row['updated_at'])->not->toBeNull();
});

test('bulk upsert rejects a kitab without a grading template', function () {
    $context = setUpStudentGradeContext();
    $student = createStudentThroughEndpointForGrading($this, $context['user'], 'Ali');

    $untemplatedBook = createGradableSubjectBook($context['school'], 'Kitab Tanpa Template', null);
    scheduleSubjectBookForClass($context['school'], $context['academicYear'], 1, $context['classLevel'], $untemplatedBook, $context['teacher']);

    $payload = studentGradeBulkPayload($context, [['student_id' => $student->id, 'scores' => ['uts' => 80]]]);
    $payload['subject_book_id'] = $untemplatedBook->id;

    $this->actingAs($context['user'])
        ->putJson('/api/v1/student-grades/bulk', $payload)
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Kitab ini belum memiliki template penilaian.');

    expect(StudentGrade::count())->toBe(0);
});

test('bulk upsert rejects a class and kitab pair that is not in the schedule', function () {
    $context = setUpStudentGradeContext();
    $student = createStudentThroughEndpointForGrading($this, $context['user'], 'Ali');

    $unscheduledBook = createGradableSubjectBook($context['school'], 'Kitab Tidak Terjadwal', $context['subjectBook']->grading_template_id);

    $payload = studentGradeBulkPayload($context, [['student_id' => $student->id, 'scores' => ['uts' => 80]]]);
    $payload['subject_book_id'] = $unscheduledBook->id;

    $this->actingAs($context['user'])
        ->putJson('/api/v1/student-grades/bulk', $payload)
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Kitab ini tidak dijadwalkan untuk kelas tersebut pada semester ini.');

    // Semester 2 has no schedule for the pair either.
    $semesterTwoPayload = studentGradeBulkPayload($context, [['student_id' => $student->id, 'scores' => ['uts' => 80]]]);
    $semesterTwoPayload['semester'] = 2;

    $this->actingAs($context['user'])
        ->putJson('/api/v1/student-grades/bulk', $semesterTwoPayload)
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Kitab ini tidak dijadwalkan untuk kelas tersebut pada semester ini.');

    expect(StudentGrade::count())->toBe(0);
});

test('bulk upsert rejects a semester without academic semester configuration', function () {
    $context = setUpStudentGradeContext();
    $student = createStudentThroughEndpointForGrading($this, $context['user'], 'Ali');

    // An academic year created without its academic_semesters rows.
    $unconfiguredYear = AcademicYear::factory()->create([
        'school_id' => $context['school']->id,
        'name' => '2024/2025',
        'start_date' => '2024-07-01',
        'end_date' => '2025-06-30',
    ]);
    scheduleSubjectBookForClass($context['school'], $unconfiguredYear, 1, $context['classLevel'], $context['subjectBook'], $context['teacher']);

    $payload = studentGradeBulkPayload($context, [['student_id' => $student->id, 'scores' => ['uts' => 80]]]);
    $payload['academic_year_id'] = $unconfiguredYear->id;

    $this->actingAs($context['user'])
        ->putJson('/api/v1/student-grades/bulk', $payload)
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Semester akademik belum dikonfigurasi.');

    $this->actingAs($context['user'])
        ->getJson('/api/v1/student-grades?'.http_build_query([
            'academic_year_id' => $unconfiguredYear->id,
            'semester' => 1,
            'class_level_id' => $context['classLevel']->id,
            'subject_book_id' => $context['subjectBook']->id,
        ]))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Semester akademik belum dikonfigurasi.');
});

test('bulk upsert returns per-student and per-factor error keys and saves nothing', function () {
    $context = setUpStudentGradeContext();
    $ali = createStudentThroughEndpointForGrading($this, $context['user'], 'Ali');
    $zaid = createStudentThroughEndpointForGrading($this, $context['user'], 'Zaid');
    $umar = createStudentThroughEndpointForGrading($this, $context['user'], 'Umar Kelas Lain', 'ibtida_1');

    $response = $this->actingAs($context['user'])
        ->putJson('/api/v1/student-grades/bulk', studentGradeBulkPayload($context, [
            ['student_id' => $ali->id, 'scores' => ['uts' => 101, 'uas' => 90]],
            ['student_id' => $zaid->id, 'scores' => ['uts' => -1, 'uas' => 80.125, 'tugas' => 80, 'adab' => 85, 'unknown_code' => 50]],
            ['student_id' => $umar->id, 'scores' => ['uts' => 90]],
        ]))
        ->assertUnprocessable();

    $errors = $response->json('errors');
    expect(array_keys($errors))->toEqualCanonicalizing([
        "{$ali->id}.uts",
        "{$zaid->id}.uts",
        "{$zaid->id}.uas",
        "{$zaid->id}.tugas",
        "{$zaid->id}.adab",
        "{$zaid->id}.unknown_code",
        $umar->id,
    ]);
    expect($errors["{$zaid->id}.adab"][0])->toBe('Level harus bilangan bulat 1–4.');
    expect($errors[$umar->id][0])->toBe('Santri tidak terdaftar di kelas ini.');

    // All-or-nothing: Ali's valid UAS was not saved either.
    expect(StudentGrade::count())->toBe(0);
});

// ── level_1_4 handling (Adab, Keaktifan) ─────────────────────────────────

test('bulk upsert stores a level_1_4 factors level and converts it to a score via scale_levels', function () {
    $context = setUpStudentGradeContext();
    $ali = createStudentThroughEndpointForGrading($this, $context['user'], 'Ali');

    $response = $this->actingAs($context['user'])
        ->putJson('/api/v1/student-grades/bulk', studentGradeBulkPayload($context, [
            ['student_id' => $ali->id, 'scores' => ['adab' => 3, 'keaktifan' => 4]],
        ]))
        ->assertOk();

    $rows = collect($response->json('data'))->keyBy('code');
    expect($rows['adab']['scale_level'])->toBe(3);
    expect($rows['adab']['score'])->toEqual(85.0);
    expect($rows['keaktifan']['scale_level'])->toBe(4);
    expect($rows['keaktifan']['score'])->toEqual(100.0);

    $adabGrade = StudentGrade::where('student_id', $ali->id)->where('grading_factor_id', $context['factorsByCode']['adab']->id)->firstOrFail();
    expect($adabGrade->scale_level)->toBe(3);
    expect((float) $adabGrade->score)->toBe(85.0);
});

test('bulk upsert clears both score and scale_level when a level_1_4 factor is set to null', function () {
    $context = setUpStudentGradeContext();
    $ali = createStudentThroughEndpointForGrading($this, $context['user'], 'Ali');

    $this->actingAs($context['user'])->putJson('/api/v1/student-grades/bulk', studentGradeBulkPayload($context, [
        ['student_id' => $ali->id, 'scores' => ['adab' => 2]],
    ]))->assertOk();

    $this->actingAs($context['user'])->putJson('/api/v1/student-grades/bulk', studentGradeBulkPayload($context, [
        ['student_id' => $ali->id, 'scores' => ['adab' => null]],
    ]))->assertOk();

    $adabGrade = StudentGrade::where('student_id', $ali->id)->where('grading_factor_id', $context['factorsByCode']['adab']->id)->firstOrFail();
    expect($adabGrade->score)->toBeNull();
    expect($adabGrade->scale_level)->toBeNull();
});

test('bulk upsert rejects an out-of-range or non-integer level for a level_1_4 factor', function () {
    $context = setUpStudentGradeContext();
    $ali = createStudentThroughEndpointForGrading($this, $context['user'], 'Ali');

    $response = $this->actingAs($context['user'])
        ->putJson('/api/v1/student-grades/bulk', studentGradeBulkPayload($context, [
            ['student_id' => $ali->id, 'scores' => ['adab' => 5, 'keaktifan' => 2.5]],
        ]))
        ->assertUnprocessable();

    $errors = $response->json('errors');
    expect($errors["{$ali->id}.adab"][0])->toBe('Level harus bilangan bulat 1–4.');
    expect($errors["{$ali->id}.keaktifan"][0])->toBe('Level harus bilangan bulat 1–4.');
    expect(StudentGrade::count())->toBe(0);
});

test('bulk upsert rejects a level_1_4 level sent as a string', function () {
    $context = setUpStudentGradeContext();
    $ali = createStudentThroughEndpointForGrading($this, $context['user'], 'Ali');

    $response = $this->actingAs($context['user'])
        ->putJson('/api/v1/student-grades/bulk', studentGradeBulkPayload($context, [
            ['student_id' => $ali->id, 'scores' => ['adab' => '3']],
        ]))
        ->assertUnprocessable();

    expect($response->json('errors')["{$ali->id}.adab"][0])->toBe('Level harus bilangan bulat 1–4.');
    expect(StudentGrade::count())->toBe(0);
});

test('grid returns the scale_level and converted score of a saved level_1_4 grade', function () {
    $context = setUpStudentGradeContext();
    $ali = createStudentThroughEndpointForGrading($this, $context['user'], 'Ali');

    $this->actingAs($context['user'])->putJson('/api/v1/student-grades/bulk', studentGradeBulkPayload($context, [
        ['student_id' => $ali->id, 'scores' => ['adab' => 1]],
    ]))->assertOk();

    $response = $this->actingAs($context['user'])->getJson(studentGradeGridQuery($context));
    $grade = $response->json("data.grades.{$ali->id}.adab");

    expect($grade['scale_level'])->toBe(1);
    expect($grade['score'])->toEqual(60.0);
});

test('editing a factors scale_levels changes conversion for new saves but old rows keep their stored score', function () {
    $context = setUpStudentGradeContext();
    $ali = createStudentThroughEndpointForGrading($this, $context['user'], 'Ali');
    $zaid = createStudentThroughEndpointForGrading($this, $context['user'], 'Zaid');
    $adabFactor = $context['factorsByCode']['adab'];

    $this->actingAs($context['user'])->putJson('/api/v1/student-grades/bulk', studentGradeBulkPayload($context, [
        ['student_id' => $ali->id, 'scores' => ['adab' => 3]],
    ]))->assertOk();

    $newScaleLevels = collect($adabFactor->scale_levels)
        ->map(function (array $levelDefinition) {
            if ($levelDefinition['level'] === 3) {
                $levelDefinition['score'] = 92;
            }

            return $levelDefinition;
        })
        ->all();

    $this->actingAs($context['user'])
        ->putJson("/api/v1/grading-factors/{$adabFactor->id}", ['scale_levels' => $newScaleLevels])
        ->assertOk();

    $this->actingAs($context['user'])->putJson('/api/v1/student-grades/bulk', studentGradeBulkPayload($context, [
        ['student_id' => $zaid->id, 'scores' => ['adab' => 3]],
    ]))->assertOk();

    $zaidGrade = StudentGrade::where('student_id', $zaid->id)->where('grading_factor_id', $adabFactor->id)->firstOrFail();
    expect((float) $zaidGrade->score)->toBe(92.0);

    $aliGrade = StudentGrade::where('student_id', $ali->id)->where('grading_factor_id', $adabFactor->id)->firstOrFail();
    expect((float) $aliGrade->score)->toBe(85.0);
});

test('bulk upsert rejects a student listed twice', function () {
    $context = setUpStudentGradeContext();
    $student = createStudentThroughEndpointForGrading($this, $context['user'], 'Ali');

    $response = $this->actingAs($context['user'])
        ->putJson('/api/v1/student-grades/bulk', studentGradeBulkPayload($context, [
            ['student_id' => $student->id, 'scores' => ['uts' => 80]],
            ['student_id' => $student->id, 'scores' => ['uts' => 90]],
        ]))
        ->assertUnprocessable();

    expect(array_keys($response->json('errors')))->toBe([$student->id]);
    expect(StudentGrade::count())->toBe(0);
});

test('bulk upsert validates the request shape', function () {
    $context = setUpStudentGradeContext();

    $this->actingAs($context['user'])
        ->putJson('/api/v1/student-grades/bulk', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['academic_year_id', 'semester', 'class_level_id', 'subject_book_id', 'rows']);
});

test('bulk upsert requires manage-grades permission', function () {
    $context = setUpStudentGradeContext();
    $student = createStudentThroughEndpointForGrading($this, $context['user'], 'Ali');

    $viewer = User::factory()->create();
    $viewer->givePermissionTo('view-grades');

    $this->actingAs($viewer)
        ->putJson('/api/v1/student-grades/bulk', studentGradeBulkPayload($context, [
            ['student_id' => $student->id, 'scores' => ['uts' => 80]],
        ]))
        ->assertForbidden();

    expect(StudentGrade::count())->toBe(0);
});

test('pengurus_pesantren can view and save grades', function () {
    $context = setUpStudentGradeContext();
    $student = createStudentThroughEndpointForGrading($this, $context['user'], 'Ali');

    $pengurus = User::factory()->create();
    $pengurus->assignRole('pengurus_pesantren');

    $this->actingAs($pengurus)->getJson(studentGradeGridQuery($context))->assertOk();
    $this->actingAs($pengurus)
        ->putJson('/api/v1/student-grades/bulk', studentGradeBulkPayload($context, [
            ['student_id' => $student->id, 'scores' => ['uts' => 80]],
        ]))
        ->assertOk();
});

test('bulk upsert rejects a student of another school', function () {
    $context = setUpStudentGradeContext();
    $otherSchool = School::factory()->create();
    $otherStudent = Student::factory()->create([
        'school_id' => $otherSchool->id,
        'class_level_id' => $context['classLevel']->id,
    ]);

    $response = $this->actingAs($context['user'])
        ->putJson('/api/v1/student-grades/bulk', studentGradeBulkPayload($context, [
            ['student_id' => $otherStudent->id, 'scores' => ['uts' => 80]],
        ]))
        ->assertUnprocessable();

    expect(array_keys($response->json('errors')))->toBe([$otherStudent->id]);
    expect(StudentGrade::count())->toBe(0);
});

test('bulk upsert rejects another schools kitab', function () {
    $context = setUpStudentGradeContext();
    $student = createStudentThroughEndpointForGrading($this, $context['user'], 'Ali');

    $otherSchool = School::factory()->create();
    $otherBook = createGradableSubjectBook($otherSchool, 'Kitab Sekolah Lain', null);

    $payload = studentGradeBulkPayload($context, [['student_id' => $student->id, 'scores' => ['uts' => 80]]]);
    $payload['subject_book_id'] = $otherBook->id;

    $this->actingAs($context['user'])
        ->putJson('/api/v1/student-grades/bulk', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['subject_book_id']);
});

// ── Grading settings has_grades (R1) ─────────────────────────────────────

test('semester weights report has_grades once a template kitab has a recorded grade', function () {
    $context = setUpStudentGradeContext();
    $student = createStudentThroughEndpointForGrading($this, $context['user'], 'Ali');

    $weightsQuery = '/api/v1/grading-template-factors?'.http_build_query([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
    ]);

    $before = collect($this->actingAs($context['user'])->getJson($weightsQuery)->json('data'));
    expect($before->firstWhere('code', 'teori_kitab')['has_grades'])->toBeFalse();

    $this->actingAs($context['user'])
        ->putJson('/api/v1/student-grades/bulk', studentGradeBulkPayload($context, [
            ['student_id' => $student->id, 'scores' => ['uts' => 80]],
        ]))
        ->assertOk();

    $after = collect($this->actingAs($context['user'])->getJson($weightsQuery)->json('data'));
    expect($after->firstWhere('code', 'teori_kitab')['has_grades'])->toBeTrue();
    expect($after->firstWhere('code', 'tahfizh')['has_grades'])->toBeFalse();

    $semesterTwo = collect($this->actingAs($context['user'])->getJson('/api/v1/grading-template-factors?'.http_build_query([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 2,
    ]))->json('data'));
    expect($semesterTwo->firstWhere('code', 'teori_kitab')['has_grades'])->toBeFalse();
});

test('a kitab with recorded grades cannot be deleted', function () {
    $context = setUpStudentGradeContext();
    $student = createStudentThroughEndpointForGrading($this, $context['user'], 'Ali');

    $this->actingAs($context['user'])
        ->putJson('/api/v1/student-grades/bulk', studentGradeBulkPayload($context, [
            ['student_id' => $student->id, 'scores' => ['uts' => 80]],
        ]))
        ->assertOk();

    // Without schedules the only remaining dependent is the grade.
    TeachingSchedule::where('subject_book_id', $context['subjectBook']->id)->delete();

    $this->actingAs($context['user'])
        ->deleteJson("/api/v1/subject-books/{$context['subjectBook']->id}")
        ->assertUnprocessable();

    expect(SubjectBook::find($context['subjectBook']->id))->not->toBeNull();
});
