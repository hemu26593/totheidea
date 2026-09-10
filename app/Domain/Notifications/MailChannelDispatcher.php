<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Notifications\Contracts\ChannelDispatcher;
use App\Enums\NotificationChannel;
use App\Exceptions\ChannelNotConfiguredException;
use App\Mail\NotificationMessage;
use App\Models\NotificationDispatch;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Delivers a dispatch by email, through Laravel's own Mail layer.
 *
 * This is the HOW, and only the HOW. Who qualifies, when, how often and to
 * which address were all decided upstream: the trigger produced a candidate,
 * NotificationDispatchService deduplicated it and snapshotted the address, and
 * DispatchNotificationJob owns the attempt count, the backoff and the recording
 * of sent or failed. Nothing here re-resolves a recipient, re-checks
 * eligibility, or touches the dispatch row.
 *
 * IT SENDS TO THE SNAPSHOT, NEVER TO A FRESH LOOKUP. `address_used` is where
 * the message was addressed when the dispatch was created. Re-reading the
 * contact now would mean the row no longer records where the message actually
 * went, and would quietly redirect an in-flight notification if the contact had
 * been edited in between.
 *
 * SUCCESS IS THE TRANSPORT'S WORD, NOT OURS. This returns only after Mail has
 * handed the message to the configured transport, and it returns that
 * transport's own Message-ID. Every failure leaves by throwing, which is what
 * makes the job record a failure and retry - the one thing this class must
 * never do is return normally without a send having happened.
 */
class MailChannelDispatcher implements ChannelDispatcher
{
    /**
     * Mailers that accept a message without delivering it anywhere.
     *
     * Perfect locally; a silent outage in production, where the dispatch row
     * would read "sent" while the message sat in a log file.
     */
    private const NON_DELIVERING = ['log', 'array', 'null'];

    public function send(NotificationDispatch $dispatch): DispatchResult
    {
        $this->assertChannelIsEmail($dispatch);
        $this->assertMailerCanDeliver();

        $address = trim((string) $dispatch->address_used);

        if ($address === '') {
            throw new RuntimeException(sprintf(
                'Dispatch %d has no address to send to. The address is snapshotted when the '
                .'dispatch is created; an empty one means it was created without a resolvable '
                .'recipient.',
                $dispatch->getKey(),
            ));
        }

        try {
            $sent = Mail::to($address)->send(new NotificationMessage($dispatch));
        } catch (TransportExceptionInterface $e) {
            // RETHROWN WITHOUT THE ORIGINAL. Symfony attaches the raw SMTP
            // conversation to a TransportException (SmtpTransport::send ->
            // appendDebug), and that conversation contains the base64 AUTH
            // exchange - the mailbox password. It is not in getMessage(), but
            // the object carrying it must not travel any further: the queue
            // serialises failures into failed_jobs, and the dispatch's error is
            // rendered on the Notifications screen. Only the message text
            // continues, and the original is not chained as $previous.
            throw new RuntimeException(sprintf(
                'The mail transport refused dispatch %d: %s',
                $dispatch->getKey(),
                $e->getMessage(),
            ));
        }

        return new DispatchResult($this->messageIdFrom($sent, $dispatch));
    }

    private function assertChannelIsEmail(NotificationDispatch $dispatch): void
    {
        if ($dispatch->channel !== NotificationChannel::Email) {
            // WhatsApp remains out of scope. A dispatch for a channel with no
            // driver is a recorded failure, never a quiet success.
            throw ChannelNotConfiguredException::for($dispatch->channel);
        }
    }

    /**
     * Refuse to call a message delivered when the mailer cannot deliver it.
     *
     * Only in production. Locally and in tests the log and array mailers are
     * exactly what is wanted, and a test that sends nowhere is the point.
     */
    private function assertMailerCanDeliver(): void
    {
        $mailer = (string) config('mail.default');

        if (app()->isProduction() && in_array($mailer, self::NON_DELIVERING, true)) {
            throw new RuntimeException(sprintf(
                'The [%s] mailer does not deliver anything, so a notification must not be recorded '
                .'as sent through it. Set MAIL_MAILER to a real transport (smtp) and give it '
                .'credentials before relying on notifications.',
                $mailer,
            ));
        }
    }

    /**
     * The transport's own Message-ID.
     *
     * A transport that returns none leaves a reference that says so, rather
     * than a fabricated id that would read like a provider receipt.
     */
    private function messageIdFrom(mixed $sent, NotificationDispatch $dispatch): string
    {
        $messageId = null;

        if ($sent !== null && method_exists($sent, 'getMessageId')) {
            $messageId = $sent->getMessageId();
        }

        $messageId = trim((string) ($messageId ?? ''));

        return $messageId !== ''
            ? $messageId
            : 'no-provider-id:dispatch-'.$dispatch->getKey();
    }
}
