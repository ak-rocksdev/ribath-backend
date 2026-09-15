<?php

use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\School;
use App\Models\SubjectBook;
use App\Models\SubjectCategory;
use App\Models\Teacher;
use App\Models\TeachingSchedule;
use App\Models\TeachingScheduleTeacherHistory;
use App\Models\TimeSlot;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SchoolSeeder;

/*
 * Riwayat pengajar (role-ustadz ticket 03, ADR 0005): the Jadwal Mengajar
 * service records the Ustadz, Kelas and Kitab a schedule had before each
 * change of any of them — through the edit (PUT /teaching-schedules/{id})
 * and the bulk "ganti ustadz" (POST /teaching-schedules/replace-teacher) —
 * and nothing for a day/time-only edit, a new schedule, "Hapus" or a clone.
 * Every schedule and every change goes through the real endpoints.
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Pengurus manages the Jadwal Mengajar of semester 1; Ustadz Ahmad teaches
 * Safinatun Najah to Tamhidi on Monday.
 *
 * @return array<string, mixed>
 */
function setUpTeacherHistoryContext($testCase): array
{
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();

    $school = School::where('is_active', true)->firstOrFail();

    $pengurus = User::factory()->create(['school_id' => $school->id, 'name' => 'Pengurus Jadwal']);
    $pengurus->assignRole('pengurus_pesantren');

    $subjectCategory = SubjectCategory::factory()->create(['school_id' => $school->id]);

    $context = [
        'school' => $school,
        'pengurus' => $pengurus,
        'academicYear' => AcademicYear::factory()->create(['school_id' => $school->id, 'is_active' => true]),
        'morningSlot' => TimeSlot::factory()->create(['school_id' => $school->id, 'sort_order' => 1]),
        'afternoonSlot' => TimeSlot::factory()->create(['school_id' => $school->id, 'sort_order' => 2]),
        'tamhidi' => ClassLevel::factory()->create(['school_id' => $school->id, 'slug' => 'tamhidi', 'label' => 'Tamhidi']),
        'ibtida' => ClassLevel::factory()->create(['school_id' => $school->id, 'slug' => 'ibtida_1', 'label' => 'Ibtida 1']),
        'safinah' => SubjectBook::factory()->create(['school_id' => $school->id, 'subject_category_id' => $subjectCategory->id, 'title' => 'Safinatun Najah']),
        'jurumiyah' => SubjectBook::factory()->create(['school_id' => $school->id, 'subject_category_id' => $subjectCategory->id, 'title' => 'Jurumiyah']),
        'ustadzAhmad' => Teacher::factory()->create(['school_id' => $school->id, 'full_name' => 'Ustadz Ahmad', 'user_id' => null]),
        'ustadzBakar' => Teacher::factory()->create(['school_id' => $school->id, 'full_name' => 'Ustadz Bakar', 'user_id' => null]),
    ];

    $context['scheduleId'] = createTeacherHistorySchedule($testCase, $context, [
        'class_level_id' => $context['tamhidi']->id,
        'subject_book_id' => $context['safinah']->id,
        'teacher_id' => $context['ustadzAhmad']->id,
    ]);

    return $context;
}

/**
 * Creates a Jadwal Mengajar through POST /teaching-schedules (semester 1,
 * Monday morning unless overridden); returns its id.
 *
 * @param  array<string, mixed>  $attributes
 */
function createTeacherHistorySchedule($testCase, array $context, array $attributes): string
{
    return $testCase->actingAs($context['pengurus'])
        ->postJson('/api/v1/teaching-schedules', array_merge([
            'academic_year_id' => $context['academicYear']->id,
            'semester' => 1,
            'day_of_week' => 'monday',
            'time_slot_id' => $context['morningSlot']->id,
        ], $attributes))
        ->assertCreated()
        ->json('data.id');
}

