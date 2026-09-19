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
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SchoolSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Kelas gabungan (ADR 0006): one Jadwal Mengajar may hold several Kelas —
 * Takmilah ba'da Isya is taught to Ibtida 2 and Tsanawiyah 1 at once. Every
 * schedule here is created and changed through the real endpoints, and a
 * single-class schedule must keep behaving exactly as before.
 */

/**
 * Pengurus manages semester 1; Ustadz Ali teaches Takmilah, Ustadz Umar is
 * the second teacher. Kelas: Ibtida 2, Tsanawiyah 1, Tamhidi.
 *
 * @return array<string, mixed>
 */
function setUpCombinedScheduleContext(): array
{
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();

    $school = School::where('is_active', true)->firstOrFail();

    $pengurus = User::factory()->create(['school_id' => $school->id, 'name' => 'Pengurus Jadwal']);
    $pengurus->assignRole('pengurus_pesantren');

    $subjectCategory = SubjectCategory::factory()->create(['school_id' => $school->id]);

    return [
        'school' => $school,
        'pengurus' => $pengurus,
        'academicYear' => AcademicYear::factory()->create(['school_id' => $school->id, 'is_active' => true]),
        'ishaSlot' => TimeSlot::factory()->create(['school_id' => $school->id, 'sort_order' => 1]),
        'subuhSlot' => TimeSlot::factory()->create(['school_id' => $school->id, 'sort_order' => 2]),
        'ibtida2' => ClassLevel::factory()->create(['school_id' => $school->id, 'slug' => 'ibtida_2', 'label' => 'Ibtida 2', 'sort_order' => 1]),
        'tsanawiyah1' => ClassLevel::factory()->create(['school_id' => $school->id, 'slug' => 'tsanawiyah_1', 'label' => 'Tsanawiyah 1', 'sort_order' => 2]),
        'tamhidi' => ClassLevel::factory()->create(['school_id' => $school->id, 'slug' => 'tamhidi', 'label' => 'Tamhidi', 'sort_order' => 3]),
        'takmilah' => SubjectBook::factory()->create(['school_id' => $school->id, 'subject_category_id' => $subjectCategory->id, 'title' => 'Takmilah']),
        'jurumiyah' => SubjectBook::factory()->create(['school_id' => $school->id, 'subject_category_id' => $subjectCategory->id, 'title' => 'Jurumiyah']),
        'ustadzAli' => Teacher::factory()->create(['school_id' => $school->id, 'full_name' => 'Ustadz Ali', 'user_id' => null]),
        'ustadzUmar' => Teacher::factory()->create(['school_id' => $school->id, 'full_name' => 'Ustadz Umar', 'user_id' => null]),
    ];
}

/**
 * The payload of the "Tambah Jadwal" form.
 *
 * @param  array<string, mixed>  $attributes
 * @return array<string, mixed>
 */
function combinedSchedulePayload(array $context, array $attributes): array
{
    return array_merge([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'day_of_week' => 'monday',
        'time_slot_id' => $context['ishaSlot']->id,
        'subject_book_id' => $context['takmilah']->id,
        'teacher_id' => $context['ustadzAli']->id,
    ], $attributes);
}

/**
 * Creates a schedule of combinedSchedulePayload() through POST
 * /teaching-schedules and returns its id — every test below that needs a
 * schedule to work on starts here.
 *
 * @param  array<string, mixed>  $attributes
 */
function createCombinedSchedule($testCase, array $context, array $attributes): string
{
    return createTeachingScheduleThroughEndpoint($testCase, $context['pengurus'], combinedSchedulePayload($context, $attributes));
}

/**
 * The Kelas ids stored for a schedule, read from the join table.
 *
 * @return array<int, string>
 */
function storedClassLevelIds(string $scheduleId): array
{
    return DB::table('teaching_schedule_class_levels')
        ->where('teaching_schedule_id', $scheduleId)
        ->pluck('class_level_id')
        ->sort()
        ->values()
        ->all();
}

// ── Membuat jadwal ───────────────────────────────────────────────────────

