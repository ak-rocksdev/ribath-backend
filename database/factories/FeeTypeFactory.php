<?php

namespace Database\Factories;

use App\Models\CashBookCategory;
use App\Models\FeeType;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class FeeTypeFactory extends Factory
{
    protected $model = FeeType::class;

    public function definition(): array
    {
        $school = School::factory();

        return [
            'school_id' => $school,
            'code' => 'fee_'.Str::lower(Str::random(6)),
            'label' => $this->faker->randomElement([
                'SPP Bulanan', 'Uang Gedung', 'Uang Masuk', 'Konsumsi',
                'Iuran Kebersihan', 'Daftar Ulang',
            ]),
            'default_cadence' => FeeType::CADENCE_MONTHLY,
            // Default to a fresh category tied to the same school. Callers can
            // override via ->for($category, 'cashBookCategory') to share one.
            'cash_book_category_id' => CashBookCategory::factory()->state(function (array $attrs) use ($school) {
                return ['school_id' => $school instanceof Factory ? null : $school];
            }),
            'is_active' => true,
        ];
    }

    public function forSchool(School $school): static
    {
        return $this->state(fn () => [
            'school_id' => $school->id,
            'cash_book_category_id' => CashBookCategory::factory()->state([
                'school_id' => $school->id,
            ]),
        ]);
    }

    public function withCadence(string $cadence): static
    {
        return $this->state(fn () => ['default_cadence' => $cadence]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
