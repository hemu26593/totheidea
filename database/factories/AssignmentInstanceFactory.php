<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AssignmentInstance;
use App\Models\AssignmentTemplate;
use App\Models\SessionInstance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssignmentInstance>
 *
 * title and instructions are set directly rather than read from a template,
 * which is exactly how release works: the wording on an instance is a
 * snapshot, not a join.
 */
class AssignmentInstanceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'session_instance_id' => SessionInstance::factory(),
            'assignment_template_id' => null,
            'title' => $this->faker->sentence(4),
            'instructions' => $this->faker->paragraph(),
            'due_at' => now()->addWeek(),
            'requires_attachment' => false,
            'status' => AssignmentInstance::STATUS_DRAFT,
        ];
    }

    public function released(): static
    {
        return $this->state(fn (): array => [
            'status' => AssignmentInstance::STATUS_RELEASED,
            'released_at' => now(),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (): array => ['status' => AssignmentInstance::STATUS_CLOSED]);
    }

    /**
     * Derive the template from the instance's session, so the factory cannot
     * pair an assignment with a session it does not belong to.
     */
    public function fromTemplate(): static
    {
        return $this->state(fn (array $attributes): array => [
            'assignment_template_id' => AssignmentTemplate::factory()->create([
                'session_template_id' => SessionInstance::query()
                    ->findOrFail($attributes['session_instance_id'])->session_template_id,
            ])->getKey(),
        ]);
    }
}
