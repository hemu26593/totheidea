<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FormTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormTemplate>
 *
 * Global by default: most templates are, and a customer-scoped one is the
 * deliberate exception.
 */
class FormTemplateFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'customer_id' => null,
            'key' => 'form_'.fake()->unique()->bothify('####'),
            'name' => fake()->words(3, true),
            'is_scored' => false,
            'status' => 'active',
        ];
    }

    public function scored(): static
    {
        return $this->state(fn (): array => ['is_scored' => true]);
    }

    public function forCustomer(int $customerId): static
    {
        return $this->state(fn (): array => ['customer_id' => $customerId]);
    }
}
