<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ActorSource;
use App\Models\Customer;
use App\Models\Position;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Position>
 *
 * Root by default: parent_position_id is null, so a factory-built position can
 * never accidentally hang under another business's chart. A test that needs a
 * parent supplies one from the same customer through the reportingTo() state.
 *
 * holder_name is a plain name string. There is deliberately no state that
 * associates it with a User.
 */
class PositionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'parent_position_id' => null,
            'title' => $this->faker->jobTitle(),
            'holder_name' => $this->faker->name(),
            'role_description' => $this->faker->sentence(),
            'kra' => $this->faker->sentence(),
            'source' => ActorSource::InternalUser,
            'created_by' => User::factory(),
        ];
    }

    /**
     * Report to an existing position, inheriting its business so the pair is
     * coherent by construction.
     */
    public function reportingTo(Position $parent): static
    {
        return $this->state(fn (): array => [
            'customer_id' => $parent->customer_id,
            'parent_position_id' => $parent->getKey(),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['archived_at' => now()]);
    }
}
