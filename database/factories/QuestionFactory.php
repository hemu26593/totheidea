<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\QuestionType;
use App\Models\FormSection;
use App\Models\Question;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Question>
 *
 * The section is created FIRST and the version derived from it, so the
 * factory can never produce the cross-version pairing the domain forbids.
 */
class QuestionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'form_section_id' => FormSection::factory(),
            'form_version_id' => fn (array $attributes): int => (int) FormSection::query()
                ->findOrFail($attributes['form_section_id'])->form_version_id,
            'type' => QuestionType::Text,
            'label' => fake()->sentence(),
            'is_required' => false,
            'position' => 0,
        ];
    }

    public function boolean(): static
    {
        return $this->state(fn (): array => ['type' => QuestionType::Boolean]);
    }

    public function scored(float $maxScore = 1.0): static
    {
        return $this->state(fn (): array => ['max_score' => $maxScore]);
    }

    public function withBankKey(string $key): static
    {
        return $this->state(fn (): array => ['bank_key' => $key]);
    }
}
