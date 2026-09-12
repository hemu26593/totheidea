<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Notifications\RecipientResolver;
use App\Enums\GrantAbility;
use App\Mail\FormLinkMessage;
use App\Models\AccessGrant;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Enrollment;
use App\Models\FormTemplate;
use App\Models\FormVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Sending a business the link to one of its own forms.
 *
 * The whole workflow - check, issue, build the URL, email it - lives here so
 * the Livewire screen can be one button and no business rule leaks into the UI.
 * Nothing here is new machinery: AccessGrantService issues and revokes,
 * RecipientResolver picks the contact, GrantScope proves the scope, and the
 * external route builds the URL.
 *
 * WHY THE EMAIL IS NOT A notification_dispatches ROW. That pipeline writes a row
 * and reconstructs the message from it later, on a queue. This message contains
 * a plaintext token, and the plaintext is never stored - not in a row, not in a
 * job payload. So the send is synchronous and carries the token only in memory,
 * from issue to SMTP, and then it is gone. It goes through the same Mail layer
 * and the same MAIL_* configuration as every other message the platform sends.
 *
 * WHY RESEND ISSUES A NEW GRANT. The plaintext cannot be recovered - that is the
 * point of storing only a hash - so "send the same link again" is not a thing
 * that can exist. Resending revokes the live grant and issues a fresh one, in
 * one transaction, which is the lifecycle AccessGrantService already supports.
 * A business therefore never holds two working links to the same form at once,
 * however many times the button is pressed.
 */
class FormLinkService
{
    public function __construct(
        private readonly AccessGrantService $grants,
        private readonly RecipientResolver $recipients,
    ) {}

    /**
     * Issue a link for one of this business's forms and email it to them.
     *
     * The pair (enrolment, published form version) is what a link is FOR, and
     * it is what the grant is scoped to - not a submission. A submission may not
     * exist yet: the business creates it by opening the link, which is what
     * makes the answers theirs (source = external_grant, created_by = NULL)
     * rather than a record staff started on their behalf.
     *
     * Returns the contact it reached so the caller can say so. The URL and the
     * token are deliberately not returned: nothing upstream has any use for
     * them, and handing them back would invite them into a log or a view.
     */
    public function send(Enrollment $enrollment, FormTemplate $template, User $actor): CustomerContact
    {
        $customer = $this->customerFor($enrollment);

        // Checked before the version is resolved, so "that form is not yours"
        // is the answer to a foreign template rather than "it is not published".
        $this->assertSendable($customer, $enrollment, $template);

        $version = $this->publishedVersionOf($template);

        $contact = $this->recipients->primaryContactFor((int) $customer->getKey());

        if ($contact === null) {
            throw new RuntimeException(
                'This business has no contact with an email address. Add a contact on the '
                .'business record before sending a form link.'
            );
        }

        $expiresAt = $this->expiry();

        // One transaction: a resend must never leave two live grants behind,
        // and a failure must not leave the old one revoked with no replacement.
        $issued = DB::transaction(function () use ($customer, $enrollment, $version, $actor, $contact, $expiresAt): IssuedGrant {
            foreach ($this->activeGrantsFor($customer, $enrollment, $version) as $live) {
                $this->grants->revoke($live, $actor, 'Superseded by a newly sent form link.');
            }

            return $this->grants->issue(
                customer: $customer,
                enrollment: $enrollment,
                subject: $version,
                ability: GrantAbility::CompleteForm,
                expiresAt: $expiresAt,
                actor: $actor,
                contact: $contact,
                maxUses: 1,
            );
        });

        // Outside the transaction: an SMTP failure must not roll back a grant
        // that was genuinely issued and audited, and the caller is told about
        // the failure either way.
        Mail::to((string) $contact->email)->send(new FormLinkMessage(
            customerName: (string) $customer->name,
            contactName: (string) ($contact->name ?? ''),
            formName: (string) $template->name,
            url: $this->urlFor($issued->plaintextToken),
            expiresAt: $issued->grant->expires_at,
        ));

        return $contact;
    }

    /**
     * Withdraw the live link for this form.
     */
    public function revoke(Enrollment $enrollment, FormTemplate $template, User $actor): int
    {
        $customer = $this->customerFor($enrollment);
        $version = $this->publishedVersionOf($template);
        $revoked = 0;

        foreach ($this->activeGrantsFor($customer, $enrollment, $version) as $grant) {
            $this->grants->revoke($grant, $actor, 'Withdrawn by staff.');
            $revoked++;
        }

        if ($revoked === 0) {
            throw new RuntimeException('There is no active link for this form to withdraw.');
        }

        return $revoked;
    }

