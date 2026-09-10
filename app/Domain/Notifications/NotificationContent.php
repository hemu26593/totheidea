<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Models\NotificationDispatch;

/**
 * What one of the nine triggers says in an email.
 *
 * NOTIFY, DO NOT DISCLOSE. Every line below tells the recipient that something
 * needs their attention and stops there. No figure, score, balance, attendance
 * mark or assignment title is put into an email, and that is a decision, not an
 * omission:
 *
 *   - Email is not a place the platform controls. A message sits in an inbox,
 *     is forwarded, is read on a shared device, and is delivered to whatever
 *     `address_used` held at the moment the dispatch was created - which may no
 *     longer be the right person.
 *   - It keeps customer isolation trivially true. A message that carries no
 *     business data cannot carry the wrong business's data.
 *   - It keeps the recorded failure safe to display. An error surfaced on the
 *     Notifications screen can never quote a sensitive body back.
 *
 * The wording is code, exactly as the triggers are (config/notifications.php):
 * the nine triggers are constants, not rows, so their wording is not
 * user-configurable in v1 either. A trigger with no entry here is a programming
 * error rather than a reason to send a blank email, so it falls back to a
 * neutral line and never to an empty one.
 */
final readonly class NotificationContent
{
    public function __construct(
        public string $subject,
        public string $line,
    ) {}

    public static function for(NotificationDispatch $dispatch): self
    {
        return self::map()[$dispatch->trigger_key] ?? new self(
            'A programme update needs your attention',
            'There is an update on your Business Mastery Programme that needs your attention.',
        );
    }

    /**
     * @return array<string, self>
     */
    private static function map(): array
    {
        return [
            'session_upcoming' => new self(
                'Your next BMP session is coming up',
                'Your next Business Mastery Programme session is coming up shortly.',
            ),
            'intake_incomplete' => new self(
                'Your BMP intake form is not finished yet',
                'Your programme intake form has been started but not submitted yet.',
            ),
            'assignment_due' => new self(
                'A BMP assignment is due soon',
                'One of your programme assignments is due shortly.',
            ),
            'assignment_overdue' => new self(
                'A BMP assignment is overdue',
                'One of your programme assignments is past its due date.',
            ),
            'daily_dashboard_missing' => new self(
                "Today's BMP dashboard has not been filled in",
                "Today's daily dashboard has not been filled in yet.",
            ),
            'dashboard_missed_three_days' => new self(
                'Your BMP dashboard has been missed for three days',
                'The daily dashboard has not been filled in for three days running.',
            ),
            'payment_due' => new self(
                'A BMP payment is due',
                'A programme payment is due. Your programme team can confirm the details.',
            ),
            'attendance_below_threshold' => new self(
                'Your BMP attendance needs attention',
                'Your programme attendance needs attention.',
            ),
            'weekly_batch_summary' => new self(
                'Your weekly BMP summary is ready',
                'Your weekly Business Mastery Programme summary is ready in the platform.',
            ),
        ];
    }
}