test('the schedule table keeps no Kelas of its own any more', function () {
    // Contract stage of ADR 0006: one source of truth. The column and the
    // partial unique index that held "one Kelas, one slot" on it are gone —
    // TeachingScheduleService keeps that rule now, inside its transaction.
    expect(Schema::hasColumn('teaching_schedules', 'class_level_id'))->toBeFalse()
        ->and(collect(Schema::getIndexes('teaching_schedules'))->pluck('name')->all())
        ->not->toContain('unique_active_class_schedule_slot')
        ->not->toContain('idx_schedules_class');
});

test('a schedule can be created for two Kelas at once', function () {
    $context = setUpCombinedScheduleContext();

    $response = $this->actingAs($context['pengurus'])
        ->postJson('/api/v1/teaching-schedules', combinedSchedulePayload($context, [
            'class_level_ids' => [$context['ibtida2']->id, $context['tsanawiyah1']->id],
        ]));

    $response->assertCreated();

    $scheduleId = $response->json('data.id');

    expect(collect($response->json('data.class_levels'))->pluck('label')->all())
        ->toEqual(['Ibtida 2', 'Tsanawiyah 1']);

    expect(storedClassLevelIds($scheduleId))
        ->toEqual(collect([$context['ibtida2']->id, $context['tsanawiyah1']->id])->sort()->values()->all());
});

test('the Kelas of a combined schedule read in the Kelas master order, whatever order the form sent', function () {
    $context = setUpCombinedScheduleContext();

    $scheduleId = createCombinedSchedule($this, $context, [
        // Sent in the reverse of the Kelas master order on purpose.
        'class_level_ids' => [$context['tsanawiyah1']->id, $context['ibtida2']->id],
    ]);

    // The order is the Kelas master's, not the form's, so a schedule names
    // its Kelas the same way everywhere and the Kelas utama a Pertemuan
    // snapshots (classLevelIds()[0]) is the same on every save.
    $schedule = TeachingSchedule::findOrFail($scheduleId);

    expect($schedule->classLevelIds())->toBe([$context['ibtida2']->id, $context['tsanawiyah1']->id])
        ->and($schedule->classLevelsLabel())->toBe('Ibtida 2 + Tsanawiyah 1');
});

test('a schedule of one Kelas is stored and answered as a set of one', function () {
    $context = setUpCombinedScheduleContext();

    $response = $this->actingAs($context['pengurus'])
        ->postJson('/api/v1/teaching-schedules', combinedSchedulePayload($context, [
            'class_level_ids' => [$context['tamhidi']->id],
        ]));

    $response->assertCreated()
        ->assertJsonPath('data.class_levels.0.id', $context['tamhidi']->id)
        ->assertJsonPath('data.class_levels.0.label', 'Tamhidi')
        ->assertJsonCount(1, 'data.class_levels');

    expect(storedClassLevelIds($response->json('data.id')))->toEqual([$context['tamhidi']->id]);
});

