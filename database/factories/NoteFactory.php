<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Note;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Note>
 *
 * author_id is always a User. There is no state producing a note authored by
 * anything else, because nothing else may author one.
 */
class NoteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'notable_type' => (new Customer)->getMorphClass(),
            'notable_id' => Customer::factory(),
            'body' => fake()->sentence(),
            'is_internal' => true,
            'author_id' => User::factory(),
        ];
    }

    public function customerVisible(): static
    {
        return $this->state(fn (): array => ['is_internal' => false]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['archived_at' => now()]);
    }
}
