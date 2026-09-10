<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use App\Exceptions\CustomerIsolationException;
use App\Models\Customer;
use App\Models\Enrollment;
use App\Models\HrPolicyAcknowledgement;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves which customer owns a polymorphic subject.
 *
 * documents and notes attach to a subject rather than to a customer, so a
 * database foreign key cannot express their isolation. This is where that
 * resolution lives, in one place, so every polymorphic attachment resolves
 * ownership the same way.
 *
 * It FAILS CLOSED. A subject whose customer cannot be established is refused
 * rather than assumed safe, so a later phase adding an attachable type must
 * make it resolvable deliberately.
 */
class SubjectOwnership
{
    /**
     * The id of the customer owning this subject.
     */
    public function customerIdFor(Model $subject): int
    {
        if ($subject instanceof Customer) {
            return (int) $subject->getKey();
        }

        // Enrolment-owned subjects resolve through the spine, which is the
        // path most customer-owned records take.
        $enrollmentId = $subject->getAttribute('enrollment_id');

        if ($enrollmentId !== null) {
            $enrollment = $subject instanceof Enrollment
                ? $subject
                : Enrollment::query()->find($enrollmentId);

            if ($enrollment !== null) {
                return (int) $enrollment->customer_id;
            }
        }

        if ($subject instanceof Enrollment) {
            return (int) $subject->customer_id;
        }

        // An acknowledgement carries neither a customer nor an enrolment: it
        // inherits its isolation through the policy it signs off. Resolved
        // deliberately here rather than by adding a denormalised column,
        // because the policy is the only thing that decides whose it is.
        if ($subject instanceof HrPolicyAcknowledgement) {
            $policy = $subject->hrPolicy()->first();

            if ($policy === null) {
                throw CustomerIsolationException::unverifiableSubject($subject->getMorphClass());
            }

            return (int) $policy->customer_id;
        }

        $customerId = $subject->getAttribute('customer_id');

        if ($customerId !== null) {
            return (int) $customerId;
        }

        throw CustomerIsolationException::unverifiableSubject($subject->getMorphClass());
    }

    /**
     * Assert that a subject belongs to the named customer.
     *
     * Never trust a customer_id supplied alongside a subject id: verify the
     * subject and compare.
     */
    public function assertBelongsTo(Model $subject, Customer $customer): void
    {
        $resolved = $this->customerIdFor($subject);

        if ($resolved !== (int) $customer->getKey()) {
            throw CustomerIsolationException::mismatch(
                'subject '.$subject->getMorphClass().' '.$subject->getKey(),
                'customer '.$customer->getKey(),
                'customer '.$resolved,
            );
        }
    }
}