test('a schedule needs at least one Kelas, without duplicates, from the active school', function () {
    $context = setUpCombinedScheduleContext();
    $otherSchoolClass = ClassLevel::factory()->create(['label' => 'Kelas Sekolah Lain']);

    $this->actingAs($context['pengurus'])
        ->postJson('/api/v1/teaching-schedules', combinedSchedulePayload($context, ['class_level_ids' => []]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['class_level_ids']);

    $this->actingAs($context['pengurus'])
        ->postJson('/api/v1/teaching-schedules', combinedSchedulePayload($context, [
            'class_level_ids' => [$context['ibtida2']->id, $context['ibtida2']->id],
        ]))
        ->assertUnprocessable();

    $this->actingAs($context['pengurus'])
        ->postJson('/api/v1/teaching-schedules', combinedSchedulePayload($context, [
            'class_level_ids' => [$otherSchoolClass->id],
        ]))
        ->assertUnprocessable();
});

// ── Mengubah daftar kelas ────────────────────────────────────────────────

test('a Kelas can be added to an existing schedule through the edit', function () {
    $context = setUpCombinedScheduleContext();

    $scheduleId = createCombinedSchedule($this, $context, [
        'class_level_ids' => [$context['tsanawiyah1']->id],
    ]);

    $response = $this->actingAs($context['pengurus'])
        ->putJson("/api/v1/teaching-schedules/{$scheduleId}", [
            'class_level_ids' => [$context['tsanawiyah1']->id, $context['ibtida2']->id],
        ]);

    $response->assertOk();

    expect(collect($response->json('data.class_levels'))->pluck('label')->all())
        ->toEqual(['Ibtida 2', 'Tsanawiyah 1'])
        ->and(storedClassLevelIds($scheduleId))->toHaveCount(2);
});

// ── Bentrok ──────────────────────────────────────────────────────────────

test('a Kelas that already has a schedule in the slot cannot join another one', function () {
    $context = setUpCombinedScheduleContext();

    createCombinedSchedule($this, $context, [
        'class_level_ids' => [$context['ibtida2']->id],
    ]);

    $response = $this->actingAs($context['pengurus'])
        ->postJson('/api/v1/teaching-schedules', combinedSchedulePayload($context, [
            'class_level_ids' => [$context['tamhidi']->id, $context['ibtida2']->id],
            'teacher_id' => $context['ustadzUmar']->id,
            'subject_book_id' => $context['jurumiyah']->id,
        ]));

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['class_level_ids']);

    expect($response->json('errors.class_level_ids.0'))
        ->toContain('Ibtida 2')
        ->not->toContain('Tamhidi');
});

test('a combined schedule blocks the slot for every Kelas it holds', function () {
    $context = setUpCombinedScheduleContext();

    createCombinedSchedule($this, $context, [
        'class_level_ids' => [$context['ibtida2']->id, $context['tsanawiyah1']->id],
    ]);

    $response = $this->actingAs($context['pengurus'])
        ->postJson('/api/v1/teaching-schedules', combinedSchedulePayload($context, [
            'class_level_ids' => [$context['tsanawiyah1']->id],
            'teacher_id' => $context['ustadzUmar']->id,
            'subject_book_id' => $context['jurumiyah']->id,
        ]));

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['class_level_ids']);

    expect($response->json('errors.class_level_ids.0'))->toContain('Tsanawiyah 1');
});

test('an Ustadz already teaching in the slot is still refused a second schedule, named by its Kelas', function () {
    $context = setUpCombinedScheduleContext();

    createCombinedSchedule($this, $context, [
        'class_level_ids' => [$context['ibtida2']->id, $context['tsanawiyah1']->id],
    ]);

    $response = $this->actingAs($context['pengurus'])
        ->postJson('/api/v1/teaching-schedules', combinedSchedulePayload($context, [
            'class_level_ids' => [$context['tamhidi']->id],
            'subject_book_id' => $context['jurumiyah']->id,
        ]));

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['teacher_id']);

    expect($response->json('errors.teacher_id.0'))
        ->toContain('Ibtida 2')
        ->toContain('Tsanawiyah 1');
});

test('a refused change leaves the Kelas of the schedule untouched', function () {
    $context = setUpCombinedScheduleContext();

    $blockingScheduleId = createCombinedSchedule($this, $context, [
        'class_level_ids' => [$context['tamhidi']->id],
        'teacher_id' => $context['ustadzUmar']->id,
    ]);

    $scheduleId = createCombinedSchedule($this, $context, [
        'class_level_ids' => [$context['ibtida2']->id],
        'day_of_week' => 'tuesday',
    ]);

    $this->actingAs($context['pengurus'])
        ->putJson("/api/v1/teaching-schedules/{$scheduleId}", [
            'day_of_week' => 'monday',
            'class_level_ids' => [$context['ibtida2']->id, $context['tamhidi']->id],
        ])
        ->assertUnprocessable();

    expect(storedClassLevelIds($scheduleId))->toEqual([$context['ibtida2']->id])
        ->and(storedClassLevelIds($blockingScheduleId))->toEqual([$context['tamhidi']->id])
        ->and(TeachingScheduleTeacherHistory::count())->toBe(0);
});

