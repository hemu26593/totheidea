<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\MmdTarget;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MmdTarget>
 *
 * set_by is always a real user: setting a target is an internal act by
 * construction, and there is no state that makes it anything else.
 */
class MmdTargetFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'enrollment_id' => Enrollment::factory(),
            'metric' => 'sales_closed_value',
            'period_type' => MmdTarget::PERIOD_MONTHLY,
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'target_value' => 50000,
            'set_by' => User::factory(),
            'set_at' => now(),
        ];
    }

    public function forMetric(string $metric): static
    {
        return $this->state(fn (): array => ['metric' => $metric]);
    }
}
