<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Domain\Access\AccessGrantService;
use App\Domain\Access\ExternalFormSubmissionService;
use App\Domain\Access\IssuedGrant;
use App\Domain\Forms\FormBuilderService;
use App\Domain\Forms\FormPublishingService;
use App\Enums\ActorSource;
use App\Enums\GrantAbility;
use App\Enums\QuestionType;
use App\Exceptions\AccessGrantDeniedException;
use App\Exceptions\CustomerIsolationException;
use App\Models\Customer;
use App\Models\Enrollment;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\FormVersion;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Completing a form through a scoped link.
 *
 * This is the whole point of the no-login decision: a participant who has no
 * account receives a link, fills a form in, and the row that results says
 * plainly that they - not staff - entered it.
 *
 * Nothing here relaxes the Phase 3 engine. The version binding, the answer
 * coherence rules and the scoring path are untouched; what is added is the
 * boundary in front of them.
 */
class ExternalFormSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private ExternalFormSubmissionService $external;

    private AccessGrantService $grants;

    private FormBuilderService $builder;

    private FormPublishingService $publishing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
        $this->external = app(ExternalFormSubmissionService::class);
        $this->grants = app(AccessGrantService::class);
        $this->builder = app(FormBuilderService::class);
        $this->publishing = app(FormPublishingService::class);
    }

    /**
     * @return array{0: FormVersion, 1: Question}
     */
    private function publishedVersion(): array
    {
        $template = FormTemplate::factory()->create();
        $version = $this->builder->createDraftVersion($template);
        $section = $this->builder->addSection($version, 'Section', 0);
        $question = $this->builder->addQuestion($version, $section, QuestionType::Text, 'Your goal', 0);
        $this->publishing->publish($version->fresh(), $this->admin());

        return [$version->fresh(), $question];
    }

    /**
     * @return array{0: IssuedGrant, 1: FormVersion, 2: Question, 3: Enrollment}
     */
    private function linkFor(?Customer $customer = null, int $maxUses = 1): array
    {
        $customer ??= Customer::factory()->create();
        $enrollment = Enrollment::factory()->create(['customer_id' => $customer->getKey()]);
        [$version, $question] = $this->publishedVersion();

        $issued = $this->grants->issue(
            $customer,
            $enrollment,
            $version,
            GrantAbility::CompleteForm,
            now()->addDays(7),
            $this->admin(),
            maxUses: $maxUses,
        );

        return [$issued, $version, $question, $enrollment];
    }

    // --- The flow ----------------------------------------------------------

    #[Test]
    public function a_link_completes_a_form_from_open_to_submitted(): void
    {
        [$issued, $version, $question, $enrollment] = $this->linkFor();
        $token = $issued->plaintextToken;

        $submission = $this->external->open($token);
        $this->assertSame('draft', $submission->status);

        $this->external->answer($token, $submission, $question, ['value_text' => 'Double revenue']);

        $submitted = $this->external->submit($token, $submission->fresh());

        $this->assertSame('submitted', $submitted->status);
        $this->assertSame($version->getKey(), $submitted->form_version_id);
        $this->assertSame($enrollment->getKey(), $submitted->enrollment_id);
        $this->assertSame((int) $enrollment->customer_id, (int) $submitted->customer_id);
        $this->assertSame('Double revenue', $submitted->answers()->first()->value_text);
    }

    #[Test]
    public function the_row_says_the_owner_entered_it_not_staff(): void
    {
        [$issued, , $question] = $this->linkFor();
        $token = $issued->plaintextToken;

        $submission = $this->external->open($token);
        $this->external->answer($token, $submission, $question, ['value_text' => 'Mine']);
        $submitted = $this->external->submit($token, $submission->fresh());

        // The actor triple. This is what makes "did staff enter this, or did
        // the owner?" answerable a year later.
        $this->assertSame(ActorSource::ExternalGrant, $submitted->source);
        $this->assertSame($issued->grant->getKey(), $submitted->access_grant_id);
        $this->assertNull($submitted->created_by);
        $this->assertTrue($submitted->wasWrittenExternally());
    }

    #[Test]
    public function opening_the_form_twice_resumes_the_same_draft(): void
    {
        [$issued] = $this->linkFor();
        $token = $issued->plaintextToken;

        $first = $this->external->open($token);
        $second = $this->external->open($token);

        // "Saved half-done and finished later" - and a single-use grant
        // survives being opened, because opening spends nothing.
        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(0, $issued->grant->fresh()->use_count);
        $this->assertSame(1, FormSubmission::query()->count());
    }

    #[Test]
    public function submitting_spends_the_link(): void
    {
        [$issued, , $question] = $this->linkFor();
        $token = $issued->plaintextToken;

        $submission = $this->external->open($token);
        $this->external->answer($token, $submission, $question, ['value_text' => 'Done']);
        $this->external->submit($token, $submission->fresh());

        $this->assertSame(1, $issued->grant->fresh()->use_count);

        // And a replay of the whole flow is refused.
        $this->expectException(AccessGrantDeniedException::class);
        $this->external->open($token);
    }

    #[Test]
    public function a_submitted_form_cannot_be_reopened_through_the_link(): void
    {
        [$issued, , $question] = $this->linkFor(maxUses: 3);
        $token = $issued->plaintextToken;

        $submission = $this->external->open($token);
        $this->external->answer($token, $submission, $question, ['value_text' => 'Done']);
        $submitted = $this->external->submit($token, $submission->fresh());

        // Amending a submitted form is an internal act with its own audit
        // entry. A link cannot do it.
        $this->expectException(AccessGrantDeniedException::class);
        $this->external->answer($token, $submitted, $question, ['value_text' => 'Changed my mind']);
    }

    // --- Historical behaviour is preserved ---------------------------------

    #[Test]
    public function the_submission_binds_to_the_exact_version_the_link_named(): void
    {
        [$issued, $v1, $question] = $this->linkFor();
        $token = $issued->plaintextToken;

        $submission = $this->external->open($token);
        $this->external->answer($token, $submission, $question, ['value_text' => 'Answer to v1']);

        // The curriculum moves on while the link is outstanding.
        $template = $v1->formTemplate()->first();
        $v2 = $this->publishing->startNextVersion($template);
        $section = $this->builder->addSection($v2, 'Section', 0);
        $this->builder->addQuestion($v2, $section, QuestionType::Text, 'A different question', 0);
        $this->publishing->publish($v2->fresh(), $this->admin());

        $submitted = $this->external->submit($token, $submission->fresh());

        // Publishing version 2 must not change what an outstanding link asked.
        $this->assertSame($v1->getKey(), $submitted->form_version_id);
        $this->assertNotSame($v2->getKey(), $submitted->form_version_id);
    }

    #[Test]
    public function a_grant_pointed_at_a_template_rather_than_a_version_is_refused(): void
    {
        $customer = Customer::factory()->create();
        $enrollment = Enrollment::factory()->create(['customer_id' => $customer->getKey()]);
        $template = FormTemplate::factory()->create();

        // Binding to a template would resolve "the current version" at
        // redemption time, which is the historical-integrity bug the engine
        // exists to prevent. GrantScope fails closed on a form template, so
        // such a grant cannot even be issued - the refusal lands one step
        // earlier than the redemption boundary, which is the better place for
        // it.
        $this->expectException(CustomerIsolationException::class);

        $this->grants->issue(
            $customer, $enrollment, $template, GrantAbility::CompleteForm,
            now()->addDays(7), $this->admin(),
        );
    }

    // --- Isolation ---------------------------------------------------------

    #[Test]
    public function a_link_cannot_write_another_businesss_submission(): void
    {
        $a = Customer::factory()->create();
        $b = Customer::factory()->create();

        [$issuedToA, $version, $question] = $this->linkFor($a);

        // B has a draft against the very same form version - shared curriculum
        // is exactly the case where an id substitution looks plausible.
        $bEnrollment = Enrollment::factory()->create(['customer_id' => $b->getKey()]);
        $issuedToB = $this->grants->issue(
            $b, $bEnrollment, $version, GrantAbility::CompleteForm, now()->addDays(7), $this->admin(),
        );
        $bDraft = $this->external->open($issuedToB->plaintextToken);

        $this->expectException(AccessGrantDeniedException::class);

        $this->external->answer($issuedToA->plaintextToken, $bDraft, $question, ['value_text' => 'Not mine']);
    }

    #[Test]
    public function a_link_cannot_submit_another_businesss_draft(): void
    {
        $a = Customer::factory()->create();
        $b = Customer::factory()->create();

        [$issuedToA, $version] = $this->linkFor($a);

        $bEnrollment = Enrollment::factory()->create(['customer_id' => $b->getKey()]);
        $issuedToB = $this->grants->issue(
            $b, $bEnrollment, $version, GrantAbility::CompleteForm, now()->addDays(7), $this->admin(),
        );
        $bDraft = $this->external->open($issuedToB->plaintextToken);

        $this->expectException(AccessGrantDeniedException::class);

        $this->external->submit($issuedToA->plaintextToken, $bDraft);
    }

    #[Test]
    public function a_link_cannot_be_used_for_an_action_it_was_not_issued_for(): void
    {
        $customer = Customer::factory()->create();
        $enrollment = Enrollment::factory()->create(['customer_id' => $customer->getKey()]);

        $termsLink = $this->grants->issue(
            $customer, $enrollment, $enrollment, GrantAbility::AcceptTerms,
            now()->addDays(7), $this->admin(),
        );

        $this->expectException(AccessGrantDeniedException::class);
        $this->external->open($termsLink->plaintextToken);
    }

    #[Test]
    public function an_expired_link_writes_nothing(): void
    {
        [$issued] = $this->linkFor();
        $issued->grant->forceFill(['expires_at' => now()->subMinute()])->save();

        $before = FormSubmission::query()->count();

        try {
            $this->external->open($issued->plaintextToken);
            $this->fail('Expected the expired link to be refused.');
        } catch (AccessGrantDeniedException) {
            // expected
        }

        $this->assertSame($before, FormSubmission::query()->count());
    }

    #[Test]
    public function a_revoked_link_writes_nothing(): void
    {
        [$issued] = $this->linkFor();
        $this->grants->revoke($issued->grant, $this->admin(), 'Sent to the wrong contact');

        $before = FormSubmission::query()->count();

        try {
            $this->external->open($issued->plaintextToken);
            $this->fail('Expected the revoked link to be refused.');
        } catch (AccessGrantDeniedException) {
            // expected
        }

        $this->assertSame($before, FormSubmission::query()->count());
    }

    // --- What external access never becomes --------------------------------

    #[Test]
    public function completing_a_form_externally_creates_no_account_and_no_session(): void
    {
        [$issued, , $question] = $this->linkFor();
        $token = $issued->plaintextToken;
        $usersBefore = User::query()->count();

        $submission = $this->external->open($token);
        $this->external->answer($token, $submission, $question, ['value_text' => 'x']);
        $this->external->submit($token, $submission->fresh());

        $this->assertSame($usersBefore, User::query()->count());
        $this->assertFalse(Auth::check());
        $this->assertNull(Auth::user());
    }
}