// ── Riwayat pengajar (ADR 0005) ──────────────────────────────────────────

test('removing one Kelas from a combined schedule records one riwayat pengajar row for it', function () {
    $context = setUpCombinedScheduleContext();

    $scheduleId = createCombinedSchedule($this, $context, [
        'class_level_ids' => [$context['ibtida2']->id, $context['tsanawiyah1']->id],
    ]);

    $this->actingAs($context['pengurus'])
        ->putJson("/api/v1/teaching-schedules/{$scheduleId}", [
            'class_level_ids' => [$context['tsanawiyah1']->id],
        ])
        ->assertOk();

    $histories = TeachingScheduleTeacherHistory::all();

    expect($histories)->toHaveCount(1);
    expect($histories->first()->only([
        'teaching_schedule_id', 'previous_teacher_id', 'previous_class_level_id', 'previous_subject_book_id', 'semester',
    ]))->toEqual([
        'teaching_schedule_id' => $scheduleId,
        'previous_teacher_id' => $context['ustadzAli']->id,
        'previous_class_level_id' => $context['ibtida2']->id,
        'previous_subject_book_id' => $context['takmilah']->id,
        'semester' => 1,
    ]);
});

test('changing the Ustadz of a combined schedule records one riwayat pengajar row per Kelas', function () {
    $context = setUpCombinedScheduleContext();

    $scheduleId = createCombinedSchedule($this, $context, [
        'class_level_ids' => [$context['ibtida2']->id, $context['tsanawiyah1']->id],
    ]);

    $this->actingAs($context['pengurus'])
        ->putJson("/api/v1/teaching-schedules/{$scheduleId}", [
            'teacher_id' => $context['ustadzUmar']->id,
        ])
        ->assertOk();

    $histories = TeachingScheduleTeacherHistory::all();

    expect($histories)->toHaveCount(2)
        ->and($histories->pluck('previous_teacher_id')->unique()->all())->toEqual([$context['ustadzAli']->id])
        ->and($histories->pluck('previous_class_level_id')->sort()->values()->all())
        ->toEqual(collect([$context['ibtida2']->id, $context['tsanawiyah1']->id])->sort()->values()->all());
});

test('moving a combined schedule to another day records nothing', function () {
    $context = setUpCombinedScheduleContext();

    $scheduleId = createCombinedSchedule($this, $context, [
        'class_level_ids' => [$context['ibtida2']->id, $context['tsanawiyah1']->id],
    ]);

    $this->actingAs($context['pengurus'])
        ->putJson("/api/v1/teaching-schedules/{$scheduleId}", [
            'day_of_week' => 'wednesday',
            'class_level_ids' => [$context['tsanawiyah1']->id, $context['ibtida2']->id],
        ])
        ->assertOk();

    expect(TeachingScheduleTeacherHistory::count())->toBe(0)
        ->and(storedClassLevelIds($scheduleId))->toHaveCount(2);
});

