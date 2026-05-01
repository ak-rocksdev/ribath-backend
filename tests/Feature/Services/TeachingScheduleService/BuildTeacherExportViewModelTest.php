<?php

use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\School;
use App\Models\SubjectBook;
use App\Models\Teacher;
use App\Models\TeachingSchedule;
use App\Models\TimeSlot;
use App\Services\TeachingScheduleService;

function makeScheduleFor(
    Teacher $teacher,
    School $school,
    AcademicYear $year,
    int $semester,
    string $day,
    TimeSlot $slot,
    ?SubjectBook $book = null,
    ?ClassLevel $class = null,
    bool $active = true,
): TeachingSchedule {
    return TeachingSchedule::factory()->create([
        'school_id'        => $school->id,
        'academic_year_id' => $year->id,
        'semester'         => $semester,
        'day_of_week'      => $day,
        'time_slot_id'     => $slot->id,
        'subject_book_id'  => ($book ?? SubjectBook::factory()->create(['school_id' => $school->id]))->id,
        'class_level_id'   => ($class ?? ClassLevel::factory()->create(['school_id' => $school->id]))->id,
        'teacher_id'       => $teacher->id,
        'is_active'        => $active,
    ]);
}

beforeEach(function () {
    $this->school = School::factory()->create(['is_active' => true]);
    $this->year   = AcademicYear::factory()->create(['school_id' => $this->school->id]);
    $this->teacher = Teacher::factory()->create(['school_id' => $this->school->id]);
    $this->service = new TeachingScheduleService();

    // Three time slots with explicit sort order
    $this->slotEarly = TimeSlot::factory()->create(['school_id' => $this->school->id, 'label' => "Ba'da Subuh", 'sort_order' => 1]);
    $this->slotMid   = TimeSlot::factory()->create(['school_id' => $this->school->id, 'label' => "Ba'da Ashar", 'sort_order' => 5]);
    $this->slotLate  = TimeSlot::factory()->create(['school_id' => $this->school->id, 'label' => "Ba'da Isya", 'sort_order' => 8]);
});

test('sorts schedules by canonical day order then time_slot.sort_order', function () {
    // Insert in deliberately scrambled order
    makeScheduleFor($this->teacher, $this->school, $this->year, 1, 'friday',    $this->slotLate);
    makeScheduleFor($this->teacher, $this->school, $this->year, 1, 'monday',    $this->slotLate);
    makeScheduleFor($this->teacher, $this->school, $this->year, 1, 'monday',    $this->slotEarly);
    makeScheduleFor($this->teacher, $this->school, $this->year, 1, 'wednesday', $this->slotMid);

    $vm = $this->service->buildTeacherExportViewModel($this->teacher, $this->year->id, 1);

    expect(collect($vm['schedules_sorted'])->map(fn ($s) => "{$s->day_of_week}:{$s->timeSlot->sort_order}")->all())
        ->toEqual(['monday:1', 'monday:8', 'wednesday:5', 'friday:8']);
});

test('groups schedules by day in canonical order with Indonesian labels', function () {
    makeScheduleFor($this->teacher, $this->school, $this->year, 1, 'monday', $this->slotEarly);
    makeScheduleFor($this->teacher, $this->school, $this->year, 1, 'monday', $this->slotLate);
    makeScheduleFor($this->teacher, $this->school, $this->year, 1, 'friday', $this->slotEarly);
    makeScheduleFor($this->teacher, $this->school, $this->year, 1, 'sunday', $this->slotEarly);

    $vm = $this->service->buildTeacherExportViewModel($this->teacher, $this->year->id, 1);

    expect(collect($vm['schedules_by_day'])->pluck('day')->all())->toEqual(['monday', 'friday', 'sunday']);
    expect(collect($vm['schedules_by_day'])->pluck('label')->all())->toEqual(['Senin', 'Jumat', 'Ahad']);
    expect(count($vm['schedules_by_day'][0]['items']))->toBe(2); // monday has 2 sessions
});

test('returns ordered distinct time slots by sort_order', function () {
    makeScheduleFor($this->teacher, $this->school, $this->year, 1, 'monday',    $this->slotLate);
    makeScheduleFor($this->teacher, $this->school, $this->year, 1, 'tuesday',   $this->slotEarly);
    makeScheduleFor($this->teacher, $this->school, $this->year, 1, 'wednesday', $this->slotMid);
    makeScheduleFor($this->teacher, $this->school, $this->year, 1, 'thursday',  $this->slotEarly); // duplicate slot

    $vm = $this->service->buildTeacherExportViewModel($this->teacher, $this->year->id, 1);

    expect(collect($vm['time_slots'])->pluck('sort_order')->all())->toEqual([1, 5, 8]);
    expect(count($vm['time_slots']))->toBe(3); // distinct
});

