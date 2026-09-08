<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiPromptStatus;
use App\Models\AiPromptVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiPromptVersion>
 *
 * The default template carries PLACEHOLDERS ONLY, because a fixture that
 * embedded a business's name would model the exact mistake the table forbids.
 *
 * version_number is derived from the rows already present for the key rather
 * than drawn from a pool: UNIQUE (key, version_number) is a real constraint,
 * and a faker sequence would exhaust and collide across a test suite.
 */
class AiPromptVersionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'key' => 'form_draft',
            'version_number' => fn (array $attributes): int => 1 + (int) AiPromptVersion::query()
                ->where('key', $attributes['key'] ?? 'form_draft')
                ->max('version_number'),
            'template' => 'Propose a short intake form for {{ business_name }}.',
            'model_identifier' => null,
            'output_schema' => null,
            'status' => AiPromptStatus::Draft,
            'published_at' => null,
            'created_by' => User::factory(),
        ];
    }

    public function forKey(string $key): static
    {
        return $this->state(fn (): array => ['key' => $key]);
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    public function withSchema(array $schema): static
    {
        return $this->state(fn (): array => ['output_schema' => $schema]);
    }

    /**
     * A published prompt, written directly.
     *
     * Publishing normally goes through PromptVersionService; this state
     * exists so a test can arrange a published prompt without also exercising
     * the publishing path, and it deliberately does not archive anything.
     */
    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => AiPromptStatus::Published,
            'published_at' => now(),
        ]);
    }
}
