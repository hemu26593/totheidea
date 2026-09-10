<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SkillArea;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SkillArea> */
class SkillAreaFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'key' => 'area_'.fake()->unique()->bothify('####'),
            'name' => fake()->words(2, true),
            'position' => 0,
        ];
    }
}
