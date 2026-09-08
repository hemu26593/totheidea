<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCustomer;
use App\Domain\Shared\Concerns\HasActorTriple;
use App\Enums\ActorSource;
use Database\Factories\AssignmentSubmissionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * A participant's work against one released assignment.
 *
 * TWO MANDATORY PARENTS. The assignment instance says which released work is
 * being answered; the enrolment says whose work it is. Neither alone
 * identifies a submission, and the two must resolve to the same batch -
 * asserted by AssignmentSubmissionService, because the check spans three joins
 * and no foreign key can express it.
 *
 * The status is the CURRENT state; assignment_reviews is how it got there.
 * Resubmission advances attempt_number on this row rather than inserting a
 * second one.
 *
 * Mass-assignment note: nothing is fillable.
 */
#[Fillable([])]
class AssignmentSubmission extends Model
{
    use BelongsToCustomer;
    use HasActorTriple;

    /** @use HasFactory<AssignmentSubmissionFactory> */
    use HasFactory;

    public const STATUS_NOT_STARTED = 'not_started';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_ACCEPTED = 'accepted';

    /** @var array<int, string> */
    public const STATUSES = [
        self::STATUS_NOT_STARTED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_SUBMITTED,
        self::STATUS_RETURNED,
        self::STATUS_ACCEPTED,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => ActorSource::class,
            'attempt_number' => 'integer',
            'submitted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new RuntimeException(
                'An assignment submission is never removed. Its reviews are the record of what '
                .'was decided about it.'
            );
        });
    }

    public function assignmentInstance(): BelongsTo
    {
        return $this->belongsTo(AssignmentInstance::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function accessGrant(): BelongsTo
    {
        return $this->belongsTo(AccessGrant::class);
    }

    /**
     * The decision history, oldest first - "returned, resubmitted, returned
     * again, accepted" in full.
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(AssignmentReview::class)->orderBy('attempt_number');
    }

    public function isSubmitted(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }
}
