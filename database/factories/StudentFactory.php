<?php

namespace Database\Factories;

use App\Models\ClassLevel;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

class StudentFactory extends Factory
{
    protected $model = Student::class;

    private function randomClassLevelSlug(): string
    {
        $slugsFromDatabase = ClassLevel::pluck('slug')->toArray();

        if (! empty($slugsFromDatabase)) {
            return $this->faker->randomElement($slugsFromDatabase);
        }

        // Fallback for tests that don't seed class_levels
        return $this->faker->randomElement([
            'tamhidi', 'ibtida_1', 'ibtida_2', 'tsanawiyah_1', 'tsanawiyah_2',
            'tahfidz_1', 'tahfidz_2', 'tahfidz_3', 'takhassus_1', 'takhassus_2', 'takhassus_3',
        ]);
    }

    public function definition(): array
    {
        return [
            'full_name' => $this->faker->name(),
            'birth_place' => $this->faker->city(),
            'birth_date' => $this->faker->date(),
            'gender' => $this->faker->randomElement(['L', 'P']),
            'program' => $this->faker->randomElement(['tahfidz', 'regular']),
            'status' => 'active',
            'entry_date' => $this->faker->date(),
            'class_level' => $this->randomClassLevelSlug(),
            'address' => $this->faker->address(),
            'notes' => $this->faker->optional()->sentence(),
        ];
    }

    public function profileComplete(): static
    {
        return $this->state(fn (array $attributes) => [
            'full_name' => $this->faker->name(),
            'birth_place' => $this->faker->city(),
            'birth_date' => $this->faker->date(),
            'gender' => $this->faker->randomElement(['L', 'P']),
            'program' => $this->faker->randomElement(['tahfidz', 'regular']),
            'entry_date' => $this->faker->date(),
            'class_level' => $this->randomClassLevelSlug(),
            'address' => $this->faker->address(),
            'profile_completed_at' => now(),
        ]);
    }

    public function incompleteProfile(): static
    {
        return $this->state(fn (array $attributes) => [
            'class_level' => null,
            'address' => null,
            'profile_completed_at' => null,
        ]);
    }

    public function graduated(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Student::STATUS_GRADUATED,
        ]);
    }

    public function transferred(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Student::STATUS_TRANSFERRED,
        ]);
    }

    public function withdrawn(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Student::STATUS_WITHDRAWN,
        ]);
    }
}