test('the bulk ganti ustadz moves a combined schedule and records every Kelas', function () {
    $context = setUpCombinedScheduleContext();

    $scheduleId = createCombinedSchedule($this, $context, [
        'class_level_ids' => [$context['ibtida2']->id, $context['tsanawiyah1']->id],
    ]);

    $this->actingAs($context['pengurus'])
        ->postJson('/api/v1/teaching-schedules/replace-teacher', [
            'source_teacher_id' => $context['ustadzAli']->id,
            'target_teacher_id' => $context['ustadzUmar']->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.updated', 1);

    expect(TeachingSchedule::findOrFail($scheduleId)->teacher_id)->toBe($context['ustadzUmar']->id)
        ->and(storedClassLevelIds($scheduleId))->toHaveCount(2)
        ->and(TeachingScheduleTeacherHistory::count())->toBe(2);
});

test('the bulk ganti ustadz names every Kelas of the schedule that blocks it', function () {
    $context = setUpCombinedScheduleContext();

    // Ustadz Ali already fills Monday ba'da Isya with a combined schedule.
    createCombinedSchedule($this, $context, [
        'class_level_ids' => [$context['ibtida2']->id, $context['tsanawiyah1']->id],
    ]);

    // Ustadz Umar teaches Tamhidi in the very same slot.
    $umarScheduleId = createCombinedSchedule($this, $context, [
        'class_level_ids' => [$context['tamhidi']->id],
        'teacher_id' => $context['ustadzUmar']->id,
        'subject_book_id' => $context['jurumiyah']->id,
    ]);

    $response = $this->actingAs($context['pengurus'])
        ->postJson('/api/v1/teaching-schedules/replace-teacher', [
            'source_teacher_id' => $context['ustadzUmar']->id,
            'target_teacher_id' => $context['ustadzAli']->id,
        ]);

    $response->assertOk()
        ->assertJsonPath('data.updated', 0)
        ->assertJsonPath('data.conflicts.0.schedule_id', $umarScheduleId);

    expect($response->json('data.conflicts.0.conflicting_class'))
        ->toContain('Ibtida 2')
        ->toContain('Tsanawiyah 1');
});

// ── Salin jadwal ─────────────────────────────────────────────────────────

test('cloning a semester carries every Kelas of a combined schedule', function () {
    $context = setUpCombinedScheduleContext();

    createCombinedSchedule($this, $context, [
        'class_level_ids' => [$context['ibtida2']->id, $context['tsanawiyah1']->id],
    ]);

    $this->actingAs($context['pengurus'])
        ->postJson('/api/v1/teaching-schedules/clone-semester', [
            'source_academic_year_id' => $context['academicYear']->id,
            'source_semester' => 1,
            'target_academic_year_id' => $context['academicYear']->id,
            'target_semester' => 2,
        ])
        ->assertCreated()
        ->assertJsonPath('data.created', 1);

    $clonedSchedule = TeachingSchedule::where('semester', 2)->firstOrFail();

    expect(storedClassLevelIds($clonedSchedule->id))
        ->toEqual(collect([$context['ibtida2']->id, $context['tsanawiyah1']->id])->sort()->values()->all());
});

test('cloning skips a schedule whose Kelas is already busy in the target semester', function () {
    $context = setUpCombinedScheduleContext();

    createCombinedSchedule($this, $context, [
        'class_level_ids' => [$context['ibtida2']->id, $context['tsanawiyah1']->id],
    ]);

    // Semester 2 already has Tsanawiyah 1 in that slot, with another Ustadz.
    createCombinedSchedule($this, $context, [
        'semester' => 2,
        'class_level_ids' => [$context['tsanawiyah1']->id],
        'teacher_id' => $context['ustadzUmar']->id,
    ]);

    $this->actingAs($context['pengurus'])
        ->postJson('/api/v1/teaching-schedules/clone-semester', [
            'source_academic_year_id' => $context['academicYear']->id,
            'source_semester' => 1,
            'target_academic_year_id' => $context['academicYear']->id,
            'target_semester' => 2,
        ])
        ->assertCreated()
        ->assertJsonPath('data.created', 0)
        ->assertJsonPath('data.skipped', 1)
        ->assertJsonPath('data.skipped_details.0.reason', 'class_slot_conflict');
});

// ── Daftar jadwal ────────────────────────────────────────────────────────

test('filtering the schedule list by Kelas finds every schedule holding it', function () {
    $context = setUpCombinedScheduleContext();

    $combinedScheduleId = createCombinedSchedule($this, $context, [
        'class_level_ids' => [$context['ibtida2']->id, $context['tsanawiyah1']->id],
    ]);

    createCombinedSchedule($this, $context, [
        'class_level_ids' => [$context['tamhidi']->id],
        'day_of_week' => 'tuesday',
    ]);

    $response = $this->actingAs($context['pengurus'])
        ->getJson('/api/v1/teaching-schedules?'.http_build_query([
            'academic_year_id' => $context['academicYear']->id,
            'class_level_id' => $context['tsanawiyah1']->id,
        ]));

    $response->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())->toEqual([$combinedScheduleId]);
});
