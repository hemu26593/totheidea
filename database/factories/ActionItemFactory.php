<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ActorSource;
use App\Models\ActionItem;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActionItem>
 *
 * Manual by default: both source columns null. An auto-fed item is built
 * through ActionItemFeeder, which is where the idempotency lookup lives.
 */
class ActionItemFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'enrollment_id' => Enrollment::factory(),
            'title' => $this->faker->sentence(4),
            'description' => $this->faker->sentence(),
            'source_type' => null,
            'source_id' => null,
            'due_date' => null,
            'status' => ActionItem::STATUS_OPEN,
            'priority' => ActionItem::PRIORITY_NORMAL,
            'position' => 0,
            'source' => ActorSource::InternalUser,
            'created_by' => User::factory(),
        ];
    }

    public function done(): static
    {
        return $this->state(fn (): array => [
            'status' => ActionItem::STATUS_DONE,
            'completed_at' => now(),
        ]);
    }

    public function dueOn(string $date): static
    {
        return $this->state(fn (): array => ['due_date' => $date]);
    }
}
