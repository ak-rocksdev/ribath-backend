<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\RegistrationPeriod;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

class RegistrationPeriodFactory extends Factory
{
    protected $model = RegistrationPeriod::class;

    public function definition(): array
    {
        $openDate = $this->faker->dateTimeBetween('now', '+1 month');
        $closeDate = $this->faker->dateTimeBetween($openDate, '+3 months');
        $entryDate = $this->faker->dateTimeBetween($closeDate, '+6 months');

        $year = $this->faker->year();
        $nextYear = $year + 1;

        return [
            'school_id' => School::factory(),
            // Default factory creates a brand-new AY for the same school —
            // callers that need to share an AY across multiple periods should
            // use ->forAcademicYear($ay).
            'academic_year_id' => AcademicYear::factory()->state(function () use ($year, $nextYear) {
                return ['name' => "{$year}/{$nextYear}"];
            }),
            'name' => "Pendaftaran {$year}/{$nextYear}",
            'wave' => $this->faker->numberBetween(1, 3),
            'registration_open' => $openDate,
            'registration_close' => $closeDate,
            'entry_date' => $entryDate,
            'student_quota' => $this->faker->optional(0.7)->numberBetween(20, 100),
            'enrolled_count' => 0,
            'description' => $this->faker->optional()->sentence(),
            'is_active' => true,
        ];
    }

    public function forSchool(School $school): static
    {
        return $this->state(fn () => [
            'school_id' => $school->id,
            'academic_year_id' => AcademicYear::factory()->state(['school_id' => $school->id]),
        ]);
    }

    public function forAcademicYear(AcademicYear $year): static
    {
        return $this->state(fn () => [
            'academic_year_id' => $year->id,
            'school_id' => $year->school_id,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function full(): static
    {
        return $this->state(fn (array $attributes) => [
            'student_quota' => 30,
            'enrolled_count' => 30,
        ]);
    }
}
