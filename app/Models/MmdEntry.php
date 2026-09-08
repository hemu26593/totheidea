<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCustomer;
use App\Domain\Shared\Concerns\HasActorTriple;
use App\Enums\ActorSource;
use Database\Factories\MmdEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One day's business figures - the daily heartbeat.
 *
 * Owned by the CUSTOMER, attributed to an enrolment. Business data outlives a
 * programme run, so the enrolment is attribution rather than ownership.
 *
 * AMENDED WITH AUDIT, NEVER DELETED. An amended figure changes a
 * target-versus-actual conclusion, so what it was must survive.
 *
 * [B1 CLIENT DECISION] - the grain of this table is unresolved. Whether one
 * business files one row a day, or contributors file their own, is unanswered,
 * so no unique key is applied and no contributor column exists. Everything
 * that reads these rows aggregates over a date range, which is correct under
 * either answer.
 */
#[Fillable([])]
class MmdEntry extends Model
{
    use BelongsToCustomer;
    use HasActorTriple;

    /** @use HasFactory<MmdEntryFactory> */
    use HasFactory;

    /**
     * The measure set. FIXED COLUMNS, NOT ROWS - every one is aggregated, and
     * the list is closed.
     *
     * mmd_targets validates its own metric against this same list, so the two
     * cannot drift apart.
     *
     * @var array<int, string>
     */
    public const METRICS = [
        'fund_in',
        'fund_out',
        'enquiries_new',
        'enquiries_repeat',
        'enquiries_referral',
        'sales_closed_count',
        'sales_closed_value',
        'production',
    ];

    /**
     * The measures that are money rather than counts. Used only to decide
     * decimal handling - there is no arithmetic meaning attached here.
     *
     * @var array<int, string>
     */
    public const MONEY_METRICS = [
        'fund_in',
        'fund_out',
        'sales_closed_value',
        'production',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => ActorSource::class,
            'entry_date' => 'date',
            'fund_in' => 'decimal:2',
            'fund_out' => 'decimal:2',
            'enquiries_new' => 'integer',
            'enquiries_repeat' => 'integer',
            'enquiries_referral' => 'integer',
            'sales_closed_count' => 'integer',
            'sales_closed_value' => 'decimal:2',
            'production' => 'decimal:2',
            'recorded_at' => 'datetime',
            'amended_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new RuntimeException(
                'An MMD entry is amended with audit, never deleted. It is an input to a '
                .'target-versus-actual conclusion.'
            );
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function accessGrant(): BelongsTo
    {
        return $this->belongsTo(AccessGrant::class);
    }

    public function wasAmended(): bool
    {
        return $this->amended_at !== null;
    }
}
