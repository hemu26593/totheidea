<?php

declare(strict_types=1);

namespace App\Domain\Business;

use App\Exceptions\CustomerIsolationException;
use App\Models\AccessGrant;
use App\Models\Customer;
use App\Models\Position;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * The participant's org chart.
 *
 * INVARIANT I13, in two halves, neither of which a foreign key can express:
 *
 *   1. A parent position must belong to the SAME BUSINESS. Without the check,
 *      one customer's org chart can be hung under another's, and every
 *      subsequent tree walk leaks.
 *   2. The graph must be ACYCLIC. A cycle is not merely untidy: walking the
 *      tree to render a chart, or to resolve a reporting line, would never
 *      terminate.
 *
 * ARCHIVE-ONLY. Acknowledgements RESTRICT against positions, so a position
 * that has signed a policy stays resolvable for as long as that record does.
 *
 * holder_name is a string and stays one. There is no method here that accepts
 * a User, because the participant's staff have no accounts.
 */
class PositionService
{
    public function __construct(private readonly BusinessOwnership $ownership) {}

    public function create(
        Customer $customer,
        string $title,
        ?Position $parent = null,
        ?string $holderName = null,
        ?string $roleDescription = null,
        ?string $kra = null,
        ?User $actor = null,
        ?AccessGrant $grant = null,
    ): Position {
        $this->assertTitleIsPresent($title);
        $this->ownership->assertGrantMatchesCustomer($grant, $customer);

        if ($parent !== null) {
            $this->assertParentIsInTheSameBusiness($parent, $customer);
            $this->assertParentIsNotArchived($parent);
        }

        return DB::transaction(function () use ($customer, $title, $parent, $holderName, $roleDescription, $kra, $actor, $grant): Position {
            $position = new Position;
            $position->forceFill([
                'customer_id' => $customer->getKey(),
                'parent_position_id' => $parent?->getKey(),
                'title' => $title,
                // FREE TEXT, permanently. Never resolved against users.
                'holder_name' => $holderName,
                'role_description' => $roleDescription,
                'kra' => $kra,
                'source' => $this->ownership->resolveSource($actor, $grant),
                'created_by' => $actor?->getKey(),
                'access_grant_id' => $grant?->getKey(),
            ])->save();

            return $position->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(
        Position $position,
        array $attributes,
        ?User $actor = null,
        ?AccessGrant $grant = null,
    ): Position {
        $this->assertNotArchived($position);
        $this->ownership->assertGrantMatchesCustomer($grant, $position->customer);

        $allowed = array_intersect_key($attributes, array_flip([
            'title', 'holder_name', 'role_description', 'kra',
        ]));

        if (array_key_exists('title', $allowed)) {
            $this->assertTitleIsPresent((string) $allowed['title']);
        }

        return DB::transaction(function () use ($position, $allowed): Position {
            $position->forceFill($allowed)->save();

            return $position->fresh();
        });
    }

    /**
     * Move a position under a new manager, or to the root.
     *
     * Both halves of I13 are re-checked here, not just at creation: a reparent
     * is precisely the operation that can introduce a cycle.
     */
    public function reparent(Position $position, ?Position $parent, ?User $actor = null): Position
    {
        $this->assertNotArchived($position);

        if ($parent !== null) {
            $customer = $position->customer()->first();

            if ($customer === null) {
                throw CustomerIsolationException::unverifiableSubject($position->getMorphClass());
            }

            $this->assertParentIsInTheSameBusiness($parent, $customer);
            $this->assertParentIsNotArchived($parent);
            $this->assertWouldNotCreateACycle($position, $parent);
        }

        return DB::transaction(function () use ($position, $parent): Position {
            $position->forceFill(['parent_position_id' => $parent?->getKey()])->save();

            return $position->fresh();
        });
    }

    public function archive(Position $position, User $actor): Position
    {
        return DB::transaction(function () use ($position): Position {
            $position->forceFill(['archived_at' => now()])->save();

            return $position->fresh();
        });
    }

    /**
     * One business's chart, top down.
     *
     * @return Collection<int, Position>
     */
    public function chartFor(Customer $customer): Collection
    {
        return Position::query()
            ->where('customer_id', $customer->getKey())
            ->whereNull('archived_at')
            ->orderByRaw('parent_position_id is not null')
            ->orderBy('parent_position_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * The reporting line from a position up to its root.
     *
     * Bounded by the acyclic invariant; the guard below is a belt to that
     * braces, because a chart that could not be walked would hang a request.
     *
     * @return array<int, Position>
     */
    public function reportingLine(Position $position): array
    {
        $line = [];
        $cursor = $position;
        $seen = [];

        while ($cursor !== null) {
            if (isset($seen[$cursor->getKey()])) {
                throw new RuntimeException(sprintf(
                    'The reporting line above position %d contains a cycle.',
                    $position->getKey(),
                ));
            }

            $seen[$cursor->getKey()] = true;
            $line[] = $cursor;
            $cursor = $cursor->parent()->first();
        }

        return $line;
    }

    /**
     * INVARIANT I13, first half.
     */
    public function assertParentIsInTheSameBusiness(Position $parent, Customer $customer): void
    {
        if ((int) $parent->customer_id !== (int) $customer->getKey()) {
            throw CustomerIsolationException::mismatch(
                'parent position '.$parent->getKey(),
                'customer '.$customer->getKey(),
                'customer '.$parent->customer_id,
            );
        }
    }

    /**
     * INVARIANT I13, second half.
     *
     * Walks upward from the proposed parent: if this position appears anywhere
     * on that line, attaching to it would close a loop. Also refuses a
     * position parented to itself, which is the one-node case of the same
     * thing.
     */
    public function assertWouldNotCreateACycle(Position $position, Position $parent): void
    {
        if ((int) $parent->getKey() === (int) $position->getKey()) {
            throw new InvalidArgumentException(sprintf(
                'Position %d cannot report to itself.',
                $position->getKey(),
            ));
        }

        $cursor = $parent;
        $seen = [];

        while ($cursor !== null) {
            if (isset($seen[$cursor->getKey()])) {
                // The existing graph is already broken; refuse rather than
                // loop forever.
                throw new RuntimeException('The existing reporting line contains a cycle.');
            }

            $seen[$cursor->getKey()] = true;

            if ((int) $cursor->getKey() === (int) $position->getKey()) {
                throw new InvalidArgumentException(sprintf(
                    'Making position %d report to position %d would create a cycle in the org '
                    .'chart: %d already reports, directly or indirectly, to %d.',
                    $position->getKey(),
                    $parent->getKey(),
                    $parent->getKey(),
                    $position->getKey(),
                ));
            }

            $cursor = $cursor->parent()->first();
        }
    }

    private function assertNotArchived(Position $position): void
    {
        if ($position->isArchived()) {
            throw new RuntimeException(
                "Position {$position->getKey()} is archived and is not edited."
            );
        }
    }

    private function assertParentIsNotArchived(Position $parent): void
    {
        if ($parent->isArchived()) {
            throw new RuntimeException(sprintf(
                'Position %d is archived and cannot be reported to.',
                $parent->getKey(),
            ));
        }
    }

    private function assertTitleIsPresent(string $title): void
    {
        if (trim($title) === '') {
            throw new InvalidArgumentException('A position needs a title.');
        }
    }
}
