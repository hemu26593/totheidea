<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ActorSource;
use App\Models\Enrollment;
use App\Models\TimeGridEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimeGridEntry>
 *
 * The activity is derived from a counter rather than a faker unique() pool:
 * UNIQUE (enrollment_id, year, quarter, activity) means a small random pool
 * collides, and a finite unique() pool exhausts across a full suite run.
 */
class TimeGridEntryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'enrollment_id' => Enrollment::factory(),
            'year' => 2026,
            'quarter' => 1,
            'activity' => fn (array $attributes): string => 'Activity '.(1 + TimeGridEntry::query()
                ->where('enrollment_id', $attributes['enrollment_id'])
                ->where('year', $attributes['year'])
                ->where('quarter', $attributes['quarter'])
                ->count()),
            'planned_hours' => 40,
            'actual_hours' => null,
            'source' => ActorSource::InternalUser,
            'created_by' => User::factory(),
        ];
    }

    public function inQuarter(int $quarter): static
    {
        return $this->state(fn (): array => ['quarter' => $quarter]);
    }
}
