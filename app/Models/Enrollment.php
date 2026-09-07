<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EnrollmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One customer's participation in one batch - THE OWNERSHIP SPINE.
 *
 * Every enrolment-owned record resolves isolation through this row. Hanging
 * such data off customer_id instead silently merges two programme runs, which
 * is expensive to unpick once data exists.
 *
 * Repeat participation is permitted across batches; UNIQUE (customer_id,
 * batch_id) blocks only a double enrolment in the SAME run.
 *
 * Mass-assignment note: status and the lifecycle timestamps are excluded.
 * Transitions go through EnrollmentService, which audits them.
 */
#[Fillable(['customer_id', 'batch_id', 'payment_due_date'])]
class Enrollment extends Model
{
    /** @use HasFactory<EnrollmentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enrolled_at' => 'datetime',
            'completed_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'payment_due_date' => 'date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function accessGrants(): HasMany
    {
        return $this->hasMany(AccessGrant::class);
    }

    public function termsAcceptances(): HasMany
    {
        return $this->hasMany(TermsAcceptance::class);
    }
}
