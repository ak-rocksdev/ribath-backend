<?php

namespace Database\Factories;

use App\Models\CashBookCategory;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

class CashBookCategoryFactory extends Factory
{
    protected $model = CashBookCategory::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'name' => $this->faker->unique()->randomElement([
                'SPP', 'Donasi', 'Wakaf Tunai', 'Gaji', 'Listrik', 'Air',
                'Konsumsi', 'Transportasi', 'Perbaikan', 'Pengadaan ATK',
            ]).' '.$this->faker->randomNumber(3),
            'is_system' => false,
            'is_active' => true,
        ];
    }

    public function saldoAwal(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => CashBookCategory::SYSTEM_NAME_SALDO_AWAL,
            'is_system' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
