<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NotificationChannel;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Enrollment;
use App\Models\NotificationDispatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationDispatch>
 *
 * The contact and enrolment are derived from one customer, so a factory-built
 * dispatch is internally coherent - a dispatch addressed to another business's
 * contact could never be produced by the scheduler.
 *
 * dedupe_key is derived from the row's own identity rather than a random
 * string, so two factory calls with the same arguments collide exactly as
 * production would.
 */
class NotificationDispatchFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'trigger_key' => 'session_upcoming',
            'channel' => NotificationChannel::Email,
            'recipient_type' => NotificationDispatch::RECIPIENT_CONTACT,
            'customer_id' => Customer::factory(),
            'enrollment_id' => fn (array $attributes): int => Enrollment::factory()
                ->create(['customer_id' => $attributes['customer_id']])->getKey(),
            'recipient_id' => fn (array $attributes): int => CustomerContact::factory()
                ->create(['customer_id' => $attributes['customer_id']])->getKey(),
            'address_used' => fn (array $attributes): string => (string) CustomerContact::query()
                ->findOrFail($attributes['recipient_id'])->email,
            'scheduled_for' => now(),
            'status' => NotificationDispatch::STATUS_PENDING,
            'attempts' => 0,
            'dedupe_key' => fn (array $attributes): string => sprintf(
                '%s:enrollment:%d:recipient:contact:%d:%s',
                $attributes['trigger_key'],
                $attributes['enrollment_id'],
                $attributes['recipient_id'],
                now()->format('Y-m-d-His-u'),
            ),
        ];
    }

    public function sent(): static
    {
        return $this->state(fn (): array => [
            'status' => NotificationDispatch::STATUS_SENT,
            'sent_at' => now(),
            'attempts' => 1,
            'last_attempted_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => NotificationDispatch::STATUS_FAILED,
            'attempts' => 1,
            'last_attempted_at' => now(),
            'error' => 'No delivery driver is configured for channel [email].',
        ]);
    }
}
