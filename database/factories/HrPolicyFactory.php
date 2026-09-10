<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ActorSource;
use App\Models\Customer;
use App\Models\HrPolicy;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HrPolicy>
 *
 * Draft by default. Publication goes through HrPolicyService, which holds the
 * permission check and the audit entry, so no factory state fabricates a
 * published policy around it.
 *
 * published() exists only for tests that need a policy already in force as a
 * starting point - it sets the status and the timestamp together, which is
 * what the CHECK on published_at requires.
 */
class HrPolicyFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'title' => $this->faker->sentence(3),
            'body' => $this->faker->paragraph(),
            'status' => HrPolicy::STATUS_DRAFT,
            'version_label' => 'v1',
            'source' => ActorSource::InternalUser,
            'created_by' => User::factory(),
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => HrPolicy::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }

    public function superseded(): static
    {
        return $this->state(fn (): array => [
            'status' => HrPolicy::STATUS_SUPERSEDED,
            'published_at' => now()->subMonth(),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['archived_at' => now()]);
    }
}
