<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * Builds the idempotency key that makes double-sending impossible.
 *
 * Triggers 2, 4, 5, 6, 7 and 8 describe conditions that STAY TRUE. Attendance
 * below 90% is continuously true once crossed; an unfilled intake form is
 * unfilled every morning. Without a key, every scheduler tick re-sends and the
 * system becomes the thing participants mute.
 *
 * The key is trigger + subject + period + RECIPIENT. The recipient is in there
 * for correctness, not convenience: a session reminder goes to every enrolment
 * in a batch, and a key without the recipient would let the first one written
 * suppress all the others.
 *
 * VARCHAR(190) is a hard limit - MySQL's utf8mb4 index prefix. A key that
 * would overflow is replaced wholesale by a digest rather than truncated,
 * because truncation could make two distinct notifications collide, which is
 * the failure mode that silently loses messages.
 */
class DedupeKeyBuilder
{
    private const MAX_LENGTH = 190;

    public function build(NotificationCandidate $candidate): string
    {
        $key = implode('|', [
            $candidate->triggerKey,
            $candidate->subject === null
                ? 'subject:none'
                : $candidate->subject->getMorphClass().':'.$candidate->subject->getKey(),
            'to:'.$candidate->recipientType().':'.$candidate->recipientId(),
            'period:'.$candidate->period,
        ]);

        if (strlen($key) <= self::MAX_LENGTH) {
            return $key;
        }

        // Deterministic and collision-resistant. Readability is worth less
        // than never merging two different sends.
        return 'sha256:'.hash('sha256', $key);
    }
}
