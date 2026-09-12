<?php

declare(strict_types=1);

namespace App\Mail;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The email that carries a business its own form link.
 *
 * DELIBERATELY NOT QUEUED, AND NOT SerializesModels. A queued mailable is
 * serialised into the jobs table, and this one holds a plaintext access token -
 * which is never stored, anywhere. It is constructed with plain strings, sent
 * synchronously by FormLinkService, and then the token is gone.
 *
 * IT CARRIES THE BUSINESS'S OWN DETAILS AND NOTHING ELSE. The recipient's name,
 * their company, the name of the form they were asked to fill in, and the link.
 * No score, no figure, no internal note, no staff name, no other business - the
 * same rule the reminder emails follow, for the same reason: an inbox is not
 * somewhere the platform controls.
 */
class FormLinkMessage extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $customerName,
        public readonly string $contactName,
        public readonly string $formName,
        public readonly string $url,
        public readonly ?DateTimeInterface $expiresAt = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->formName.' — please complete this form');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.form-link',
            with: [
                'greeting' => $this->contactName !== '' ? 'Hello '.$this->contactName.',' : 'Hello,',
                'customerName' => $this->customerName,
                'formName' => $this->formName,
                'url' => $this->url,
                'expiresAt' => $this->expiresAt,
            ],
        );
    }
}
