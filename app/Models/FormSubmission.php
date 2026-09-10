<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCustomer;
use App\Domain\Shared\Concerns\HasActorTriple;
use App\Enums\ActorSource;
use Database\Factories\FormSubmissionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One completed - or in-progress - form for one enrolment.
 *
 * BINDS TO THE VERSION, NEVER THE TEMPLATE. Reading a historical submission
 * always goes through formVersion(), never through
 * formTemplate()->publishedVersion(): resolving against "the current version"
 * after the fact is exactly the bug this design exists to prevent.
 *
 * Mass-assignment note: nothing structural is fillable. The version,
 * enrolment, customer and actor triple are set by SubmissionService, which
 * asserts they agree.
 */
#[Fillable([])]
class FormSubmission extends Model
{
    use BelongsToCustomer;
    use HasActorTriple;

    /** @use HasFactory<FormSubmissionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => ActorSource::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'amended_at' => 'datetime',
        ];
    }

    public function formVersion(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class);
    }

    public function scores(): HasMany
    {
        return $this->hasMany(SubmissionScore::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isSubmitted(): bool
    {
        return $this->status !== 'draft';
    }
}
