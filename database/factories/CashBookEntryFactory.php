<?php

namespace Database\Factories;

use App\Models\CashBookCategory;
use App\Models\CashBookEntry;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CashBookEntryFactory extends Factory
{
    protected $model = CashBookEntry::class;

    public function definition(): array
    {
        $school = School::factory();

        return [
            'school_id' => $school,
            'transaction_date' => $this->faker->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
            'type' => $this->faker->randomElement(CashBookEntry::TYPES),
            'category_id' => CashBookCategory::factory()->for($school, 'school'),
            'description' => $this->faker->sentence(6),
            'counterparty' => $this->faker->optional()->name(),
            'amount' => $this->faker->numberBetween(10_000, 5_000_000),
            'proof_file_path' => null,
            'proof_file_mime' => null,
            'created_by' => User::factory(),
            'updated_by' => null,
        ];
    }

    public function in(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => CashBookEntry::TYPE_IN,
        ]);
    }

    public function out(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => CashBookEntry::TYPE_OUT,
        ]);
    }

    public function withProof(string $path = 'cash_book/test/test.pdf', string $mime = 'application/pdf'): static
    {
        return $this->state(fn (array $attributes) => [
            'proof_file_path' => $path,
            'proof_file_mime' => $mime,
        ]);
    }
}
