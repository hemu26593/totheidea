<?php

declare(strict_types=1);

namespace App\Domain\Programme;

use App\Enums\AuditAction;
use App\Exceptions\CustomerIsolationException;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * All write operations on enrolments - the ownership spine.
 *
 * Capacity is checked here rather than in the database because it is a count
 * across rows. The duplicate rule is enforced by both: UNIQUE (customer_id,
 * batch_id) is the guarantee, and the check here is the readable error.
 */
class EnrollmentService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function enrol(Customer $customer, Batch $batch, User $actor, ?string $paymentDueDate = null): Enrollment
    {
        return DB::transaction(function () use ($customer, $batch, $actor, $paymentDueDate): Enrollment {
            if ($this->alreadyEnrolled($customer, $batch)) {
                throw new RuntimeException(
                    "Customer {$customer->getKey()} is already enrolled in batch {$batch->getKey()}."
                );
            }

            $this->assertCapacityRemains($batch);

            $enrollment = new Enrollment([
                'customer_id' => $customer->getKey(),
                'batch_id' => $batch->getKey(),
                'payment_due_date' => $paymentDueDate,
            ]);
            $enrollment->forceFill([
                'status' => 'enrolled',
                'enrolled_at' => now(),
            ])->save();

            $this->audit->log(
                AuditAction::EnrollmentCreated,
                $enrollment,
                null,
                ['customer_id' => $customer->getKey(), 'batch_id' => $batch->getKey()],
                $actor,
            );

            return $enrollment->fresh();
        });
    }

    public function withdraw(Enrollment $enrollment, string $reason, User $actor): Enrollment
    {
        return DB::transaction(function () use ($enrollment, $reason, $actor): Enrollment {
            $before = ['status' => $enrollment->status];

            $enrollment->forceFill([
                'status' => 'withdrawn',
                'withdrawn_at' => now(),
                'withdrawal_reason' => $reason,
            ])->save();

            $this->audit->log(
                AuditAction::EnrollmentWithdrawn,
                $enrollment,
                $before,
                ['status' => 'withdrawn', 'withdrawal_reason' => $reason],
                $actor,
            );

            return $enrollment->fresh();
        });
    }

    public function complete(Enrollment $enrollment, User $actor): Enrollment
    {
        return DB::transaction(function () use ($enrollment, $actor): Enrollment {
            $before = ['status' => $enrollment->status];

            $enrollment->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
            ])->save();

            $this->audit->log(
                AuditAction::EnrollmentCompleted,
                $enrollment,
                $before,
                ['status' => 'completed'],
                $actor,
            );

            return $enrollment->fresh();
        });
    }

    /**
     * Guard against a caller pairing an enrolment with someone else's customer.
     * Never trust a customer_id supplied alongside an enrollment_id.
     */
    public function assertBelongsTo(Enrollment $enrollment, Customer $customer): void
    {
        if ((int) $enrollment->customer_id !== (int) $customer->getKey()) {
            throw CustomerIsolationException::mismatch(
                'enrolment '.$enrollment->getKey(),
                'customer '.$customer->getKey(),
                'customer '.$enrollment->customer_id,
            );
        }
    }

    private function alreadyEnrolled(Customer $customer, Batch $batch): bool
    {
        return Enrollment::query()
            ->where('customer_id', $customer->getKey())
            ->where('batch_id', $batch->getKey())
            ->exists();
    }

    private function assertCapacityRemains(Batch $batch): void
    {
        if ($batch->capacity === null) {
            return;
        }

        $taken = Enrollment::query()->where('batch_id', $batch->getKey())->count();

        if ($taken >= $batch->capacity) {
            throw new RuntimeException("Batch {$batch->getKey()} is at capacity ({$batch->capacity}).");
        }
    }
}
