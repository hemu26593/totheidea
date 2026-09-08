<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Enums\NotificationChannel;
use App\Jobs\DispatchNotificationJob;
use App\Models\CustomerContact;
use App\Models\NotificationDispatch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Turns a candidate into a dispatch row - at most once.
 *
 * The idempotency guarantee is the database's, not this class's. The insert is
 * attempted and a unique violation on dedupe_key is caught and treated as "already
 * handled". A read-then-write check would leave a window in which two scheduler
 * runs, or two queue workers, both see nothing and both insert.
 *
 * CONSENT IS DECIDED HERE, AND A WITHHELD SEND IS STILL A ROW. A suppressed
 * dispatch is recorded with status = suppressed, so "why did this business not
 * hear from us?" has an answer.
 */
class NotificationDispatchService
{
    public function __construct(private readonly DedupeKeyBuilder $keys) {}

    /**
     * Queue a candidate, or recognise that it has already been queued.
     *
     * Returns null when the dedupe key already exists - the notification has
     * been dealt with, whatever its outcome was.
     */
    public function queue(NotificationCandidate $candidate): ?NotificationDispatch
    {
        $channel = NotificationChannel::Email;
        $address = $this->addressFor($candidate, $channel);

        if ($address === null) {
            // No address to snapshot means there is no send to record.
            return null;
        }

        $suppressed = $this->isSuppressed($candidate, $channel);
        $dedupeKey = $this->keys->build($candidate);

        try {
            return DB::transaction(function () use ($candidate, $channel, $address, $suppressed, $dedupeKey): NotificationDispatch {
                $dispatch = new NotificationDispatch;
                $dispatch->forceFill([
                    'trigger_key' => $candidate->triggerKey,
                    'channel' => $channel,
                    'recipient_type' => $candidate->recipientType(),
                    'recipient_id' => $candidate->recipientId(),
                    // Snapshot: the address as it was at send time.
                    'address_used' => $address,
                    'customer_id' => $candidate->customerId,
                    'enrollment_id' => $candidate->enrollmentId,
                    'subject_type' => $candidate->subject?->getMorphClass(),
                    'subject_id' => $candidate->subject?->getKey(),
                    'scheduled_for' => $candidate->scheduledFor,
                    'status' => $suppressed
                        ? NotificationDispatch::STATUS_SUPPRESSED
                        : NotificationDispatch::STATUS_PENDING,
                    'dedupe_key' => $dedupeKey,
                ])->save();

                return $dispatch;
            });
        } catch (QueryException $e) {
            if ($this->isDuplicate($e)) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Hand a pending dispatch to the queue.
     *
     * Separate from queue() so that creating the row and attempting delivery
     * are independently retryable: a worker crash loses an attempt, never the
     * record that the notification was due.
     */
    public function enqueue(NotificationDispatch $dispatch): void
    {
        if (! $dispatch->isPending()) {
            return;
        }

        DispatchNotificationJob::dispatch((int) $dispatch->getKey())
            ->onQueue((string) config('notifications.queue', 'default'));
    }

    /**
     * Consent, per channel, per contact.
     *
     * Internal users are staff acting on the programme; there is no opt-in
     * column on users and inventing one would be inventing a policy.
     */
    private function isSuppressed(NotificationCandidate $candidate, NotificationChannel $channel): bool
    {
        $recipient = $candidate->recipient;

        if (! $recipient instanceof CustomerContact) {
            return false;
        }

        return match ($channel) {
            NotificationChannel::Email => ! (bool) $recipient->email_opt_in,
            NotificationChannel::Whatsapp => ! (bool) $recipient->whatsapp_opt_in,
        };
    }

    private function addressFor(NotificationCandidate $candidate, NotificationChannel $channel): ?string
    {
        $recipient = $candidate->recipient;

        $address = match ($channel) {
            NotificationChannel::Email => $recipient->email,
            NotificationChannel::Whatsapp => $recipient instanceof CustomerContact
                ? $recipient->phone_e164
                : null,
        };

        return is_string($address) && $address !== '' ? $address : null;
    }

    /**
     * Portable duplicate-key detection. SQLite and MySQL word it differently
     * and use different SQLSTATEs, so both are recognised rather than one
     * being assumed.
     */
    private function isDuplicate(QueryException $e): bool
    {
        if ($e->getCode() === '23000' || $e->getCode() === '23505') {
            return true;
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, 'unique constraint failed')
            || str_contains($message, 'duplicate entry');
    }
}
