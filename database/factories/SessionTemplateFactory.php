<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Program;
use App\Models\SessionTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SessionTemplate>
 */
class SessionTemplateFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'program_id' => Program::factory(),
            // Derived rather than random: UNIQUE (program_id, sequence) means
            // a random value collides as soon as two templates share a
            // program, and a faker unique() pool of six exhausts immediately.
            'sequence' => fn (array $attributes): int => 1 + (int) SessionTemplate::query()
                ->where('program_id', $attributes['program_id'])
                ->max('sequence'),
            'title' => $this->faker->words(3, true),
            'theme' => $this->faker->words(2, true),
            'objectives' => $this->faker->sentence(),
        ];
    }

    /**
     * Not named sequence(): Factory::sequence() already means something else,
     * and overriding it would break every caller that expects the framework's.
     */
    public function atSequence(int $sequence): static
    {
        return $this->state(fn (): array => ['sequence' => $sequence]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['archived_at' => now()]);
    }
}
