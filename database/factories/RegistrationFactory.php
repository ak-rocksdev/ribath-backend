<?php

namespace Database\Factories;

use App\Models\Registration;
use App\Models\RegistrationPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

class RegistrationFactory extends Factory
{
    protected $model = Registration::class;

    public function definition(): array
    {
        $registrantType = $this->faker->randomElement(['guardian', 'student']);

        return [
            'registration_period_id' => RegistrationPeriod::factory(),
            'registration_number' => 'PSB-'.now()->year.'-'.str_pad($this->faker->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'status' => Registration::STATUS_NEW,
            'registrant_type' => $registrantType,
            'full_name' => $this->faker->name(),
            'birth_place' => $this->faker->city(),
            'birth_date' => $this->faker->date(),
            'gender' => $this->faker->randomElement(['L', 'P']),
            'preferred_program' => $this->faker->randomElement(['tahfidz', 'regular']),
            'guardian_name' => $registrantType === 'guardian' ? $this->faker->name() : null,
            'guardian_phone' => '08'.$this->faker->numerify('##########'),
            'guardian_email' => $this->faker->optional()->safeEmail(),
            'info_source' => $this->faker->optional()->randomElement(['instagram', 'facebook', 'website', 'teman', 'keluarga']),
        ];
    }

    public function selfRegistered(): static
    {
        return $this->state(fn (array $attributes) => [
            'registrant_type' => 'student',
            'guardian_name' => null,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Registration::STATUS_REJECTED,
            'is_archived' => true,
        ]);
    }
}
