<?php

namespace Database\Factories;

use App\Models\School;
use App\Models\TimeSlot;
use Illuminate\Database\Eloquent\Factories\Factory;

class TimeSlotFactory extends Factory
{
    protected $model = TimeSlot::class;

    public function definition(): array
    {
        $hour = fake()->unique()->numberBetween(5, 21);
        $endHour = $hour + 1;

        return [
            'school_id' => School::factory(),
            'code' => sprintf('%02d:00-%02d:00', $hour, $endHour),
            'label' => sprintf('%02d:00 - %02d:00', $hour, $endHour),
            'type' => TimeSlot::TYPE_FIXED_CLOCK,
            'start_time' => sprintf('%02d:00', $hour),
            'end_time' => sprintf('%02d:00', $endHour),
            'sort_order' => $hour,
            'is_active' => true,
        ];
    }

    public function prayerBased(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => TimeSlot::TYPE_PRAYER_BASED,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