    /**
     * The most recent grant for this (enrolment, form), live or not, so a
     * screen can say what state the link is in.
     */
    public function latestGrantFor(Enrollment $enrollment, FormTemplate $template): ?AccessGrant
    {
        $version = $template->publishedVersion();

        if ($version === null) {
            return null;
        }

        return $this->grantQuery((int) $enrollment->customer_id, (int) $enrollment->getKey(), $version)
            ->with('customerContact:id,name,email')
            ->latest('id')
            ->first();
    }

    /**
     * Whether this form can be sent to this enrolment at all, without saying
     * why not.
     *
     * Used to decide whether to render the button. The button being absent is
     * never the authorization - send() re-checks everything.
     */
    public function canBeSent(Customer $customer, Enrollment $enrollment, FormTemplate $template): bool
    {
        try {
            $this->assertSendable($customer, $enrollment, $template);
        } catch (RuntimeException) {
            return false;
        }

        return true;
    }

    /**
     * Everything that must be true before a business is sent a link.
     *
     * Each message is written to be shown to the person who pressed the button:
     * it says what to do, and it never quotes an internal identifier.
     */
    private function assertSendable(Customer $customer, Enrollment $enrollment, FormTemplate $template): void
    {
        // The enrolment must be this business's. AccessGrantService asserts it
        // again when it issues; asserting here turns it into a message rather
        // than an exception.
        if ((int) $enrollment->customer_id !== (int) $customer->getKey()) {
            throw new RuntimeException('That enrolment could not be verified against this business.');
        }

        if (! $enrollment->isActive()) {
            throw new RuntimeException('That enrolment is not active, so there is no form to send against it.');
        }

        // Shared curriculum, or this business's own - never another's.
        if ($template->customer_id !== null && (int) $template->customer_id !== (int) $customer->getKey()) {
            throw new RuntimeException('That form does not belong to this business.');
        }

        if ($template->publishedVersion() === null) {
            throw new RuntimeException('A form must be published before it can be sent to a business.');
        }
    }

    private function customerFor(Enrollment $enrollment): Customer
    {
        $customer = Customer::query()->find($enrollment->customer_id);

        if ($customer === null) {
            throw new RuntimeException('The business this enrolment belongs to could not be found.');
        }

        return $customer;
    }

    private function publishedVersionOf(FormTemplate $template): FormVersion
    {
        $version = $template->publishedVersion();

        if ($version === null) {
            throw new RuntimeException('A form must be published before it can be sent to a business.');
        }

        return $version;
    }

    /**
     * Grants that would still open this form right now.
     *
     * @return Collection<int, AccessGrant>
     */
    private function activeGrantsFor(Customer $customer, Enrollment $enrollment, FormVersion $version)
    {
        return $this->grantQuery((int) $customer->getKey(), (int) $enrollment->getKey(), $version)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->whereColumn('use_count', '<', 'max_uses')
            ->get();
    }

    /**
     * Every grant ever issued for this exact (business, enrolment, form
     * version, complete_form) combination - the natural key for a form link.
     */
    private function grantQuery(int $customerId, int $enrollmentId, FormVersion $version)
    {
        return AccessGrant::query()
            ->where('customer_id', $customerId)
            ->where('enrollment_id', $enrollmentId)
            ->where('subject_type', $version->getMorphClass())
            ->where('subject_id', $version->getKey())
            ->where('ability', GrantAbility::CompleteForm);
    }

    /**
     * The link itself, built from the one existing external route so APP_URL
     * and any subdirectory deployment are honoured. No host is written here.
     */
    private function urlFor(string $plaintextToken): string
    {
        return route('external.forms.show', ['token' => $plaintextToken]);
    }

    private function expiry(): CarbonImmutable
    {
        $days = (int) config('access.link_expiry_days', 0);

        if ($days < 1) {
            // A grant is never open-ended. Misconfiguration fails loudly here
            // rather than producing a link that outlives the programme.
            throw new RuntimeException(
                'No form link expiry is configured. Set ACCESS_FORM_LINK_EXPIRY_DAYS to the number '
                .'of days a business should have to complete a form it is sent.'
            );
        }

        return CarbonImmutable::now()->addDays($days);
    }
}
