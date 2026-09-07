<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GrantAbility;
use App\Models\AccessGrant;
use App\Models\Customer;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccessGrant>
 *
 * The factory stores a hash like production does. Tests that need a usable
 * plaintext token issue through AccessGrantService instead, which is the only
 * place a plaintext token ever exists.
 */
class AccessGrantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'token_hash' => hash('sha256', bin2hex(random_bytes(16))),
            'ability' => GrantAbility::AcceptTerms,
            'subject_type' => (new Enrollment)->getMorphClass(),
            // The enrolment and subject are derived from the customer so a
            // factory-built grant is internally coherent: a grant whose
            // customer and enrolment disagree could never be issued.
            'customer_id' => Customer::factory(),
            'enrollment_id' => fn (array $attributes): int => Enrollment::factory()
                ->create(['customer_id' => $attributes['customer_id']])->getKey(),
            'subject_id' => fn (array $attributes): int => (int) $attributes['enrollment_id'],
            'single_use' => true,
            'max_uses' => 1,
            'use_count' => 0,
            'issued_by' => User::factory(),
            'issued_at' => now(),
            'expires_at' => now()->addDays(7),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'issued_at' => now()->subDays(10),
            'expires_at' => now()->subDay(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => ['revoked_at' => now()]);
    }

    public function exhausted(): static
    {
        return $this->state(fn (): array => ['max_uses' => 1, 'use_count' => 1]);
    }
}
