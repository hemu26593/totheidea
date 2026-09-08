<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Shared\SubjectOwnership;
use App\Exceptions\CustomerIsolationException;
use App\Models\Batch;
use App\Models\Enrollment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Which business a report is about, resolved server-side.
 *
 * REPORTS ARE THE MOST DANGEROUS THING TO GET WRONG, because they aggregate.
 * A single mis-scoped query does not leak one row; it leaks a whole business's
 * position in a document that is then handed to somebody. So the subject is
 * resolved from the database and the customer is derived from it - never taken
 * from a parameter alongside it.
 *
 * A BATCH IS NOT A BUSINESS. Several customers are enrolled in one batch, so a
 * batch-subject report has no single owning customer. Every builder for such a
 * report must therefore scope its rows through the enrolments it actually
 * reads, and the customer id on the payload is 0 to say plainly that no single
 * business owns it.
 *
 * Fails closed: a subject type this class does not name is refused rather than
 * assumed safe.
 */
class ReportScope
{
    /**
     * Sentinel for a batch-level report, which spans many businesses.
     */
    public const NO_SINGLE_CUSTOMER = 0;

    public function __construct(private readonly SubjectOwnership $ownership) {}

    /**
     * The customer a report subject belongs to.
     */
    public function customerIdFor(Model $subject): int
    {
        if ($subject instanceof Enrollment) {
            return $this->ownership->customerIdFor($subject);
        }

        if ($subject instanceof Batch) {
            // A batch spans businesses. Saying "customer 7" here would be a
            // lie about scope, and a report that lies about its scope is
            // exactly how one business's figures reach another's desk.
            return self::NO_SINGLE_CUSTOMER;
        }

        throw CustomerIsolationException::unverifiableSubject($subject->getMorphClass());
    }

    /**
     * The subject must be the kind of thing this report is about.
     *
     * The subject arrives from the caller, so its TYPE is checked as
     * carefully as its ownership: pointing a participant report at a batch, or
     * the reverse, would silently change which rows a builder reads.
     *
     * @param  class-string<Model>  $expected
     */
    public function assertSubjectType(Model $subject, string $expected): void
    {
        if (! $subject instanceof $expected) {
            throw new InvalidArgumentException(sprintf(
                'This report is about %s; got %s.',
                class_basename($expected),
                $subject::class,
            ));
        }
    }

    /**
     * Guard against a caller pairing a subject with another business.
     *
     * Used where a request carries both a customer and a subject: the two are
     * compared rather than the pair being trusted.
     */
    public function assertSubjectBelongsTo(Model $subject, int $customerId): void
    {
        $resolved = $this->customerIdFor($subject);

        if ($resolved !== $customerId) {
            throw CustomerIsolationException::mismatch(
                'report subject '.$subject->getMorphClass().' '.$subject->getKey(),
                'customer '.$customerId,
                'customer '.$resolved,
            );
        }
    }

    /**
     * The enrolments a batch-level report may read.
     *
     * Every batch report scopes its rows through this, so a batch report can
     * never reach an enrolment outside its own batch.
     *
     * @return Collection<int, Enrollment>
     */
    public function enrollmentsIn(Batch $batch)
    {
        return Enrollment::query()
            ->where('batch_id', $batch->getKey())
            ->orderBy('id')
            ->get();
    }
}
