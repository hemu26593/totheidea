<?php

declare(strict_types=1);

namespace App\Domain\Shared\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Customer ownership for records carrying a customer_id (Step 3B, invariant I15).
 *
 * Two kinds of table use this. On directly-owned tables customer_id IS the
 * ownership. On the four enrolment-owned tables that also carry it
 * (form_submissions, session_attendances, assignment_submissions,
 * day_plan_items) it is a denormalised copy — defence in depth, so that a
 * single missed join cannot leak another customer's data. There,
 * enrollment_id remains the semantic owner.
 *
 * The rule, stated once so no developer has to remember it:
 *
 *     table.customer_id == table.enrollment.customer_id
 *
 * It is set once on insert and NEVER updated — not by a form request, not by
 * a service, not by a fixup script. This trait enforces presence and
 * immutability. The cross-check against the enrolment needs the Enrollment
 * model and so is asserted by the writing services from Phase 1 onward.
 *
 * Applied to models from Phase 1 onward. The customer() relationship is
 * deliberately absent until the customers table exists.
 */
trait BelongsToCustomer
{
    public static function bootBelongsToCustomer(): void
    {
        static::creating(static function (Model $model): void {
            if ($model->getAttribute('customer_id') === null) {
                throw new RuntimeException(
                    'A customer-owned record cannot be created without a customer_id.'
                );
            }
        });

        static::updating(static function (Model $model): void {
            if ($model->isDirty('customer_id')) {
                throw new RuntimeException(
                    'customer_id is set once on insert and is never reassigned.'
                );
            }
        });
    }

    /**
     * Constrain a query to one customer. Callers pass the id explicitly:
     * internal staff work across many customers, so there is no implicit
     * "current customer" to scope by.
     */
    public function scopeForCustomer(Builder $query, int $customerId): Builder
    {
        return $query->where($query->getModel()->qualifyColumn('customer_id'), $customerId);
    }
}
