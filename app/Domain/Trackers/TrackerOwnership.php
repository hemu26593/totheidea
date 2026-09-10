<?php

declare(strict_types=1);

namespace App\Domain\Trackers;

use App\Enums\ActorSource;
use App\Exceptions\CustomerIsolationException;
use App\Models\AccessGrant;
use App\Models\Customer;
use App\Models\Enrollment;
use App\Models\User;

/**
 * The ownership checks every tracker shares.
 *
 * Five instruments, one set of rules: a grant may only write for the enrolment
 * it was issued against, an enrolment may only be paired with its own
 * customer, and the actor triple is resolved the same way everywhere.
 *
 * Written once so the five services cannot drift apart. Isolation that is
 * re-implemented per module is isolation that eventually differs per module.
 */
class TrackerOwnership
{
    /**
     * A grant is scoped to one enrolment. It can never be pointed at another.
     */
    public function assertGrantMatchesEnrollment(?AccessGrant $grant, Enrollment $enrollment): void
    {
        if ($grant === null) {
            return;
        }

        if ((int) $grant->enrollment_id !== (int) $enrollment->getKey()) {
            throw CustomerIsolationException::mismatch(
                'grant '.$grant->getKey(),
                'enrolment '.$enrollment->getKey(),
                'enrolment '.$grant->enrollment_id,
            );
        }
    }

    /**
     * An enrolment offered alongside a customer must actually belong to it.
     *
     * Used where a record is owned by the CUSTOMER and merely attributed to an
     * enrolment - MMD entries, where business data outlives a programme run.
     */
    public function assertEnrollmentBelongsToCustomer(?Enrollment $enrollment, Customer $customer): void
    {
        if ($enrollment === null) {
            return;
        }

        if ((int) $enrollment->customer_id !== (int) $customer->getKey()) {
            throw CustomerIsolationException::mismatch(
                'enrolment '.$enrollment->getKey(),
                'customer '.$customer->getKey(),
                'customer '.$enrollment->customer_id,
            );
        }
    }

    /**
     * Guard against a caller pairing a tracker record with another customer.
     */
    public function assertBelongsToCustomer(int $recordCustomerId, int $customerId, string $what): void
    {
        if ($recordCustomerId !== $customerId) {
            throw CustomerIsolationException::mismatch(
                $what,
                'customer '.$customerId,
                'customer '.$recordCustomerId,
            );
        }
    }

    public function resolveSource(?User $actor, ?AccessGrant $grant): ActorSource
    {
        return match (true) {
            $grant !== null => ActorSource::ExternalGrant,
            $actor !== null => ActorSource::InternalUser,
            default => ActorSource::System,
        };
    }
}
