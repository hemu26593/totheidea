<?php

declare(strict_types=1);

namespace App\Domain\Forms;

use App\Domain\Access\FormLinkService;
use App\Domain\Access\IssuedLink;
use App\Models\AccessGrant;
use App\Models\Enrollment;
use App\Models\FormTemplate;
use App\Models\FormVersion;
use App\Models\User;

/**
 * The one instrument every participant is given on entry: the Participant
 * Business Analysis Questionnaire.
 *
 * WHAT "ASSOCIATING A FORM WITH A CUSTOMER" MEANS HERE, because it is not a
 * join table. The questionnaire is shared curriculum - one global template,
 * answered by every business - so what makes it *this* business's form is the
 * scoped link: an access grant for (their enrolment, the published version,
 * complete_form). That grant is the association, the authority and the audit
 * entry all at once, and it is the mechanism the Forms screen has always used.
 * Nothing new is introduced to hold a per-customer assignment, because a
 * second record of the same fact is a second thing to keep in step.
 *
 * ISSUING IS IDEMPOTENT BY DESIGN. A business that already holds a live link
 * is left alone rather than handed a second one - issuing again would revoke
 * the link already sitting in their inbox. So re-saving a customer, re-running
 * a seeder, or double-clicking Create cannot produce two links.
 *
 * It knows WHICH form by its stable key and nothing else: no id, no position,
 * no "the newest one". The seeder writes that key and this class reads it.
 */
final class ParticipantQuestionnaire
{
    /**
     * The stable identifier the seeder creates and this class resolves.
     *
     * Changing it would orphan the questionnaire rather than rename it, which
     * is why it is a constant here and not a configuration knob.
     */
    public const KEY = 'bmp_participant_business_analysis';

    public function __construct(private readonly FormLinkService $links) {}

    /**
     * The shared template, if it has been seeded.
     */
    public function template(): ?FormTemplate
    {
        return FormTemplate::query()
            ->whereNull('customer_id')
            ->where('key', self::KEY)
            ->whereNull('archived_at')
            ->first();
    }

    public function publishedVersion(): ?FormVersion
    {
        return $this->template()?->publishedVersion();
    }

    /**
     * Whether there is anything to give a new participant yet.
     *
     * False is a legitimate state, not a failure: a database that has not been
     * seeded has no questionnaire, and a customer created in it is still a
     * customer. The caller says so rather than refusing the customer.
     */
    public function isAvailable(): bool
    {
        return $this->publishedVersion() !== null;
    }

    /**
     * Give this enrolment its questionnaire link.
     *
     * Returns null when there is nothing to issue - no questionnaire is
     * published, or this enrolment already holds a live link. Neither is an
     * error, and neither writes anything.
     */
    public function assign(Enrollment $enrollment, User $actor): ?IssuedLink
    {
        $template = $this->template();

        if ($template === null || $template->publishedVersion() === null) {
            return null;
        }

        // A link is a capability, so issuing one asks the same policy here as
        // it does behind the Forms screen's button. Today every role that can
        // create a customer can also issue links; this is what keeps that
        // from becoming an assumption the next role silently breaks.
        if (! $actor->can('create', AccessGrant::class)) {
            return null;
        }

        if ($this->links->hasActiveLink($enrollment, $template)) {
            return null;
        }

        return $this->links->issue($enrollment, $template, $actor);
    }
}
