<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Program;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Program>
 */
class ProgramFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Business Mastery Program',
            'code' => 'BMP-'.fake()->unique()->bothify('##??'),
            'description' => null,
            'session_count' => 6,
            'status' => 'active',
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (): array => [
            'status' => 'archived',
            'archived_at' => now(),
        ]);
    }
}
