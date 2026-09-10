<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationChannel;
use Database\Factories\NotificationDispatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * One attempted send.
 *
 * This table IS the notification record. audit_logs is deliberately NOT
 * written per dispatch - duplicating it would be the second audit mechanism
 * the architecture forbids.
 *
 * APPEND-ONLY in the sense that matters: a dispatch is never deleted, and its
 * identity (trigger, recipient, subject, dedupe key) never changes. Its
 * lifecycle fields - status, sent_at, attempts, error - do move, because a
 * retryable send is exactly a row whose outcome is not yet known.
 *
 * Mass-assignment note: nothing is fillable. Rows are created by
 * NotificationScheduler and settled by DispatchNotificationJob.
 */
#[Fillable([])]
class NotificationDispatch extends Model
{
    /** @use HasFactory<NotificationDispatchFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /** Consent withheld. Recorded, never silently skipped. */
    public const STATUS_SUPPRESSED = 'suppressed';

    /** @var array<int, string> */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SENT,
        self::STATUS_FAILED,
        self::STATUS_SUPPRESSED,
    ];

    public const RECIPIENT_CONTACT = 'contact';

    public const RECIPIENT_USER = 'user';

    /**
     * Recipients are customer contacts or internal users. Participants have no
     * account, so there is no third kind.
     *
     * @var array<int, string>
     */
    public const RECIPIENT_TYPES = [
        self::RECIPIENT_CONTACT,
        self::RECIPIENT_USER,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'scheduled_for' => 'datetime',
            'sent_at' => 'datetime',
            'last_attempted_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $dispatch): void {
            // The identity of a dispatch is fixed. Letting any of it move
            // would let one row masquerade as a different send and defeat the
            // whole idempotency argument.
            foreach (['dedupe_key', 'trigger_key', 'recipient_type', 'recipient_id', 'address_used'] as $column) {
                if ($dispatch->isDirty($column)) {
                    throw new RuntimeException(
                        "A dispatch's [{$column}] is fixed at creation. Only its outcome may change."
                    );
                }
            }
        });

        static::deleting(function (): never {
            throw new RuntimeException(
                'A notification dispatch is never deleted. It is the record that a message was '
                .'attempted, suppressed or failed.'
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

    /**
     * What the message is about - a session instance, an assignment instance,
     * an enrolment, a batch. Polymorphic, so no foreign key.
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function wasSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    public function wasSuppressed(): bool
    {
        return $this->status === self::STATUS_SUPPRESSED;
    }

    public function hasFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}
