<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\FundPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FundPlan>
 *
 * The month is derived rather than random: UNIQUE (enrollment_id, year, month)
 * means a random month collides as soon as two plans share an enrolment, and a
 * faker unique() pool of twelve exhausts across a full suite run.
 */
class FundPlanFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'enrollment_id' => Enrollment::factory(),
            'year' => 2026,
            'month' => fn (array $attributes): int => 1 + FundPlan::query()
                ->where('enrollment_id', $attributes['enrollment_id'])
                ->where('year', $attributes['year'])
                ->count(),
            'status' => FundPlan::STATUS_DRAFT,
            'budget_total' => 100000,
            'created_by' => User::factory(),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => FundPlan::STATUS_APPROVED,
            'approved_by' => User::factory(),
            'approved_at' => now(),
        ]);
    }

    public function forMonth(int $year, int $month): static
    {
        return $this->state(fn (): array => ['year' => $year, 'month' => $month]);
    }
}
