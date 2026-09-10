<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Forms\SubmissionService;
use App\Enums\GrantAbility;
use App\Exceptions\AccessGrantDeniedException;
use App\Models\FormSubmission;
use App\Models\FormVersion;
use App\Models\Question;
use App\Models\QuestionOption;

/**
 * Completing a form through a scoped link, with no account.
 *
 * This class adds NO form logic. Validation, version binding, answer coherence
 * and scoring all remain in the Phase 3 engine; everything here is the
 * external boundary in front of it:
 *
 *   1. the token is valid, unexpired, unrevoked and unspent
 *   2. the grant's ability is complete_form
 *   3. the grant's subject is the exact form version being answered
 *   4. the submission being written belongs to the grant's enrolment
 *   5. the submission is bound to the grant's form version
 *
 * Every one of those is re-checked on EVERY call. There is no session, so
 * there is nothing to trust between calls: the token is presented again and
 * the whole chain runs again from the database.
 *
 * The resulting rows carry the actor triple as source = external_grant,
 * created_by = NULL, access_grant_id = the grant - which is what makes "did
 * staff enter this, or did the owner?" answerable afterwards.
 */
class ExternalFormSubmissionService
{
    public function __construct(
        private readonly AccessGrantRedeemer $redeemer,
        private readonly SubmissionService $submissions,
    ) {}

    /**
     * Open the form: start a draft, or resume the one already in progress.
     *
     * Deliberately does NOT consume a use. A single-use grant must survive
     * being opened, half-filled, closed and reopened - the use is spent when
     * the participant actually submits.
     */
    public function open(string $token, ?string $ip = null): FormSubmission
    {
        $scope = $this->redeemer->authorize($token, GrantAbility::CompleteForm, ip: $ip);
        $version = $this->assertSubjectIsAFormVersion($scope);

        $existing = FormSubmission::query()
            ->where('enrollment_id', $scope->enrollmentId())
            ->where('form_version_id', $version->getKey())
            ->where('status', 'draft')
            ->latest('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $this->submissions->startDraft(
            $scope->grant->enrollment,
            $version,
            grant: $scope->grant,
        );
    }

    /**
     * Record one answer. "Saved half-done and finished later" is exactly this
     * call, repeated.
     *
     * @param  array<string, mixed>  $value
     * @param  array<int, QuestionOption>  $options
     */
    public function answer(
        string $token,
        FormSubmission $submission,
        Question $question,
        array $value = [],
        array $options = [],
        ?string $ip = null,
    ): void {
        $scope = $this->redeemer->authorize($token, GrantAbility::CompleteForm, ip: $ip);
        $version = $this->assertSubjectIsAFormVersion($scope);

        $this->assertSubmissionIsInScope($submission, $scope, $version);

        $this->submissions->answer($submission, $question, $value, $options);
    }

    /**
     * Submit. THIS is what consumes a use of the grant.
     */
    public function submit(string $token, FormSubmission $submission, ?string $ip = null): FormSubmission
    {
        $scope = $this->redeemer->authorize($token, GrantAbility::CompleteForm, ip: $ip);
        $version = $this->assertSubjectIsAFormVersion($scope);

        $this->assertSubmissionIsInScope($submission, $scope, $version);

        // Re-presented and consumed atomically. A replayed link loses here.
        $consumed = $this->redeemer->redeem($token, GrantAbility::CompleteForm, ip: $ip);

        return $this->submissions->submit($submission, grant: $consumed->grant);
    }

    /**
     * A complete_form grant must point at a form VERSION, never a template.
     *
     * Binding to the template would resolve "the current version" at
     * redemption time, which is precisely the historical-integrity bug the
     * form engine exists to prevent: publishing version 2 would silently
     * change what an outstanding link asks.
     */
    private function assertSubjectIsAFormVersion(RedeemedGrant $scope): FormVersion
    {
        if (! $scope->subject instanceof FormVersion) {
            throw AccessGrantDeniedException::because(AccessGrantDeniedException::REASON_WRONG_SUBJECT);
        }

        return $scope->subject;
    }

    /**
     * The submission must be this enrolment's, and must be bound to the exact
     * version the grant names.
     *
     * The submission id arrives from the caller, so it is the one identifier
     * an attacker controls. Neither its enrolment nor its version is taken on
     * trust.
     */
    private function assertSubmissionIsInScope(
        FormSubmission $submission,
        RedeemedGrant $scope,
        FormVersion $version,
    ): void {
        if ((int) $submission->enrollment_id !== $scope->enrollmentId()) {
            throw AccessGrantDeniedException::because(AccessGrantDeniedException::REASON_OUT_OF_SCOPE);
        }

        if ((int) $submission->customer_id !== $scope->customerId()) {
            throw AccessGrantDeniedException::because(AccessGrantDeniedException::REASON_OUT_OF_SCOPE);
        }

        if ((int) $submission->form_version_id !== (int) $version->getKey()) {
            throw AccessGrantDeniedException::because(AccessGrantDeniedException::REASON_WRONG_SUBJECT);
        }

        // A submitted form is not reopened through a link. Amendment is an
        // internal act with its own audit entry.
        if ($submission->status !== 'draft') {
            throw AccessGrantDeniedException::because(AccessGrantDeniedException::REASON_OUT_OF_SCOPE);
        }
    }
}
