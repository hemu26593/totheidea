<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Batch;
use App\Models\SessionInstance;
use App\Models\SessionTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SessionInstance>
 *
 * The session template is derived from the batch's program rather than
 * generated independently, so the factory can never produce the cross-program
 * row that invariant I17 exists to prevent.
 */
class SessionInstanceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'batch_id' => Batch::factory(),
            'session_template_id' => fn (array $attributes): int => SessionTemplate::factory()->create([
                'program_id' => Batch::query()->findOrFail($attributes['batch_id'])->program_id,
            ])->getKey(),
            'planned_date' => now()->addWeek()->toDateString(),
            'status' => SessionInstance::STATUS_SCHEDULED,
        ];
    }

    /**
     * A session that has actually been held - the only kind attendance may be
     * marked against (I16).
     */
    public function held(): static
    {
        return $this->state(fn (): array => [
            'status' => SessionInstance::STATUS_COMPLETED,
            'actual_date' => now()->toDateString(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => ['status' => SessionInstance::STATUS_CANCELLED]);
    }
}
