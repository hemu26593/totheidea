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

    /*
    |--------------------------------------------------------------------------
    | Read relationships
    |--------------------------------------------------------------------------
    |
    | Retrieval only, added so the internal UI can read an enrolment's work
    | without hand-rolling a query per screen. Writes still go through the
    | domain services that own each rule - nothing here is a write path, and
    | none of these relations is used to create a related record.
    |
    */

    public function formSubmissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(SessionAttendance::class);
    }

    public function assignmentSubmissions(): HasMany
    {
        return $this->hasMany(AssignmentSubmission::class);
    }

    public function dayPlanItems(): HasMany
    {
        return $this->hasMany(DayPlanItem::class);
    }

    public function timeGridEntries(): HasMany
    {
        return $this->hasMany(TimeGridEntry::class);
    }

    public function mmdTargets(): HasMany
    {
        return $this->hasMany(MmdTarget::class);
    }

    public function fundPlans(): HasMany
    {
        return $this->hasMany(FundPlan::class);
    }

    public function actionItems(): HasMany
    {
        return $this->hasMany(ActionItem::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'enrolled';
    }
}
