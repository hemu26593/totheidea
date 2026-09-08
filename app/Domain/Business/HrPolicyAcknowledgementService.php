<?php

declare(strict_types=1);

namespace App\Domain\Business;

use App\Enums\ActorSource;
use App\Enums\AuditAction;
use App\Exceptions\CustomerIsolationException;
use App\Models\AccessGrant;
use App\Models\HrPolicy;
use App\Models\HrPolicyAcknowledgement;
use App\Models\Position;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Recording that a named person signed off on a policy.
 *
 * INVARIANT I19: THE POSITION AND THE POLICY MUST BE THE SAME BUSINESS. Both
 * ids arrive from the caller, so both are resolved and compared here rather
 * than trusted. Without it, one business's staff could be recorded as having
 * acknowledged another's policy, and the compliance tracker would read as
 * perfectly normal while being wrong.
 *
 * ONE SIGN-OFF PER POSITION PER POLICY, enforced by the database. Duplicates
 * would inflate the compliance tracker, which is the single number this table
 * exists to produce.
 *
 * A SIGN-OFF IS BOUND TO WHAT WAS SIGNED. A policy is only acknowledgeable
 * while published; a superseded version keeps the acknowledgements it already
 * has, because those people did read those words. Re-acknowledging the
 * revision is a new row against the new policy.
 *
 * External sign-off goes through the EXISTING scoped-grant architecture: the
 * caller passes the AccessGrant it already redeemed, and the row records
 * source = external_grant. No customer account, no second access mechanism.
 */
class HrPolicyAcknowledgementService
{
    public function __construct(
        private readonly BusinessOwnership $ownership,
        private readonly AuditLogger $audit,
    ) {}

    public function record(
        HrPolicy $policy,
        Position $position,
        string $acknowledgedName,
        ?User $actor = null,
        ?AccessGrant $grant = null,
    ): HrPolicyAcknowledgement {
        $this->assertNameIsPresent($acknowledgedName);
        $this->assertSameBusiness($policy, $position);
        $this->assertPolicyIsAcknowledgeable($policy);
        $this->assertPositionIsAcknowledging($position);
        $this->ownership->assertGrantMatchesCustomer($grant, $policy->customer);
        $this->assertNotAlreadyAcknowledged($policy, $position);

        return DB::transaction(function () use ($policy, $position, $acknowledgedName, $actor, $grant): HrPolicyAcknowledgement {
            $acknowledgement = new HrPolicyAcknowledgement;
            $acknowledgement->forceFill([
                'hr_policy_id' => $policy->getKey(),
                // WHO signed - a position, never a user.
                'position_id' => $position->getKey(),
                'acknowledged_name' => trim($acknowledgedName),
                'acknowledged_at' => now(),
                'source' => $this->ownership->resolveSource($actor, $grant),
                'created_by' => $actor?->getKey(),
                'access_grant_id' => $grant?->getKey(),
            ])->save();

            $this->audit->log(
                AuditAction::HrPolicyAcknowledged,
                $acknowledgement->fresh(),
                null,
                [
                    'hr_policy_id' => $policy->getKey(),
                    'position_id' => $position->getKey(),
                    'acknowledged_name' => trim($acknowledgedName),
                    'customer_id' => $policy->customer_id,
                ],
                $actor,
                $grant !== null ? ActorSource::ExternalGrant : null,
                $grant,
            );

            return $acknowledgement->fresh();
        });
    }

    /**
     * Who has signed this policy.
     *
     * @return Collection<int, HrPolicyAcknowledgement>
     */
    public function forPolicy(HrPolicy $policy): Collection
    {
        return HrPolicyAcknowledgement::query()
            ->where('hr_policy_id', $policy->getKey())
            ->orderBy('id')
            ->get();
    }

    /**
     * The compliance tracker for one policy: who has signed, and who has not.
     *
     * Derived on demand from the positions and the acknowledgements. Nothing
     * is stored, so archiving a position or adding a new one is reflected
     * immediately rather than needing a recount.
     *
     * @return array{policy_id: int, acknowledged: int, outstanding: int, outstanding_positions: array<int, int>}
     */
    public function complianceFor(HrPolicy $policy): array
    {
        $positionIds = Position::query()
            ->where('customer_id', $policy->customer_id)
            ->whereNull('archived_at')
            ->pluck('id');

        $signed = HrPolicyAcknowledgement::query()
            ->where('hr_policy_id', $policy->getKey())
            ->pluck('position_id');

        $outstanding = $positionIds
            ->reject(fn (int $id): bool => $signed->contains($id))
            ->values();

        return [
            'policy_id' => (int) $policy->getKey(),
            'acknowledged' => $signed->count(),
            'outstanding' => $outstanding->count(),
            'outstanding_positions' => $outstanding->map(fn (int $id): int => $id)->all(),
        ];
    }

    public function hasAcknowledged(HrPolicy $policy, Position $position): bool
    {
        return HrPolicyAcknowledgement::query()
            ->where('hr_policy_id', $policy->getKey())
            ->where('position_id', $position->getKey())
            ->exists();
    }

    /**
     * INVARIANT I19.
     */
    public function assertSameBusiness(HrPolicy $policy, Position $position): void
    {
        if ((int) $policy->customer_id !== (int) $position->customer_id) {
            throw CustomerIsolationException::mismatch(
                'position '.$position->getKey(),
                'customer '.$policy->customer_id,
                'customer '.$position->customer_id,
            );
        }
    }

    /**
     * A draft has not been put in force, and an archived policy is no longer
     * in force. A SUPERSEDED one keeps the sign-offs it already has - those
     * people did read those words - but collects no new ones.
     */
    private function assertPolicyIsAcknowledgeable(HrPolicy $policy): void
    {
        if (! $policy->isAcknowledgeable()) {
            throw new RuntimeException(sprintf(
                'HR policy %d is [%s]%s; only a published policy can be acknowledged.',
                $policy->getKey(),
                $policy->status,
                $policy->archived_at === null ? '' : ' and archived',
            ));
        }
    }

    private function assertPositionIsAcknowledging(Position $position): void
    {
        if ($position->isArchived()) {
            throw new RuntimeException(sprintf(
                'Position %d is archived and cannot sign a policy.',
                $position->getKey(),
            ));
        }
    }

    /**
     * The service-level form of UNIQUE (hr_policy_id, position_id), so the
     * caller gets an intelligible message rather than a constraint violation.
     */
    private function assertNotAlreadyAcknowledged(HrPolicy $policy, Position $position): void
    {
        if ($this->hasAcknowledged($policy, $position)) {
            throw new RuntimeException(sprintf(
                'Position %d has already acknowledged HR policy %d. A second sign-off would '
                .'inflate the compliance tracker; a re-acknowledgement belongs to a new policy '
                .'version.',
                $position->getKey(),
                $policy->getKey(),
            ));
        }
    }

    private function assertNameIsPresent(string $name): void
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('An acknowledgement needs the name of the person signing.');
        }
    }
}