/**
 * The payload of the "Ubah Jadwal" form: it always sends every field, the
 * unchanged ones included.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function teacherHistoryEditFormPayload(string $scheduleId, array $changes): array
{
    $schedule = TeachingSchedule::findOrFail($scheduleId);

    return array_merge($schedule->only([
        'academic_year_id', 'semester', 'day_of_week', 'time_slot_id',
        'class_level_id', 'subject_book_id', 'teacher_id',
    ]), $changes);
}

// ── Edit jadwal ──────────────────────────────────────────────────────────

test('changing the Ustadz through the schedule edit records the previous Ustadz, Kelas and Kitab', function () {
    Carbon::setTestNow('2025-09-10 08:30:00');
    $context = setUpTeacherHistoryContext($this);

    $this->actingAs($context['pengurus'])
        ->putJson("/api/v1/teaching-schedules/{$context['scheduleId']}", teacherHistoryEditFormPayload($context['scheduleId'], [
            'teacher_id' => $context['ustadzBakar']->id,
        ]))
        ->assertOk()
        ->assertJsonPath('data.teacher.id', $context['ustadzBakar']->id);

    $historyEntries = TeachingScheduleTeacherHistory::all();
    expect($historyEntries)->toHaveCount(1);

    $historyEntry = $historyEntries->first();
    expect($historyEntry->school_id)->toBe($context['school']->id)
        ->and($historyEntry->teaching_schedule_id)->toBe($context['scheduleId'])
        ->and($historyEntry->academic_year_id)->toBe($context['academicYear']->id)
        ->and($historyEntry->semester)->toBe(1)
        ->and($historyEntry->previous_teacher_id)->toBe($context['ustadzAhmad']->id)
        ->and($historyEntry->previous_class_level_id)->toBe($context['tamhidi']->id)
        ->and($historyEntry->previous_subject_book_id)->toBe($context['safinah']->id)
        ->and($historyEntry->changed_by)->toBe($context['pengurus']->id)
        ->and($historyEntry->changed_at->toDateTimeString())->toBe('2025-09-10 08:30:00');
});

test('changing the Kelas or the Kitab records the values the schedule had before each change', function () {
    $context = setUpTeacherHistoryContext($this);
    $scheduleUrl = "/api/v1/teaching-schedules/{$context['scheduleId']}";

    $this->actingAs($context['pengurus'])
        ->putJson($scheduleUrl, teacherHistoryEditFormPayload($context['scheduleId'], ['class_level_id' => $context['ibtida']->id]))
        ->assertOk();
    $this->actingAs($context['pengurus'])
        ->putJson($scheduleUrl, teacherHistoryEditFormPayload($context['scheduleId'], ['subject_book_id' => $context['jurumiyah']->id]))
        ->assertOk();

    $previousAssignments = TeachingScheduleTeacherHistory::orderBy('changed_at')->orderBy('id')->get()
        ->map(fn (TeachingScheduleTeacherHistory $historyEntry) => [
            $historyEntry->previous_teacher_id,
            $historyEntry->previous_class_level_id,
            $historyEntry->previous_subject_book_id,
        ])
        ->all();

    expect($previousAssignments)->toHaveCount(2)
        ->toContain([$context['ustadzAhmad']->id, $context['tamhidi']->id, $context['safinah']->id])
        ->toContain([$context['ustadzAhmad']->id, $context['ibtida']->id, $context['safinah']->id]);
});

test('one edit that changes the Ustadz, Kelas and Kitab together records one row', function () {
    $context = setUpTeacherHistoryContext($this);

    $this->actingAs($context['pengurus'])
        ->putJson("/api/v1/teaching-schedules/{$context['scheduleId']}", [
            'teacher_id' => $context['ustadzBakar']->id,
            'class_level_id' => $context['ibtida']->id,
            'subject_book_id' => $context['jurumiyah']->id,
        ])
        ->assertOk();

    expect(TeachingScheduleTeacherHistory::count())->toBe(1);
    $this->assertDatabaseHas('teaching_schedule_teacher_histories', [
        'teaching_schedule_id' => $context['scheduleId'],
        'previous_teacher_id' => $context['ustadzAhmad']->id,
        'previous_class_level_id' => $context['tamhidi']->id,
        'previous_subject_book_id' => $context['safinah']->id,
    ]);
});

test('moving a schedule to another day or time slot records nothing', function () {
    $context = setUpTeacherHistoryContext($this);
    $scheduleUrl = "/api/v1/teaching-schedules/{$context['scheduleId']}";

    // The edit form re-sends the unchanged Ustadz, Kelas and Kitab.
    $this->actingAs($context['pengurus'])
        ->putJson($scheduleUrl, teacherHistoryEditFormPayload($context['scheduleId'], [
            'day_of_week' => 'wednesday',
            'time_slot_id' => $context['afternoonSlot']->id,
        ]))
        ->assertOk();

    // Drag and drop on the weekly grid sends only the new day and slot.
    $this->actingAs($context['pengurus'])
        ->putJson($scheduleUrl, ['day_of_week' => 'thursday', 'time_slot_id' => $context['morningSlot']->id])
        ->assertOk()
        ->assertJsonPath('data.day_of_week', 'thursday');

    expect(TeachingScheduleTeacherHistory::count())->toBe(0);
});

// ── Ganti ustadz massal ──────────────────────────────────────────────────

test('the bulk ganti ustadz records one row for every schedule it moves to the new Ustadz', function () {
    $context = setUpTeacherHistoryContext($this);

    $ahmadIbtidaScheduleId = createTeacherHistorySchedule($this, $context, [
        'day_of_week' => 'tuesday',
        'class_level_id' => $context['ibtida']->id,
        'subject_book_id' => $context['jurumiyah']->id,
        'teacher_id' => $context['ustadzAhmad']->id,
    ]);
    // Outside the chosen semester: left alone, nothing recorded.
    $ahmadSemesterTwoScheduleId = createTeacherHistorySchedule($this, $context, [
        'semester' => 2,
        'class_level_id' => $context['tamhidi']->id,
        'subject_book_id' => $context['safinah']->id,
        'teacher_id' => $context['ustadzAhmad']->id,
    ]);

    $this->actingAs($context['pengurus'])
        ->postJson('/api/v1/teaching-schedules/replace-teacher', [
            'source_teacher_id' => $context['ustadzAhmad']->id,
            'target_teacher_id' => $context['ustadzBakar']->id,
            'academic_year_id' => $context['academicYear']->id,
            'semester' => 1,
        ])
        ->assertOk()
        ->assertJsonPath('data.updated', 2);

    $historyByScheduleId = TeachingScheduleTeacherHistory::all()->keyBy('teaching_schedule_id');
    expect($historyByScheduleId->keys()->sort()->values()->all())
        ->toBe(collect([$context['scheduleId'], $ahmadIbtidaScheduleId])->sort()->values()->all());

    expect($historyByScheduleId[$context['scheduleId']]->only(['previous_teacher_id', 'previous_class_level_id', 'previous_subject_book_id', 'semester', 'changed_by']))
        ->toBe([
            'previous_teacher_id' => $context['ustadzAhmad']->id,
            'previous_class_level_id' => $context['tamhidi']->id,
            'previous_subject_book_id' => $context['safinah']->id,
            'semester' => 1,
            'changed_by' => $context['pengurus']->id,
        ]);
    expect($historyByScheduleId[$ahmadIbtidaScheduleId]->only(['previous_teacher_id', 'previous_class_level_id', 'previous_subject_book_id']))
        ->toBe([
            'previous_teacher_id' => $context['ustadzAhmad']->id,
            'previous_class_level_id' => $context['ibtida']->id,
            'previous_subject_book_id' => $context['jurumiyah']->id,
        ]);
    expect(TeachingSchedule::findOrFail($ahmadSemesterTwoScheduleId)->teacher_id)->toBe($context['ustadzAhmad']->id);
});

test('a schedule the bulk ganti ustadz skips for a conflict records nothing', function () {
    $context = setUpTeacherHistoryContext($this);

    // Ustadz Bakar already teaches another class on Monday morning, so
    // Ahmad's Monday lesson cannot move to him; the Tuesday one can.
    createTeacherHistorySchedule($this, $context, [
        'class_level_id' => $context['ibtida']->id,
        'subject_book_id' => $context['jurumiyah']->id,
        'teacher_id' => $context['ustadzBakar']->id,
    ]);
    $ahmadTuesdayScheduleId = createTeacherHistorySchedule($this, $context, [
        'day_of_week' => 'tuesday',
        'class_level_id' => $context['tamhidi']->id,
        'subject_book_id' => $context['jurumiyah']->id,
        'teacher_id' => $context['ustadzAhmad']->id,
    ]);

    $this->actingAs($context['pengurus'])
        ->postJson('/api/v1/teaching-schedules/replace-teacher', [
            'source_teacher_id' => $context['ustadzAhmad']->id,
            'target_teacher_id' => $context['ustadzBakar']->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.updated', 1)
        ->assertJsonPath('data.conflicts.0.schedule_id', $context['scheduleId']);

    expect(TeachingScheduleTeacherHistory::pluck('teaching_schedule_id')->all())->toBe([$ahmadTuesdayScheduleId]);
});

// ── Perubahan lain yang tidak dicatat ────────────────────────────────────

test('creating, deleting and cloning schedules records nothing', function () {
    $context = setUpTeacherHistoryContext($this);

    createTeacherHistorySchedule($this, $context, [
        'day_of_week' => 'tuesday',
        'class_level_id' => $context['ibtida']->id,
        'subject_book_id' => $context['jurumiyah']->id,
        'teacher_id' => $context['ustadzBakar']->id,
    ]);

    $this->actingAs($context['pengurus'])
        ->postJson('/api/v1/teaching-schedules/clone-semester', [
            'source_academic_year_id' => $context['academicYear']->id,
            'source_semester' => 1,
            'target_academic_year_id' => $context['academicYear']->id,
            'target_semester' => 2,
        ])
        ->assertCreated()
        ->assertJsonPath('data.created', 2);

    $this->actingAs($context['pengurus'])
        ->deleteJson("/api/v1/teaching-schedules/{$context['scheduleId']}")
        ->assertOk();

    expect(TeachingScheduleTeacherHistory::count())->toBe(0);
});

test('a rejected change records nothing', function () {
    $context = setUpTeacherHistoryContext($this);
    $scheduleUrl = "/api/v1/teaching-schedules/{$context['scheduleId']}";

    // Ustadz Bakar is busy on Monday morning: the edit is refused.
    createTeacherHistorySchedule($this, $context, [
        'class_level_id' => $context['ibtida']->id,
        'subject_book_id' => $context['jurumiyah']->id,
        'teacher_id' => $context['ustadzBakar']->id,
    ]);
    $this->actingAs($context['pengurus'])
        ->putJson($scheduleUrl, ['teacher_id' => $context['ustadzBakar']->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['teacher_id']);

    // Validation.
    $this->actingAs($context['pengurus'])
        ->putJson($scheduleUrl, ['teacher_id' => 'bukan-uuid'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['teacher_id']);
    $this->actingAs($context['pengurus'])
        ->postJson('/api/v1/teaching-schedules/replace-teacher', [
            'source_teacher_id' => $context['ustadzAhmad']->id,
            'target_teacher_id' => $context['ustadzAhmad']->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['target_teacher_id']);

    // Without manage-schedules.
    $userWithoutPermission = User::factory()->create(['school_id' => $context['school']->id]);
    $this->actingAs($userWithoutPermission)
        ->putJson($scheduleUrl, ['teacher_id' => $context['ustadzBakar']->id])
        ->assertForbidden();
    $this->actingAs($userWithoutPermission)
        ->postJson('/api/v1/teaching-schedules/replace-teacher', [
            'source_teacher_id' => $context['ustadzAhmad']->id,
            'target_teacher_id' => $context['ustadzBakar']->id,
        ])
        ->assertForbidden();

    expect(TeachingScheduleTeacherHistory::count())->toBe(0)
        ->and(TeachingSchedule::findOrFail($context['scheduleId'])->teacher_id)->toBe($context['ustadzAhmad']->id);
});

// ── Semester Akademik tetap ──────────────────────────────────────────────

const TEACHER_HISTORY_FIXED_SEMESTER_MESSAGE = 'Semester Akademik jadwal tidak dapat diubah; salin jadwal ke semester lain.';

test('an edit cannot move a schedule to another Semester Akademik', function () {
    $context = setUpTeacherHistoryContext($this);
    $scheduleUrl = "/api/v1/teaching-schedules/{$context['scheduleId']}";
    $otherAcademicYear = AcademicYear::factory()->create(['school_id' => $context['school']->id, 'is_active' => false]);

    $this->actingAs($context['pengurus'])
        ->putJson($scheduleUrl, teacherHistoryEditFormPayload($context['scheduleId'], ['semester' => 2]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['semester' => TEACHER_HISTORY_FIXED_SEMESTER_MESSAGE]);

    $this->actingAs($context['pengurus'])
        ->putJson($scheduleUrl, teacherHistoryEditFormPayload($context['scheduleId'], ['academic_year_id' => $otherAcademicYear->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['academic_year_id' => TEACHER_HISTORY_FIXED_SEMESTER_MESSAGE]);

    // Moving to another semester together with a new Ustadz is refused as a whole.
    $this->actingAs($context['pengurus'])
        ->putJson($scheduleUrl, ['semester' => 2, 'teacher_id' => $context['ustadzBakar']->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['semester']);

    $schedule = TeachingSchedule::findOrFail($context['scheduleId']);
    expect($schedule->academic_year_id)->toBe($context['academicYear']->id)
        ->and($schedule->semester)->toBe(1)
        ->and($schedule->teacher_id)->toBe($context['ustadzAhmad']->id)
        ->and(TeachingScheduleTeacherHistory::count())->toBe(0);

    // The edit form re-sends the schedule's own year and semester: accepted.
    $this->actingAs($context['pengurus'])
        ->putJson($scheduleUrl, teacherHistoryEditFormPayload($context['scheduleId'], ['day_of_week' => 'wednesday']))
        ->assertOk()
        ->assertJsonPath('data.day_of_week', 'wednesday')
        ->assertJsonPath('data.semester', 1);
});

// ── Tenancy ──────────────────────────────────────────────────────────────

test('a schedule of another school is not found for the edit and untouched by the bulk ganti ustadz', function () {
    $context = setUpTeacherHistoryContext($this);

    $otherSchool = School::factory()->inactive()->create();
    // Only reachable by id: the API never lets the active school write here.
    $otherSchoolSchedule = TeachingSchedule::factory()->create([
        'school_id' => $otherSchool->id,
        'academic_year_id' => AcademicYear::factory()->create(['school_id' => $otherSchool->id])->id,
        'semester' => 1,
        'time_slot_id' => TimeSlot::factory()->create(['school_id' => $otherSchool->id])->id,
        'class_level_id' => ClassLevel::factory()->create(['school_id' => $otherSchool->id])->id,
        'subject_book_id' => SubjectBook::factory()->create([
            'school_id' => $otherSchool->id,
            'subject_category_id' => SubjectCategory::factory()->create(['school_id' => $otherSchool->id])->id,
        ])->id,
        'teacher_id' => $context['ustadzAhmad']->id,
    ]);

    $this->actingAs($context['pengurus'])
        ->putJson("/api/v1/teaching-schedules/{$otherSchoolSchedule->id}", ['teacher_id' => $context['ustadzBakar']->id])
        ->assertNotFound();

    $this->actingAs($context['pengurus'])
        ->postJson('/api/v1/teaching-schedules/replace-teacher', [
            'source_teacher_id' => $context['ustadzAhmad']->id,
            'target_teacher_id' => $context['ustadzBakar']->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.updated', 1);

    expect($otherSchoolSchedule->fresh()->teacher_id)->toBe($context['ustadzAhmad']->id)
        ->and(TeachingScheduleTeacherHistory::pluck('teaching_schedule_id')->all())->toBe([$context['scheduleId']])
        ->and(TeachingScheduleTeacherHistory::pluck('school_id')->all())->toBe([$context['school']->id]);
});
