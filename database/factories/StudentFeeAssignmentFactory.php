<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\FeeType;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentFeeAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

class StudentFeeAssignmentFactory extends Factory
{
    protected $model = StudentFeeAssignment::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'student_id' => Student::factory(),
            'fee_type_id' => FeeType::factory(),
            'locked_amount' => $this->faker->numberBetween(50000, 5000000),
            'cadence' => 'monthly',
            'source_academic_year_id' => AcademicYear::factory(),
            'source' => StudentFeeAssignment::SOURCE_AUTO_ENROLLMENT,
            'created_by' => null,
        ];
    }

    public function forStudent(Student $student): self
    {
        return $this->state(fn () => [
            'school_id' => $student->school_id,
            'student_id' => $student->id,
        ]);
    }

    public function forFeeType(FeeType $feeType): self
    {
        return $this->state(fn () => [
            'fee_type_id' => $feeType->id,
            'cadence' => $feeType->default_cadence,
        ]);
    }

    public function forAcademicYear(AcademicYear $ay): self
    {
        return $this->state(fn () => [
            'source_academic_year_id' => $ay->id,
        ]);
    }

    public function manualSnapshot(): self
    {
        return $this->state(fn () => ['source' => StudentFeeAssignment::SOURCE_MANUAL_SNAPSHOT]);
    }

    public function manualEntry(): self
    {
        return $this->state(fn () => ['source' => StudentFeeAssignment::SOURCE_MANUAL_ENTRY]);
    }
}
