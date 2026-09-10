<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Notifications\NotificationContent;
use App\Models\Customer;
use App\Models\NotificationDispatch;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One BMP reminder, as an email.
 *
 * DELIBERATELY NOT A ShouldQueue MAILABLE. The dispatch is ALREADY queued -
 * DispatchNotificationJob is what carries the retries, the backoff and the
 * recording of sent/failed against notification_dispatches. Queueing the
 * mailable as well would put the send outside that job, so the row would be
 * marked sent before anything had been handed to a transport, and a transport
 * failure would then be invisible to the dispatch log. This mailable is sent
 * synchronously inside the job that owns the outcome.
 *
 * IT CARRIES NO BUSINESS DATA. See NotificationContent: the body says that
 * something needs attention and names the business it concerns, and nothing
 * else. The customer is read from the dispatch's OWN customer_id and no other
 * record is touched, so an email cannot carry a second business's data.
 */
class NotificationMessage extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(private readonly NotificationDispatch $dispatch) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: NotificationContent::for($this->dispatch)->subject);
    }

    public function content(): Content
    {
        $content = NotificationContent::for($this->dispatch);

        return new Content(
            // markdown:, not view:. The template uses Laravel's mail
            // components (<x-mail::message>), and it is the markdown renderer
            // that registers the `mail::` view namespace those live in.
            markdown: 'mail.notification',
            with: [
                'line' => $content->line,
                'businessName' => $this->businessName(),
                // Internal staff have accounts and a dashboard to be sent to.
                // A customer contact has neither - BMP participants do not
                // authenticate - so they are never given a sign-in link.
                'signInUrl' => $this->dispatch->recipient_type === NotificationDispatch::RECIPIENT_USER
                    ? route('dashboard')
                    : null,
            ],
        );
    }

    /**
     * The business this dispatch concerns, read from the dispatch's own
     * customer_id and nowhere else.
     */
    private function businessName(): ?string
    {
        if ($this->dispatch->customer_id === null) {
            return null;
        }

        return Customer::query()
            ->whereKey($this->dispatch->customer_id)
            ->value('name');
    }
}
