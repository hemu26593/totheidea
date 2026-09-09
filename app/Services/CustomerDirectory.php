<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Support\LikeTerm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Searching and filtering the customer directory.
 *
 * An application-layer query object, not a domain rule. It exists so that the
 * directory list, the customer picker on a report, and the batch roster all
 * search the same way rather than each growing their own `where` clause.
 *
 * The status vocabulary is the one CustomerService already writes; nothing
 * here invents a state.
 */
class CustomerDirectory
{
    /** @var array<int, string> */
    public const STATUSES = ['prospect', 'active', 'archived'];

    public function query(string $search = '', string $status = 'all'): Builder
    {
        return Customer::query()
            ->when($search !== '', function (Builder $query) use ($search): void {
                // Escaped through LikeTerm, which states the escape character
                // rather than relying on an engine default: SQLite has none and
                // MySQL uses a backslash, so a literal % typed into the search
                // box matched everything on one and nothing on the other.
                $term = LikeTerm::contains($search);

                $query->where(function (Builder $inner) use ($term): void {
                    LikeTerm::where($inner, 'name', $term);
                    LikeTerm::orWhere($inner, 'code', $term);
                });
            })
            ->when(
                in_array($status, self::STATUSES, true),
                fn (Builder $query) => $query->where('status', $status),
                // "All" still hides archived records: an archived customer is
                // out of the way by definition, and is found by asking for it.
                fn (Builder $query) => $status === 'all' ? $query->whereNull('archived_at') : $query,
            )
            ->orderBy('name');
    }

    /**
     * Customers an internal user may pick from, for selectors.
     *
     * @return Collection<int, Customer>
     */
    public function selectable(): Collection
    {
        return Customer::query()
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
    }
}
