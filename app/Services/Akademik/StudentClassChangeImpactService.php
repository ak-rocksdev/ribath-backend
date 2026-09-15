<?php

namespace App\Services\Akademik;

use App\Models\AcademicSemester;
use App\Models\ReportCard;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\StudentGrade;
use App\Models\StudentTaskScore;
use App\Services\AcademicYearService;
use Illuminate\Database\Eloquent\Builder;

/**
 * What correcting a santri's Kelas would leave behind (ticket 20): the
 * santri's Penilaian records in the active Semester Akademik. There is no
 * class history — Nilai, Tugas scores, Absensi and Rapor recorded for the
 * old class stay with the old class, while a Nilai of a kitab that is also
 * taught in the new class carries over (grades are keyed by santri × kitab ×
 * semester). The Kelas & Program dialog warns only when records exist.
 */
class StudentClassChangeImpactService
{
    public function __construct(
        private AcademicYearService $academicYearService,
    ) {}

    /**
     * @return array{
     *     class_level: array{id: string, slug: string, label: string}|null,
     *     program: string|null,
     *     academic_semester: array{academic_year_id: string, academic_year_name: string, semester: int}|null,
     *     record_counts: array{grades: int, task_scores: int, attendances: int, report_cards: int},
     *     has_records: bool
     * }
     */
    public function impactFor(Student $student): array
    {
        $activeAcademicSemester = $this->activeAcademicSemester();
        $recordCounts = $activeAcademicSemester === null
            ? ['grades' => 0, 'task_scores' => 0, 'attendances' => 0, 'report_cards' => 0]
            : $this->countRecordsInSemester($student, $activeAcademicSemester);

        return [
            'class_level' => $student->classLevel?->summary(),
            'program' => $student->program,
            'academic_semester' => $activeAcademicSemester === null ? null : [
                'academic_year_id' => $activeAcademicSemester->academic_year_id,
                'academic_year_name' => $activeAcademicSemester->academicYear->name,
                'semester' => $activeAcademicSemester->semester,
            ],
            'record_counts' => $recordCounts,
            'has_records' => array_sum($recordCounts) > 0,
        ];
    }

    /** The active academic year's active_semester row, or null when no academic year is active. */
    private function activeAcademicSemester(): ?AcademicSemester
    {
        $activeAcademicYear = $this->academicYearService->getActive();
        $activeAcademicSemester = $activeAcademicYear?->semester($activeAcademicYear->active_semester);

        $activeAcademicSemester?->setRelation('academicYear', $activeAcademicYear);

        return $activeAcademicSemester;
    }

    /**
     * Cleared grades and task scores (score NULL, row kept for the audit
     * trail) are not records; soft-deleted Tugas and Pertemuan are excluded
     * by their relations.
     *
     * @return array{grades: int, task_scores: int, attendances: int, report_cards: int}
     */
    private function countRecordsInSemester(Student $student, AcademicSemester $academicSemester): array
    {
        $schoolId = School::activeOrFail()->id;
        $inSemester = fn (Builder $query) => $query
            ->where('academic_year_id', $academicSemester->academic_year_id)
            ->where('semester', $academicSemester->semester);

        return [
            'grades' => StudentGrade::query()
                ->where('school_id', $schoolId)
                ->where('student_id', $student->id)
                ->where($inSemester)
                ->whereNotNull('score')
                ->count(),
            'task_scores' => StudentTaskScore::query()
                ->where('school_id', $schoolId)
                ->where('student_id', $student->id)
                ->whereNotNull('score')
                ->whereHas('classTask', $inSemester)
                ->count(),
            'attendances' => StudentAttendance::query()
                ->where('school_id', $schoolId)
                ->where('student_id', $student->id)
                ->whereHas('classSession', $inSemester)
                ->count(),
            'report_cards' => ReportCard::query()
                ->where('school_id', $schoolId)
                ->where('student_id', $student->id)
                ->where($inSemester)
                ->count(),
        ];
    }
}
