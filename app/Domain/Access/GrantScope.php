<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Exceptions\CustomerIsolationException;
use App\Models\AssignmentInstance;
use App\Models\Customer;
use App\Models\Enrollment;
use App\Models\FormVersion;
use App\Models\SessionInstance;
use Illuminate\Database\Eloquent\Model;

/**
 * Decides whether a subject is inside a grant's scope. Invariant I11.
 *
 * One class, used both when a grant is ISSUED and again when it is REDEEMED.
 * Two copies of this rule would eventually disagree, and the half that
 * disagreed would be the isolation boundary.
 *
 * There are two shapes of subject, and conflating them is what makes this
 * subtle:
 *
 *   CUSTOMER-OWNED - a form submission, a terms acceptance, the enrolment
 *   itself. The row names its own owner, so the rule is equality: it must be
 *   THIS customer's and THIS enrolment's.
 *
 *   SHARED - a form version, an assignment instance, a session instance.
 *   Curriculum and delivery are shared across businesses by design, so such a
 *   row has no customer_id to compare and equality is not available. Isolation
 *   is carried by the grant's OWN customer + enrolment pair, and the rule
 *   becomes REACHABILITY: the enrolment must actually be able to encounter
 *   this thing. An assignment released to another cohort is not reachable, and
 *   a grant naming it is refused.
 *
 * FAILS CLOSED. A subject type not named below is refused, so a later phase
 * that wants to make something grantable has to decide, deliberately, which of
 * the two shapes it is.
 */
class GrantScope
{
    public function assertInScope(Model $subject, Customer $customer, Enrollment $enrollment): void
    {
        // --- The enrolment itself (accept_terms, view_report) --------------
        if ($subject instanceof Enrollment) {
            if ((int) $subject->getKey() !== (int) $enrollment->getKey()) {
                throw CustomerIsolationException::mismatch(
                    'subject enrolment '.$subject->getKey(),
                    'enrolment '.$enrollment->getKey(),
                    'enrolment '.$subject->getKey(),
                );
            }

            return;
        }

        // --- Shared curriculum ---------------------------------------------
        //
        // A form version is global: the same instrument is put to every
        // business, which is exactly why submissions bind to the version. It
        // carries no owner and cannot contradict the grant. The write it
        // authorises is created against the grant's enrolment by
        // SubmissionService, and that is where the isolation actually lives.
        if ($subject instanceof FormVersion) {
            return;
        }

        // --- Shared delivery, constrained by reachability -------------------
        if ($subject instanceof SessionInstance) {
            $this->assertSameBatch(
                (int) $subject->batch_id,
                $enrollment,
                'session instance '.$subject->getKey(),
            );

            return;
        }

        if ($subject instanceof AssignmentInstance) {
            $sessionInstance = $subject->sessionInstance()->first();

            if ($sessionInstance === null) {
                throw CustomerIsolationException::unverifiableSubject($subject->getMorphClass());
            }

            $this->assertSameBatch(
                (int) $sessionInstance->batch_id,
                $enrollment,
                'assignment instance '.$subject->getKey(),
            );

            return;
        }

        // --- Customer-owned -------------------------------------------------
        $subjectEnrollmentId = $subject->getAttribute('enrollment_id');
        $subjectCustomerId = $subject->getAttribute('customer_id');

        if ($subjectEnrollmentId === null && $subjectCustomerId === null) {
            throw CustomerIsolationException::unverifiableSubject($subject->getMorphClass());
        }

        if ($subjectEnrollmentId !== null && (int) $subjectEnrollmentId !== (int) $enrollment->getKey()) {
            throw CustomerIsolationException::mismatch(
                'subject '.$subject->getMorphClass(),
                'enrolment '.$enrollment->getKey(),
                'enrolment '.$subjectEnrollmentId,
            );
        }

        if ($subjectCustomerId !== null && (int) $subjectCustomerId !== (int) $customer->getKey()) {
            throw CustomerIsolationException::mismatch(
                'subject '.$subject->getMorphClass(),
                'customer '.$customer->getKey(),
                'customer '.$subjectCustomerId,
            );
        }
    }

    private function assertSameBatch(int $batchId, Enrollment $enrollment, string $what): void
    {
        if ($batchId !== (int) $enrollment->batch_id) {
            throw CustomerIsolationException::mismatch(
                $what,
                'batch '.$enrollment->batch_id,
                'batch '.$batchId,
            );
        }
    }
}
