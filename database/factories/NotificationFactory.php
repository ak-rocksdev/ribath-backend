<?php

namespace Database\Factories;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'school_id' => null,
            'type' => fake()->randomElement(Notification::TYPES),
            'title' => fake()->sentence(4),
            'message' => fake()->paragraph(1),
            'priority' => fake()->randomElement(Notification::PRIORITIES),
            'category' => fake()->randomElement(Notification::CATEGORIES),
            'metadata' => null,
            'is_read' => false,
            'read_at' => null,
            'expires_at' => null,
            'action_url' => null,
            'action_label' => null,
        ];
    }

    public function read(): static
    {
        return $this->state(fn () => [
            'is_read' => true,
            'read_at' => now(),
        ]);
    }

    public function unread(): static
    {
        return $this->state(fn () => [
            'is_read' => false,
            'read_at' => null,
        ]);
    }

    public function urgent(): static
    {
        return $this->state(fn () => [
            'priority' => Notification::PRIORITY_URGENT,
        ]);
    }

    public function psb(): static
    {
        return $this->state(fn () => [
            'type' => Notification::TYPE_INFO,
            'category' => Notification::CATEGORY_PSB,
            'action_url' => '/admin/pendaftaran-masuk',
            'action_label' => 'Lihat Pendaftaran',
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'expires_at' => now()->subDay(),
        ]);
    }

    public function withMetadata(array $metadata): static
    {
        return $this->state(fn () => [
            'metadata' => $metadata,
        ]);
    }
}
