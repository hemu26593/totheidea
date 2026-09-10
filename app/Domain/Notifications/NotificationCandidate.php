<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Models\CustomerContact;
use App\Models\NotificationDispatch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One qualifying (trigger, recipient, subject, period) tuple.
 *
 * A candidate is not yet a dispatch: it is what a trigger found, before
 * deduplication decides whether it has already been acted on. Keeping the two
 * apart is what lets the scheduler run as often as it likes.
 *
 * The RECIPIENT is a model, not an address. The address is snapshotted onto
 * the dispatch at creation, because a contact's email may change afterwards
 * and the record must say where the message actually went.
 */
final readonly class NotificationCandidate
{
    public function __construct(
        public string $triggerKey,
        public CustomerContact|User $recipient,
        public ?int $customerId,
        public ?int $enrollmentId,
        public ?Model $subject,
        public CarbonImmutable $scheduledFor,
        /**
         * The window this candidate belongs to - a date, an ISO week, a due
         * date plus an offset. Two candidates sharing a period are the same
         * notification, however many times the scheduler runs.
         */
        public string $period,
    ) {}

    public function recipientType(): string
    {
        return $this->recipient instanceof User
            ? NotificationDispatch::RECIPIENT_USER
            : NotificationDispatch::RECIPIENT_CONTACT;
    }

    public function recipientId(): int
    {
        return (int) $this->recipient->getKey();
    }
}
