<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Access\AccessGrantService;
use App\Domain\Forms\FormBuilderService;
use App\Domain\Forms\FormPublishingService;
use App\Domain\Forms\SubmissionService;
use App\Enums\ActorSource;
use App\Enums\AuditAction;
use App\Enums\GrantAbility;
use App\Enums\QuestionType;
use App\Exceptions\CustomerIsolationException;
use App\Models\Customer;
use App\Models\Enrollment;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\FormVersion;
use App\Models\Question;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Submissions bind to the exact version presented, and never resolve against
 * "the current version" afterwards.
 */
class SubmissionBindingTest extends TestCase
{
    use RefreshDatabase;

    private FormBuilderService $builder;

    private FormPublishingService $publishing;

    private SubmissionService $submissions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
        $this->builder = app(FormBuilderService::class);
        $this->publishing = app(FormPublishingService::class);
        $this->submissions = app(SubmissionService::class);
    }

    /**
     * @return array{0: FormVersion, 1: Question}
     */
    private function publishedVersion(?FormTemplate $template = null, string $label = 'Original question'): array
    {
        $template ??= FormTemplate::factory()->create();
        $version = $this->builder->createDraftVersion($template);
        $section = $this->builder->addSection($version, 'S', 0);
        $question = $this->builder->addQuestion($version, $section, QuestionType::Text, $label, 0);
        $this->publishing->publish($version->fresh(), $this->admin());

        return [$version->fresh(), $question];
    }

    // --- Version binding ---------------------------------------------------

    #[Test]
    public function a_submission_binds_to_the_version_it_was_presented(): void
    {
        [$version] = $this->publishedVersion();
        $enrollment = Enrollment::factory()->create();

        $submission = $this->submissions->startDraft($enrollment, $version, $this->admin());

        $this->assertSame($version->getKey(), $submission->form_version_id);
        $this->assertTrue($submission->formVersion->is($version));
    }

    #[Test]
    public function publishing_a_new_version_does_not_move_an_existing_submission(): void
    {
        // The headline historical guarantee.
        $template = FormTemplate::factory()->create();
        [$v1] = $this->publishedVersion($template, 'Original question');
        $enrollment = Enrollment::factory()->create();

        $submission = $this->submissions->startDraft($enrollment, $v1, $this->admin());
        $this->submissions->submit($submission, $this->admin());

        // Now the form changes.
        $v2 = $this->publishing->startNextVersion($template);
        $section = $this->builder->addSection($v2, 'Revised', 0);
        $this->builder->addQuestion($v2, $section, QuestionType::Text, 'Reworded question', 0);
        $this->publishing->publish($v2->fresh(), $this->admin());

        $reloaded = $submission->fresh();

        $this->assertSame($v1->getKey(), $reloaded->form_version_id);
        $this->assertSame(1, $reloaded->formVersion->version_number);
        $this->assertSame('Original question', $reloaded->formVersion->questions()->firstOrFail()->label);
        // And the template's "current" version has moved on without it.
        $this->assertSame($v2->getKey(), $template->fresh()->publishedVersion()?->getKey());
    }

    #[Test]
    public function a_draft_version_cannot_be_submitted_against(): void
    {
        $version = $this->builder->createDraftVersion(FormTemplate::factory()->create());

        $this->expectException(RuntimeException::class);

        $this->submissions->startDraft(Enrollment::factory()->create(), $version, $this->admin());
    }

    #[Test]
    public function an_answered_version_can_never_be_removed(): void
    {
        [$version] = $this->publishedVersion();
        $this->submissions->startDraft(Enrollment::factory()->create(), $version, $this->admin());

        $this->expectException(QueryException::class);

        DB::table('form_versions')->where('id', $version->getKey())->delete();
    }

    // --- Ownership ---------------------------------------------------------

    #[Test]
    public function the_redundant_customer_id_is_taken_from_the_enrolment(): void
    {
        [$version] = $this->publishedVersion();
        $customer = Customer::factory()->create();
        $enrollment = Enrollment::factory()->create(['customer_id' => $customer->getKey()]);

        $submission = $this->submissions->startDraft($enrollment, $version, $this->admin());

        $this->assertSame($customer->getKey(), $submission->customer_id);
        $this->assertSame((int) $submission->enrollment->customer_id, (int) $submission->customer_id);
    }

    #[Test]
    public function a_submissions_customer_can_never_be_reassigned(): void
    {
        [$version] = $this->publishedVersion();
        $submission = $this->submissions->startDraft(Enrollment::factory()->create(), $version, $this->admin());

        $this->expectException(RuntimeException::class);

        $submission->update(['customer_id' => Customer::factory()->create()->getKey()]);
    }

    #[Test]
    public function a_submission_cannot_be_claimed_by_another_customer(): void
    {
        [$version] = $this->publishedVersion();
        $a = Customer::factory()->create();
        $b = Customer::factory()->create();
        $submission = $this->submissions->startDraft(
            Enrollment::factory()->create(['customer_id' => $a->getKey()]), $version, $this->admin(),
        );

        $this->expectException(CustomerIsolationException::class);

        $this->submissions->assertBelongsToCustomer($submission, $b->getKey());
    }

    #[Test]
    public function a_grant_for_another_enrolment_cannot_start_a_submission(): void
    {
        [$version] = $this->publishedVersion();
        $customer = Customer::factory()->create();
        $mine = Enrollment::factory()->create(['customer_id' => $customer->getKey()]);
        $other = Enrollment::factory()->create(['customer_id' => $customer->getKey()]);

        $grant = app(AccessGrantService::class)->issue(
            $customer, $other, $other, GrantAbility::CompleteForm, now()->addDay(), $this->admin(),
        )->grant;

        $this->expectException(CustomerIsolationException::class);

        $this->submissions->startDraft($mine, $version, null, $grant);
    }

    #[Test]
    public function a_submission_through_a_grant_records_the_grant_not_a_user(): void
    {
        [$version] = $this->publishedVersion();
        $customer = Customer::factory()->create();
        $enrollment = Enrollment::factory()->create(['customer_id' => $customer->getKey()]);

        $grant = app(AccessGrantService::class)->issue(
            $customer, $enrollment, $enrollment, GrantAbility::CompleteForm, now()->addDay(), $this->admin(),
        )->grant;

        $submission = $this->submissions->startDraft($enrollment, $version, null, $grant);

        $this->assertSame(ActorSource::ExternalGrant, $submission->source);
        $this->assertSame($grant->getKey(), $submission->access_grant_id);
        $this->assertNull($submission->created_by);
    }

    // --- Answers -----------------------------------------------------------

    #[Test]
    public function an_answer_must_belong_to_the_submissions_version(): void
    {
        [$v1] = $this->publishedVersion();
        [, $foreignQuestion] = $this->publishedVersion(null, 'A question from another form');

        $submission = $this->submissions->startDraft(Enrollment::factory()->create(), $v1, $this->admin());

        $this->expectException(InvalidArgumentException::class);

        $this->submissions->answer($submission, $foreignQuestion, ['value_text' => 'Wrong form']);
    }

    #[Test]
    public function typed_values_are_stored_in_their_own_columns(): void
    {
        $template = FormTemplate::factory()->create();
        $version = $this->builder->createDraftVersion($template);
        $section = $this->builder->addSection($version, 'S', 0);

        $text = $this->builder->addQuestion($version, $section, QuestionType::Text, 'Text', 0);
        $number = $this->builder->addQuestion($version, $section, QuestionType::Number, 'Number', 1);
        $date = $this->builder->addQuestion($version, $section, QuestionType::Date, 'Date', 2);
        $boolean = $this->builder->addQuestion($version, $section, QuestionType::Boolean, 'Boolean', 3);

        $this->publishing->publish($version->fresh(), $this->admin());
        $submission = $this->submissions->startDraft(Enrollment::factory()->create(), $version->fresh(), $this->admin());

        $this->submissions->answer($submission, $text, ['value_text' => 'Grow the business']);
        $this->submissions->answer($submission, $number, ['value_number' => 42.5]);
        $this->submissions->answer($submission, $date, ['value_date' => '2026-10-01']);
        $this->submissions->answer($submission, $boolean, ['value_boolean' => true]);

        $answers = $submission->fresh()->answers()->get()->keyBy('question_id');

        $this->assertSame('Grow the business', $answers[$text->getKey()]->value_text);
        $this->assertSame('42.5000', $answers[$number->getKey()]->value_number);
        $this->assertSame('2026-10-01', $answers[$date->getKey()]->value_date->toDateString());
        $this->assertTrue($answers[$boolean->getKey()]->value_boolean);
    }

    #[Test]
    public function one_answer_per_question_per_submission(): void
    {
        [$version, $question] = $this->publishedVersion();
        $submission = $this->submissions->startDraft(Enrollment::factory()->create(), $version, $this->admin());

        $this->submissions->answer($submission, $question, ['value_text' => 'First']);
        $this->submissions->answer($submission, $question, ['value_text' => 'Corrected']);

        $this->assertSame(1, $submission->fresh()->answers()->count());
        $this->assertSame('Corrected', $submission->fresh()->answers()->firstOrFail()->value_text);
    }

    #[Test]
    public function an_option_from_another_question_cannot_be_selected(): void
    {
        $template = FormTemplate::factory()->create();
        $version = $this->builder->createDraftVersion($template);
        $section = $this->builder->addSection($version, 'S', 0);
        $q1 = $this->builder->addQuestion($version, $section, QuestionType::SelectOne, 'Q1', 0);
        $q2 = $this->builder->addQuestion($version, $section, QuestionType::SelectOne, 'Q2', 1);
        $this->builder->addOption($q1, 'a', 'A', 0);
        $foreign = $this->builder->addOption($q2, 'b', 'B', 0);

        $this->publishing->publish($version->fresh(), $this->admin());
        $submission = $this->submissions->startDraft(Enrollment::factory()->create(), $version->fresh(), $this->admin());

        $this->expectException(InvalidArgumentException::class);

        $this->submissions->answer($submission, $q1, [], [$foreign]);
    }

    #[Test]
    public function a_historical_selection_is_read_through_the_stored_option(): void
    {
        // Not through "the question's current options" - which is why editing
        // a published version is impossible in the first place.
        $template = FormTemplate::factory()->create();
        $version = $this->builder->createDraftVersion($template);
        $section = $this->builder->addSection($version, 'S', 0);
        $question = $this->builder->addQuestion($version, $section, QuestionType::SelectOne, 'Pick', 0);
        $option = $this->builder->addOption($question, 'yes', 'Yes, definitely', 0);

        $this->publishing->publish($version->fresh(), $this->admin());
        $submission = $this->submissions->startDraft(Enrollment::factory()->create(), $version->fresh(), $this->admin());
        $answer = $this->submissions->answer($submission, $question, [], [$option]);

        // A later version reuses the same machine value with different wording.
        $v2 = $this->publishing->startNextVersion($template);
        $s2 = $this->builder->addSection($v2, 'S', 0);
        $q2 = $this->builder->addQuestion($v2, $s2, QuestionType::SelectOne, 'Pick', 0);
        $this->builder->addOption($q2, 'yes', 'Completely reworded', 0);
        $this->publishing->publish($v2->fresh(), $this->admin());

        $selected = $answer->fresh()->answerOptions()->with('questionOption')->firstOrFail();

        $this->assertSame('Yes, definitely', $selected->questionOption->label);
    }

    #[Test]
    public function submitting_is_audited_and_records_the_bound_version(): void
    {
        [$version] = $this->publishedVersion();
        $actor = $this->admin();
        $submission = $this->submissions->startDraft(Enrollment::factory()->create(), $version, $actor);

        $this->submissions->submit($submission, $actor);

        $this->assertTrue($submission->fresh()->isSubmitted());
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::SubmissionSubmitted->value,
            'auditable_id' => $submission->getKey(),
        ]);
    }

    #[Test]
    public function repeat_submissions_of_the_same_form_are_permitted(): void
    {
        // SOW section 9.2 q12 contemplates repeating the 35-question assessment,
        // so there is deliberately no unique key preventing it.
        [$version] = $this->publishedVersion();
        $enrollment = Enrollment::factory()->create();

        $this->submissions->startDraft($enrollment, $version, $this->admin());
        $this->submissions->startDraft($enrollment, $version, $this->admin());

        $this->assertSame(2, FormSubmission::query()->where('enrollment_id', $enrollment->getKey())->count());
    }
}
