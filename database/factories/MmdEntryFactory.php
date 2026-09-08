<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ActorSource;
use App\Models\Customer;
use App\Models\Enrollment;
use App\Models\MmdEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MmdEntry>
 *
 * The enrolment is derived from the customer, so a factory-built entry can
 * never be attributed to another business's programme run.
 *
 * No unique constraint is assumed on (customer_id, entry_date):
 * [B1 CLIENT DECISION] is unresolved, so the factory can legitimately produce
 * two rows for one day and no test should read that as a bug.
 */
class MmdEntryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'enrollment_id' => fn (array $attributes): int => Enrollment::factory()
                ->create(['customer_id' => $attributes['customer_id']])->getKey(),
            'entry_date' => now()->toDateString(),
            'fund_in' => 1000,
            'fund_out' => 400,
            'enquiries_new' => 5,
            'enquiries_repeat' => 2,
            'enquiries_referral' => 1,
            'sales_closed_count' => 3,
            'sales_closed_value' => 2500,
            'production' => 1800,
            'recorded_at' => now(),
            'lock_version' => 0,
            'source' => ActorSource::InternalUser,
            'created_by' => User::factory(),
        ];
    }

    public function on(string $date): static
    {
        return $this->state(fn (): array => ['entry_date' => $date]);
    }
}
