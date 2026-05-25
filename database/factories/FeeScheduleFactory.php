<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\FeeSchedule;
use App\Models\FeeType;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

class FeeScheduleFactory extends Factory
{
    protected $model = FeeSchedule::class;

    public function definition(): array
    {
        $school = School::factory();

        return [
            'school_id' => $school,
            'academic_year_id' => AcademicYear::factory(),
            'fee_type_id' => FeeType::factory(),
            'amount' => $this->faker->numberBetween(100_000, 2_000_000),
        ];
    }

    public function forSchool(School $school): static
    {
        return $this->state(fn () => ['school_id' => $school->id]);
    }

    public function forFeeType(FeeType $feeType): static
    {
        return $this->state(fn () => [
            'fee_type_id' => $feeType->id,
            'school_id' => $feeType->school_id,
        ]);
    }

    public function forAcademicYear(AcademicYear $year): static
    {
        return $this->state(fn () => [
            'academic_year_id' => $year->id,
            'school_id' => $year->school_id,
        ]);
    }
}
