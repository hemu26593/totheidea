<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ActorSource;
use App\Models\DayPlanItem;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DayPlanItem>
 *
 * customer_id is derived from the enrolment rather than generated
 * independently, so the factory can never produce the mis-scoped row the
 * redundant-copy invariant exists to prevent.
 */
class DayPlanItemFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'enrollment_id' => Enrollment::factory(),
            'customer_id' => fn (array $attributes): int => (int) Enrollment::query()
                ->findOrFail($attributes['enrollment_id'])->customer_id,
            'plan_date' => now()->toDateString(),
            'task' => $this->faker->sentence(4),
            'status' => DayPlanItem::STATUS_PLANNED,
            'position' => 0,
            'source' => ActorSource::InternalUser,
            'created_by' => User::factory(),
        ];
    }

    public function on(string $date): static
    {
        return $this->state(fn (): array => ['plan_date' => $date]);
    }

    public function done(): static
    {
        return $this->state(fn (): array => ['status' => DayPlanItem::STATUS_DONE]);
    }

    public function carriedForward(): static
    {
        return $this->state(fn (): array => ['status' => DayPlanItem::STATUS_CARRIED_FORWARD]);
    }
}