test('computes totals correctly', function () {
    $book1 = SubjectBook::factory()->create(['school_id' => $this->school->id]);
    $book2 = SubjectBook::factory()->create(['school_id' => $this->school->id]);
    $class1 = ClassLevel::factory()->create(['school_id' => $this->school->id]);
    $class2 = ClassLevel::factory()->create(['school_id' => $this->school->id]);

    makeScheduleFor($this->teacher, $this->school, $this->year, 1, 'monday',  $this->slotEarly, $book1, $class1);
    makeScheduleFor($this->teacher, $this->school, $this->year, 1, 'tuesday', $this->slotEarly, $book1, $class1);
    makeScheduleFor($this->teacher, $this->school, $this->year, 1, 'wednesday', $this->slotEarly, $book2, $class2);

    $vm = $this->service->buildTeacherExportViewModel($this->teacher, $this->year->id, 1);

    expect($vm['totals'])->toEqual([
        'sesi'  => 3,
        'kitab' => 2,
        'kelas' => 2,
    ]);
});

test('filters by academic_year_id and semester', function () {
    $otherYear = AcademicYear::factory()->create(['school_id' => $this->school->id]);

    makeScheduleFor($this->teacher, $this->school, $this->year,      1, 'monday', $this->slotEarly);
    makeScheduleFor($this->teacher, $this->school, $this->year,      2, 'monday', $this->slotEarly); // wrong semester
    makeScheduleFor($this->teacher, $this->school, $otherYear,       1, 'monday', $this->slotEarly); // wrong year

    $vm = $this->service->buildTeacherExportViewModel($this->teacher, $this->year->id, 1);

    expect($vm['totals']['sesi'])->toBe(1);
});

test('excludes inactive schedules and other teachers', function () {
    $otherTeacher = Teacher::factory()->create(['school_id' => $this->school->id]);

    makeScheduleFor($this->teacher, $this->school, $this->year, 1, 'monday', $this->slotEarly);
    makeScheduleFor($this->teacher, $this->school, $this->year, 1, 'tuesday', $this->slotEarly, active: false);
    makeScheduleFor($otherTeacher,  $this->school, $this->year, 1, 'wednesday', $this->slotEarly);

    $vm = $this->service->buildTeacherExportViewModel($this->teacher, $this->year->id, 1);

    expect($vm['totals']['sesi'])->toBe(1);
});

test('isolates schedules by school (multi-tenant)', function () {
    // A schedule recorded against a DIFFERENT school but the same teacher_id should never leak
    $otherSchool = School::factory()->create(['is_active' => true]);
    $otherSchoolYear = AcademicYear::factory()->create(['school_id' => $otherSchool->id]);
    $otherSchoolSlot = TimeSlot::factory()->create(['school_id' => $otherSchool->id]);
    $otherSchoolBook = SubjectBook::factory()->create(['school_id' => $otherSchool->id]);
    $otherSchoolClass = ClassLevel::factory()->create(['school_id' => $otherSchool->id]);

    // Same teacher, same year_id, semester=1, but a different school's row
    TeachingSchedule::factory()->create([
        'school_id'        => $otherSchool->id,
        'academic_year_id' => $otherSchoolYear->id,
        'semester'         => 1,
        'day_of_week'      => 'monday',
        'time_slot_id'     => $otherSchoolSlot->id,
        'subject_book_id'  => $otherSchoolBook->id,
        'class_level_id'   => $otherSchoolClass->id,
        'teacher_id'       => $this->teacher->id,
        'is_active'        => true,
    ]);

    // One legitimate schedule for this teacher's school
    makeScheduleFor($this->teacher, $this->school, $this->year, 1, 'tuesday', $this->slotEarly);

    $vm = $this->service->buildTeacherExportViewModel($this->teacher, $this->year->id, 1);

    expect($vm['totals']['sesi'])->toBe(1)
        ->and($vm['schedules_sorted'][0]->day_of_week)->toBe('tuesday');
});

test('builds the canonical filename', function () {
    $vm = [
        'teacher'       => ['code' => 'AKH'],
        'semester'      => 1,
        'academic_year' => ['name' => '1447/1448'],
    ];

    expect($this->service->buildTeacherExportFilename($vm))
        ->toBe('Jadwal-AKH-Sem1-1447-1448.pdf');
});

test('filename falls back gracefully when fields are missing', function () {
    expect($this->service->buildTeacherExportFilename([]))
        ->toBe('Jadwal-Ustadz-Sem1-TA.pdf');
});

test('returns empty structures when teacher has no schedules', function () {
    $vm = $this->service->buildTeacherExportViewModel($this->teacher, $this->year->id, 1);

    expect($vm['schedules_sorted'])->toEqual([]);
    expect($vm['schedules_by_day'])->toEqual([]);
    expect($vm['time_slots'])->toEqual([]);
    expect($vm['totals'])->toEqual(['sesi' => 0, 'kitab' => 0, 'kelas' => 0]);
});

test('includes school info from teachers school', function () {
    $school = School::factory()->create([
        'name'    => 'Pesantren Test',
        'address' => 'Jl. Test No. 1',
        'phone'   => '021-9999',
        'email'   => 'test@example.com',
        'is_active' => true,
    ]);
    $teacher = Teacher::factory()->create(['school_id' => $school->id]);
    $year = AcademicYear::factory()->create(['school_id' => $school->id]);

    $vm = $this->service->buildTeacherExportViewModel($teacher, $year->id, 1);

    expect($vm['school'])->toEqual([
        'name'    => 'Pesantren Test',
        'address' => 'Jl. Test No. 1',
        'phone'   => '021-9999',
        'email'   => 'test@example.com',
    ]);
});
