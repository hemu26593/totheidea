<?php

declare(strict_types=1);

namespace App\Domain\Business;

use App\Enums\ActorSource;
use App\Exceptions\CustomerIsolationException;
use App\Models\AccessGrant;
use App\Models\Customer;
use App\Models\User;

/**
 * The ownership checks the business-systems module shares.
 *
 * These records are owned by the CUSTOMER rather than by an enrolment - an org
 * chart and a set of HR policies are continuous business data that outlive a
 * programme run. So the question asked here is "does this belong to this
 * business?", not "does this belong to this run?".
 *
 * Written once so PositionService, HrPolicyService and
 * HrPolicyAcknowledgementService cannot drift apart. Isolation re-implemented
 * per module is isolation that eventually differs per module.
 */
class BusinessOwnership
{
    /**
     * A grant is scoped to one enrolment, and therefore to one business. It
     * can never be pointed at another.
     */
    public function assertGrantMatchesCustomer(?AccessGrant $grant, ?Customer $customer): void
    {
        if ($grant === null) {
            return;
        }

        if ($customer === null) {
            throw CustomerIsolationException::unverifiableSubject('Customer');
        }

        if ((int) $grant->customer_id !== (int) $customer->getKey()) {
            throw CustomerIsolationException::mismatch(
                'grant '.$grant->getKey(),
                'customer '.$customer->getKey(),
                'customer '.$grant->customer_id,
            );
        }
    }

    /**
     * Guard against a caller pairing a business record with another customer.
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
