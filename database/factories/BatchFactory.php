<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Batch;
use App\Models\Program;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Batch>
 */
class BatchFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'program_id' => Program::factory(),
            'name' => 'Vadodara — '.fake()->monthName().' '.fake()->year(),
            'code' => 'B-'.fake()->unique()->bothify('##??'),
            'starts_on' => now()->toDateString(),
            'ends_on' => null,
            'capacity' => null,
            'status' => 'planned',
        ];
    }

    public function withCapacity(int $capacity): static
    {
        return $this->state(fn (): array => ['capacity' => $capacity]);
    }
}
