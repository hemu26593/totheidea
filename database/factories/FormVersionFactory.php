<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FormTemplate;
use App\Models\FormVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormVersion>
 *
 * Draft by default. Publishing goes through FormPublishingService, which
 * holds the one-published-version invariant, so no factory state fabricates a
 * published version around it.
 */
class FormVersionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'form_template_id' => FormTemplate::factory(),
            'version_number' => 1,
            'status' => 'draft',
        ];
    }
}
