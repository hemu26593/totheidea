<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AssignmentTemplate;
use App\Models\SessionTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssignmentTemplate>
 */
class AssignmentTemplateFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'session_template_id' => SessionTemplate::factory(),
            'title' => $this->faker->sentence(4),
            'instructions' => $this->faker->paragraph(),
            'default_due_days' => 7,
            'requires_attachment' => false,
            // Derived: UNIQUE (session_template_id, position).
            'position' => fn (array $attributes): int => 1 + (int) AssignmentTemplate::query()
                ->where('session_template_id', $attributes['session_template_id'])
                ->max('position'),
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['archived_at' => now()]);
    }
}
