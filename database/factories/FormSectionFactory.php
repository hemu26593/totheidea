<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FormSection;
use App\Models\FormVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FormSection> */
class FormSectionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'form_version_id' => FormVersion::factory(),
            'title' => fake()->words(2, true),
            'position' => 0,
        ];
    }
}
