<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * The participant's BUSINESS (ADR-003).
 *
 * A Customer is NOT a User and never authenticates (ADR-004/007). This model
 * deliberately has:
 *
 *   - no Authenticatable
 *   - no password, remember token or any credential attribute
 *   - no Notifiable (reminders go to customer_contacts, not to the business)
 *   - no relationship expressing "this customer is that user"
 *
 * The only relationship to User is archived_by, which records which member of
 * staff archived the record. That is provenance, not ownership.
 *
 * Deletion is refused. Customers are archived (ADR-011) so that historical
 * customer-owned data stays available.
 *
 * Mass-assignment note: status, archived_at and archived_by are excluded from
 * fillable. Archiving happens only through CustomerService, which writes the
 * audit entry.
 */
#[Fillable(['name', 'code'])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new RuntimeException(
                'Customers are archived, never deleted (ADR-011). Use CustomerService::archive().'
            );
        });
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function accessGrants(): HasMany
    {
        return $this->hasMany(AccessGrant::class);
    }

    /**
     * The member of staff who archived this record. Provenance only - this is
     * NOT an owner, and a customer is never a user.
     */
    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    public function primaryContact(): ?CustomerContact
    {
        return $this->contacts()
            ->where('is_primary', true)
            ->whereNull('archived_at')
            ->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Read relationships
    |--------------------------------------------------------------------------
    |
    | Retrieval only, for the internal UI. Writes go through the domain
    | services; see the note on Enrollment.
    |
    */

    public function mmdEntries(): HasMany
    {
        return $this->hasMany(MmdEntry::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    public function hrPolicies(): HasMany
    {
        return $this->hasMany(HrPolicy::class);
    }

    public function aiGenerations(): HasMany
    {
        return $this->hasMany(AiGeneration::class);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
