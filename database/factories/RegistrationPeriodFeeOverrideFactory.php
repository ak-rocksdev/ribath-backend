<?php

namespace Database\Factories;

use App\Models\FeeType;
use App\Models\RegistrationPeriod;
use App\Models\RegistrationPeriodFeeOverride;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class RegistrationPeriodFeeOverrideFactory extends Factory
{
    protected $model = RegistrationPeriodFeeOverride::class;

    public function definition(): array
    {
        $school = School::factory();

        return [
            'school_id' => $school,
            'registration_period_id' => RegistrationPeriod::factory(),
            'fee_type_id' => FeeType::factory()->withCadence(FeeType::CADENCE_ONCE_AT_ENROLLMENT),
            'amount' => $this->faker->numberBetween(100_000, 1_000_000),
            'reason' => 'Keputusan komite ' . $this->faker->date(),
            'created_by' => User::factory(),
        ];
    }

    public function forPeriod(RegistrationPeriod $period): static
    {
        return $this->state(fn () => [
            'registration_period_id' => $period->id,
            'school_id' => $period->school_id,
        ]);
    }

    public function forFeeType(FeeType $feeType): static
    {
        return $this->state(fn () => [
            'fee_type_id' => $feeType->id,
            'school_id' => $feeType->school_id,
        ]);
    }
}
