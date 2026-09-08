<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FormTemplate;
use App\Models\SessionTemplate;
use App\Models\SessionTemplateForm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SessionTemplateForm>
 */
class SessionTemplateFormFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'session_template_id' => SessionTemplate::factory(),
            'form_template_id' => FormTemplate::factory(),
            'is_required' => true,
            'position' => 0,
        ];
    }

    public function optional(): static
    {
        return $this->state(fn (): array => ['is_required' => false]);
    }
}
