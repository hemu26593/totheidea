<?php

declare(strict_types=1);

namespace App\Domain\Business;

use App\Enums\AuditAction;
use App\Models\AccessGrant;
use App\Models\Customer;
use App\Models\HrPolicy;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * The HR policy library.
 *
 * SUPERSEDE, NEVER EDIT. A published policy's words are frozen. Revising one
 * means drafting a new policy and superseding the old, which leaves every
 * acknowledgement pointing at the exact text its signer read. Editing in place
 * would make historical sign-offs attest to words nobody saw - the same class
 * of mistake as rebinding a form submission to a newer version.
 *
 * PUBLICATION IS PRIVILEGED. Staff holds hr_policies.manage and can draft;
 * it does not hold hr_policies.publish. The refusal is in this service as well
 * as in HrPolicyPolicy, so no job, console command or future controller can
 * publish by forgetting to authorize.
 *
 * Text policies live in body. Files go to the existing documents table -
 * there is no second document system here, and no bespoke versioning engine:
 * supersession IS the versioning.
 */
class HrPolicyService
{
    public function __construct(
        private readonly BusinessOwnership $ownership,
        private readonly AuditLogger $audit,
    ) {}

    public function draft(
        Customer $customer,
        string $title,
        ?string $body = null,
        ?string $versionLabel = null,
        ?User $actor = null,
        ?AccessGrant $grant = null,
    ): HrPolicy {
        $this->assertTitleIsPresent($title);
        $this->ownership->assertGrantMatchesCustomer($grant, $customer);

        return DB::transaction(function () use ($customer, $title, $body, $versionLabel, $actor, $grant): HrPolicy {
            $policy = new HrPolicy;
            $policy->forceFill([
                'customer_id' => $customer->getKey(),
                'title' => $title,
                'body' => $body,
                'status' => HrPolicy::STATUS_DRAFT,
                'version_label' => $versionLabel,
                'source' => $this->ownership->resolveSource($actor, $grant),
                'created_by' => $actor?->getKey(),
                'access_grant_id' => $grant?->getKey(),
            ])->save();

            return $policy->fresh();
        });
    }

    /**
     * Edit a policy that is still a draft.
     *
     * Refuses anything else. The model refuses too, which is what makes it
     * absolute; this refusal exists so the caller gets an explanation rather
     * than a guard exception.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateDraft(HrPolicy $policy, array $attributes, ?User $actor = null): HrPolicy
    {
        if (! $policy->isDraft()) {
            throw new RuntimeException(sprintf(
                'HR policy %d is [%s] and its text is frozen. Draft a new policy and supersede '
                .'this one, so acknowledgements stay bound to the words that were acknowledged.',
                $policy->getKey(),
                $policy->status,
            ));
        }

        $allowed = array_intersect_key($attributes, array_flip(HrPolicy::CONTENT_COLUMNS));

        if (array_key_exists('title', $allowed)) {
            $this->assertTitleIsPresent((string) $allowed['title']);
        }

        return DB::transaction(function () use ($policy, $allowed): HrPolicy {
            $policy->forceFill($allowed)->save();

            return $policy->fresh();
        });
    }

    /**
     * Publish a draft.
     *
     * Requires hr_policies.publish. Staff does not hold it.
     */
    public function publish(HrPolicy $policy, User $actor): HrPolicy
    {
        if (! $actor->can('hr_policies.publish')) {
            throw new RuntimeException(sprintf(
                'User %d cannot publish an HR policy: hr_policies.publish is required, and '
                .'publication is deliberately separate from hr_policies.manage.',
                $actor->getKey(),
            ));
        }

        if (! $policy->isDraft()) {
            throw new RuntimeException(sprintf(
                'HR policy %d is [%s]; only a draft can be published.',
                $policy->getKey(),
                $policy->status,
            ));
        }

        return DB::transaction(function () use ($policy, $actor): HrPolicy {
            $policy->forceFill([
                'status' => HrPolicy::STATUS_PUBLISHED,
                'published_at' => now(),
            ])->save();

            $fresh = $policy->fresh();

            $this->audit->log(
                AuditAction::HrPolicyPublished,
                $fresh,
                ['status' => HrPolicy::STATUS_DRAFT],
                [
                    'status' => HrPolicy::STATUS_PUBLISHED,
                    'title' => $fresh->title,
                    'version_label' => $fresh->version_label,
                    'customer_id' => $fresh->customer_id,
                ],
                $actor,
            );

            return $fresh;
        });
    }

    /**
     * Replace a published policy with a new one.
     *
     * The old policy is marked superseded and points at its replacement; its
     * text is untouched, and every acknowledgement against it stays valid
     * evidence of what that person actually signed.
     *
     * Publishing the replacement is a separate, privileged act - so a member
     * of staff can prepare a revision without being able to put it in force.
     */
    public function supersede(
        HrPolicy $policy,
        string $title,
        ?string $body = null,
        ?string $versionLabel = null,
        ?User $actor = null,
    ): HrPolicy {
        if (! $policy->isPublished()) {
            throw new RuntimeException(sprintf(
                'HR policy %d is [%s]; only a published policy is superseded. A draft is simply '
                .'edited.',
                $policy->getKey(),
                $policy->status,
            ));
        }

        $customer = $policy->customer()->first();

        if ($customer === null) {
            throw new RuntimeException("HR policy {$policy->getKey()} has no resolvable customer.");
        }

        return DB::transaction(function () use ($policy, $customer, $title, $body, $versionLabel, $actor): HrPolicy {
            $replacement = $this->draft($customer, $title, $body, $versionLabel, $actor);

            $policy->forceFill([
                'status' => HrPolicy::STATUS_SUPERSEDED,
                'superseded_by_id' => $replacement->getKey(),
            ])->save();

            return $replacement->fresh();
        });
    }

    public function archive(HrPolicy $policy, User $actor): HrPolicy
    {
        return DB::transaction(function () use ($policy): HrPolicy {
            $policy->forceFill(['archived_at' => now()])->save();

            return $policy->fresh();
        });
    }

    /**
     * The Session 6 policy status tracker for one business.
     *
     * @return Collection<int, HrPolicy>
     */
    public function libraryFor(Customer $customer): Collection
    {
        return HrPolicy::query()
            ->where('customer_id', $customer->getKey())
            ->orderBy('status')
            ->orderBy('id')
            ->get();
    }

    /**
     * The supersession chain, oldest first.
     *
     * @return array<int, HrPolicy>
     */
    public function history(HrPolicy $policy): array
    {
        // Walk back to the earliest version, then forward, so the chain reads
        // in the order it was written.
        $earliest = $policy;
        $seen = [$policy->getKey() => true];

        while (($previous = $earliest->supersedes()->first()) !== null) {
            if (isset($seen[$previous->getKey()])) {
                break;
            }

            $seen[$previous->getKey()] = true;
            $earliest = $previous;
        }

        $chain = [$earliest];
        $cursor = $earliest;

        while (($next = $cursor->supersededBy()->first()) !== null) {
            if (in_array($next->getKey(), array_map(fn (HrPolicy $p): int => (int) $p->getKey(), $chain), true)) {
                break;
            }

            $chain[] = $next;
            $cursor = $next;
        }

        return $chain;
    }

    private function assertTitleIsPresent(string $title): void
    {
        if (trim($title) === '') {
            throw new InvalidArgumentException('An HR policy needs a title.');
        }
    }
}
