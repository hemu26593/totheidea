<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ActorSource;
use App\Models\HrPolicy;
use App\Models\HrPolicyAcknowledgement;
use App\Models\Position;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HrPolicyAcknowledgement>
 *
 * The position is derived from the policy's business, so the factory satisfies
 * I19 by construction: it cannot produce the cross-customer sign-off the
 * invariant exists to prevent. A test that needs a violating pair builds it
 * deliberately.
 */
class HrPolicyAcknowledgementFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'hr_policy_id' => HrPolicy::factory()->published(),
            'position_id' => fn (array $attributes): int => Position::factory()->create([
                'customer_id' => HrPolicy::query()
                    ->findOrFail($attributes['hr_policy_id'])->customer_id,
            ])->getKey(),
            'acknowledged_name' => $this->faker->name(),
            'acknowledged_at' => now(),
            'source' => ActorSource::InternalUser,
            'created_by' => User::factory(),
        ];
    }
}
