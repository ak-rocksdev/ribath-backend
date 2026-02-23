<?php

namespace Database\Factories;

use App\Models\RegistrationPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

class RegistrationPeriodFactory extends Factory
{
    protected $model = RegistrationPeriod::class;

    public function definition(): array
    {
        $year = $this->faker->year();
        $nextYear = $year + 1;
        $openDate = $this->faker->dateTimeBetween('now', '+1 month');
        $closeDate = $this->faker->dateTimeBetween($openDate, '+3 months');
        $entryDate = $this->faker->dateTimeBetween($closeDate, '+6 months');

        return [
            'name' => "Pendaftaran {$year}/{$nextYear}",
            'year' => "{$year}/{$nextYear}",
            'wave' => $this->faker->numberBetween(1, 3),
            'registration_open' => $openDate,
            'registration_close' => $closeDate,
            'entry_date' => $entryDate,
            'registration_fee' => $this->faker->randomElement([0, 100000, 250000, 500000]),
            'monthly_tuition_fee' => $this->faker->randomElement([500000, 750000, 1000000]),
            'student_quota' => $this->faker->optional(0.7)->numberBetween(20, 100),
            'enrolled_count' => 0,
            'description' => $this->faker->optional()->sentence(),
            'is_active' => true,
        ];
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
