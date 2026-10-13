<?php

declare(strict_types=1);

namespace Tests\Feature\Forms;

use App\Domain\Forms\ParticipantQuestionnaire;
use App\Domain\Forms\QuestionVisibility;
use App\Domain\Forms\SubmissionService;
use App\Enums\ActorSource;
use App\Enums\QuestionType;
use App\Livewire\Admin\FormTemplates;
use App\Livewire\Customers\Forms as FormsScreen;
use App\Livewire\Customers\ManageCustomer;
use App\Livewire\Forms\FormRenderer;
use App\Models\AccessGrant;
use App\Models\Answer;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\Enrollment;
use App\Models\FormSection;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\FormVersion;
use App\Models\Program;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\User;
use Database\Seeders\ParticipantQuestionnaireSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Participant Business Analysis Questionnaire, end to end.
 *
 * Three claims are worth pinning, and they are what this file is organised
 * around:
 *
 *   1. The instrument in the database IS the instrument in the source
 *      document - twelve sections, every question, every option, in order -
 *      and seeding it again neither duplicates it nor rewrites it.
 *   2. Entering a confirmed customer into a batch gives them the
 *      questionnaire and exactly one link, with no second action and no
 *      duplicate when the customer is edited afterwards.
 *   3. That link lets a business complete the questionnaire with no account,
 *      and the answers land against THAT business and nobody else.
 */
class ParticipantQuestionnaireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
        Mail::fake();
    }

    /*
    |--------------------------------------------------------------------------
    | A. The instrument
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_seeds_the_whole_instrument_from_the_source_document(): void
    {
        $this->seedQuestionnaire();

        $template = app(ParticipantQuestionnaire::class)->template();

        $this->assertNotNull($template);
        $this->assertSame(
            'Business Mastery Program (BMP) — Participant Business Analysis Questionnaire',
            $template->name,
        );
        $this->assertStringContainsString('Raajul Parekh', (string) $template->description);
        $this->assertNull($template->customer_id, 'The questionnaire is shared curriculum, not one business\'s form.');

        $version = $template->publishedVersion();

        $this->assertNotNull($version, 'An unpublished questionnaire cannot be answered.');
        $this->assertTrue($version->isPublished());

        $this->assertSame(12, $version->sections()->count());
        $this->assertSame(49, $version->questions()->count());
    }

    #[Test]
    public function the_sections_are_in_the_documents_own_order(): void
    {
        $this->seedQuestionnaire();

        $titles = $this->version()->sections()->orderBy('position')->pluck('title')->all();

        $this->assertSame([
            'Basic Business Details',
            'Vision & Goals',
            'Challenges & Bottlenecks',
            'Sales & Customers',
            'Marketing & Lead System',
            'Operations & Systems',
            'Team & Delegation',
            'Finance & Profitability',
            'Automation Readiness',
            'Owner Lifestyle & Expectations',
            'Growth Opportunities & Program Expectation',
            'Business Readiness Score',
        ], $titles);
    }

    #[Test]
    public function the_answer_options_are_the_documents_own(): void
    {
        $this->seedQuestionnaire();

        $this->assertSame(
            ['Less than 1 year', '1–3 years', '3–7 years', '7+'],
            $this->optionLabels('years_in_business'),
        );

        $this->assertSame(
            ['₹1–2 Cr', '₹2–3 Cr', '₹3–5 Cr', '₹5–7 Cr', '₹7–10 Cr', '₹10 Cr+'],
            $this->optionLabels('annual_turnover'),
        );

        $this->assertSame(
            ['Sales', 'Leads', 'Marketing', 'Team', 'Profit', 'Systems', 'Time', 'Retention', 'Operations'],
            $this->optionLabels('current_challenges'),
        );

        $this->assertSame(
            ['Instagram', 'LinkedIn', 'Google', 'WhatsApp', 'Offline Networking', 'Other'],
            $this->optionLabels('best_platform'),
        );

        $this->assertSame(
            ['₹0', '₹10k–₹30k', '₹30k–₹75k', '₹75k–₹2L', '₹2L+'],
            $this->optionLabels('marketing_budget'),
        );

        $this->assertSame(['Yes', 'No', 'Partially'], $this->optionLabels('has_sops'));
        $this->assertSame(['Operator', 'Manager', 'Leader', 'Visionary'], $this->optionLabels('self_leadership_level'));
    }

    #[Test]
    public function single_choice_questions_are_radios_and_multiple_choice_are_checkboxes(): void
    {
        // The TYPE is what decides the control, in both renderers. Pinning the
        // type is therefore pinning the control, without asserting on markup.
        $this->seedQuestionnaire();

        foreach (['years_in_business', 'team_size', 'main_sales_source', 'has_sops', 'business_stage'] as $key) {
            $this->assertSame(QuestionType::SelectOne, $this->question($key)->type, $key);
        }

        foreach (['current_challenges', 'best_platform', 'tools_used'] as $key) {
            $this->assertSame(QuestionType::SelectMany, $this->question($key)->type, $key);
        }

        foreach (['business_vision', 'why_join_bmp', 'biggest_bottleneck', 'ideal_customer'] as $key) {
            $this->assertSame(QuestionType::Textarea, $this->question($key)->type, $key);
        }

        foreach (['dedicated_sales_team', 'structured_marketing_plan', 'track_profits_monthly', 'ready_to_implement'] as $key) {
            $this->assertSame(QuestionType::Boolean, $this->question($key)->type, $key);
        }
    }

    #[Test]
    public function the_three_goals_are_three_answers_rather_than_one_box(): void
    {
        $this->seedQuestionnaire();

        foreach (['goal_1', 'goal_2', 'goal_3'] as $key) {
            $question = $this->question($key);

            $this->assertSame(QuestionType::Text, $question->type);
            $this->assertStringContainsString('Top 3 goals for next 12 months', (string) $question->label);
        }
    }

    #[Test]
    public function nothing_on_the_questionnaire_carries_a_score(): void
    {
        // The last section is titled "Business Readiness Score" and still
        // scores nothing: the client has defined no numeric mapping for any of
        // these categorical answers, and the form must not invent one.
        $this->seedQuestionnaire();

        $version = $this->version();

        $this->assertFalse((bool) $version->formTemplate->is_scored);

        $this->assertSame(
            0,
            $version->questions()->whereNotNull('max_score')->count(),
            'A scored question would imply a mapping nobody has defined.',
        );

        $this->assertSame(
            0,
            QuestionOption::query()
                ->whereIn('question_id', $version->questions()->pluck('id'))
                ->whereNotNull('score_value')
                ->count(),
        );
    }

    #[Test]
    public function only_the_identifying_section_is_required(): void
    {
        $this->seedQuestionnaire();

        $required = $this->version()->questions()->where('is_required', true)->pluck('bank_key')->all();

        $this->assertSame([
            'full_name',
            'business_name',
            'business_category',
            'years_in_business',
            'business_location',
            'team_size',
            'annual_turnover',
            'main_products_services',
        ], $required);
    }

    #[Test]
    public function the_other_platform_question_is_revealed_only_by_the_other_option(): void
    {
        $this->seedQuestionnaire();

        $platform = $this->question('best_platform');
        $other = $this->question('best_platform_other');
        $visibility = app(QuestionVisibility::class);

        $this->assertFalse($other->is_required, 'A question nobody can see must not block a submission.');
        $this->assertNotNull($other->visible_when);

        $otherOption = $platform->options()->where('value', 'other')->sole();
        $instagram = $platform->options()->where('value', 'instagram')->sole();

        $this->assertFalse($visibility->isVisible($other, []));
        $this->assertFalse($visibility->isVisible($other, [$platform->getKey() => [$instagram->getKey()]]));
        $this->assertTrue($visibility->isVisible($other, [$platform->getKey() => [$otherOption->getKey()]]));

        // Everything else on the form is unconditional.
        $this->assertSame(
            1,
            $this->version()->questions()->whereNotNull('visible_when')->count(),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | B. Seeding is idempotent, and never rewrites history
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function seeding_twice_creates_no_second_form_version_or_question(): void
    {
        $this->seedQuestionnaire();

        $before = [
            'templates' => FormTemplate::query()->count(),
            'versions' => $this->version()->formTemplate->versions()->count(),
            'sections' => FormSection::query()->count(),
            'questions' => Question::query()->count(),
            'options' => QuestionOption::query()->count(),
            'version_id' => $this->version()->getKey(),
        ];

        $this->seedQuestionnaire();
        $this->seedQuestionnaire();

        $this->assertSame($before['templates'], FormTemplate::query()->count());
        $this->assertSame($before['versions'], $this->version()->formTemplate->versions()->count());
        $this->assertSame($before['sections'], FormSection::query()->count());
        $this->assertSame($before['questions'], Question::query()->count());
        $this->assertSame($before['options'], QuestionOption::query()->count());
        $this->assertSame($before['version_id'], $this->version()->getKey(), 'The published version must not be replaced.');
    }

    #[Test]
    public function re_seeding_preserves_existing_forms_and_submissions(): void
    {
        $this->seedQuestionnaire();

        $customer = $this->enteredCustomer('C-KEEP');
        $submission = $this->openedSubmissionFor($customer);

        app(SubmissionService::class)->answer(
            $submission,
            $this->question('full_name'),
            ['value_text' => 'Meera Shah'],
        );

        $this->seedQuestionnaire();

        $this->assertDatabaseHas('form_submissions', ['id' => $submission->getKey()]);
        $this->assertSame(
            'Meera Shah',
            Answer::query()
                ->where('form_submission_id', $submission->getKey())
                ->where('question_id', $this->question('full_name')->getKey())
                ->value('value_text'),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | C. Entering a customer assigns the questionnaire
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function entering_a_customer_into_a_batch_assigns_the_questionnaire_and_one_link(): void
    {
        $this->seedQuestionnaire();

        $customer = $this->enteredCustomer('C-ENTRY');
        $enrollment = $customer->enrollments()->sole();

        $grants = AccessGrant::query()->where('customer_id', $customer->getKey())->get();

        $this->assertCount(1, $grants, 'One customer, one questionnaire, one link.');
        $this->assertSame((int) $enrollment->getKey(), (int) $grants->first()->enrollment_id);
        $this->assertSame($this->version()->getMorphClass(), $grants->first()->subject_type);
        $this->assertSame((int) $this->version()->getKey(), (int) $grants->first()->subject_id);
        $this->assertSame(1, (int) $grants->first()->max_uses);
        $this->assertTrue($grants->first()->expires_at->isFuture());
    }

    #[Test]
    public function the_link_is_handed_to_the_admin_and_stored_nowhere(): void
    {
        $this->seedQuestionnaire();

        $url = $this->enterCustomer('C-LINK')['url'];
        $token = $this->tokenFrom($url);

        $this->assertStringContainsString('/external/forms/', $url);

        // The plaintext exists in the response and nowhere else: the grant row
        // holds a hash, and the audit entry holds the scope.
        $this->assertDatabaseMissing('access_grants', ['token_hash' => $token]);
        $this->assertSame(
            0,
            AuditLog::query()->where('metadata', 'like', '%'.$token.'%')->count(),
        );
    }

    #[Test]
    public function nothing_is_emailed_when_no_transport_is_configured(): void
    {
        // MAIL_MAILER is `array` here and `log` by default in this project:
        // neither delivers, so the link is issued for the operator to send and
        // no message is pushed through an unconfigured service.
        $this->seedQuestionnaire();

        $this->enterCustomer('C-QUIET');

        Mail::assertNothingSent();
    }

    #[Test]
    public function editing_a_customer_creates_no_second_assignment_or_link(): void
    {
        $this->seedQuestionnaire();

        $customer = $this->enteredCustomer('C-EDIT');

        Livewire::actingAs($this->admin())
            ->test(ManageCustomer::class, ['customer' => $customer])
            ->set('name', 'Renamed Engineering')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, AccessGrant::query()->where('customer_id', $customer->getKey())->count());
        $this->assertSame(1, $customer->enrollments()->count());
        $this->assertSame('Renamed Engineering', (string) $customer->fresh()->name);
    }

    #[Test]
    public function a_business_already_holding_a_live_link_is_not_handed_a_second(): void
    {
        $this->seedQuestionnaire();

        $customer = $this->enteredCustomer('C-ONCE');
        $enrollment = $customer->enrollments()->sole();

        $again = app(ParticipantQuestionnaire::class)->assign($enrollment, $this->admin());

        $this->assertNull($again, 'Issuing again would revoke the link already in their inbox.');
        $this->assertSame(1, AccessGrant::query()->where('customer_id', $customer->getKey())->count());
    }

    #[Test]
    public function an_operator_who_may_not_issue_links_does_not_issue_one(): void
    {
        // Issuing a link is minting a capability, and it asks the same policy
        // here as it does behind the Forms screen's button.
        $this->seedQuestionnaire();

        $customer = $this->enteredCustomer('C-POLICY');
        $enrollment = $customer->enrollments()->sole();

        // Every role this application defines may issue links, so the actor
        // without the ability is one carrying no role at all.
        $powerless = User::factory()->create();

        $this->assertFalse($powerless->can('create', AccessGrant::class));

        $before = AccessGrant::query()->count();

        $this->assertNull(app(ParticipantQuestionnaire::class)->assign($enrollment, $powerless));
        $this->assertSame($before, AccessGrant::query()->count());
    }

    #[Test]
    public function a_customer_can_still_be_entered_before_the_questionnaire_is_seeded(): void
    {
        // The questionnaire is deliberately NOT a precondition for a customer.
        // A database that has not been seeded has no questionnaire, and the
        // business is still a business.
        $customer = $this->enteredCustomer('C-BARE');

        $this->assertSame('active', (string) $customer->status);
        $this->assertSame(1, $customer->enrollments()->count());
        $this->assertSame(0, AccessGrant::query()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | D. The participant completes it, with no account
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_participant_opens_the_questionnaire_with_no_login(): void
    {
        $this->seedQuestionnaire();

        $token = $this->tokenFrom($this->enterCustomer('C-OPEN')['url']);

        // Entering the customer signed an administrator in. The participant is
        // not that administrator and has no session of any kind.
        auth()->logout();

        $this->assertGuest();

        $this->get(route('external.forms.show', ['token' => $token]))
            ->assertOk()
            ->assertSee('Participant Business Analysis Questionnaire', false)
            ->assertSee('Basic Business Details')
            ->assertSee('Business Readiness Score')
            ->assertSee('Current Annual Turnover Range')
            ->assertSee('Self Leadership Level')
            // The conditional question is on the page, hidden, carrying what
            // reveals it - so it appears the moment Other is ticked.
            ->assertSee('data-reveals-question', false)
            ->assertSee('Other — please specify the platform', false)
            // Not an account: nothing from the internal application.
            ->assertDontSee('Dashboard')
            ->assertDontSee('Sign out');
    }

    #[Test]
    public function the_submitted_answers_are_saved_against_the_right_customer_form_and_submission(): void
    {
        $this->seedQuestionnaire();

        $entry = $this->enterCustomer('C-ANSWER');
        $token = $this->tokenFrom($entry['url']);
        $customer = Customer::query()->where('code', 'C-ANSWER')->sole();

        $this->get(route('external.forms.show', ['token' => $token]))->assertOk();

        $this->post(route('external.forms.store', ['token' => $token]), $this->completeAnswers())
            ->assertOk()
            ->assertSee('Thank you', false);

        $submission = FormSubmission::query()->sole();

        $this->assertSame((int) $customer->getKey(), (int) $submission->customer_id);
        $this->assertSame((int) $customer->enrollments()->sole()->getKey(), (int) $submission->enrollment_id);
        $this->assertSame((int) $this->version()->getKey(), (int) $submission->form_version_id);
        $this->assertSame('submitted', (string) $submission->status);
        $this->assertNotNull($submission->submitted_at);

        // The answers are the participant's own, not staff's.
        $this->assertSame(ActorSource::ExternalGrant, $submission->source);
        $this->assertNull($submission->created_by);

        $this->assertSame(
            'Meera Shah',
            Answer::query()
                ->where('form_submission_id', $submission->getKey())
                ->where('question_id', $this->question('full_name')->getKey())
                ->value('value_text'),
        );

        $this->assertSame(
            ['3_7'],
            $this->selectedValues($submission, 'years_in_business'),
        );

        $this->assertSame(
            ['leads', 'systems'],
            $this->selectedValues($submission, 'current_challenges'),
        );
    }

    #[Test]
    public function a_required_answer_that_is_missing_refuses_the_submission(): void
    {
        $this->seedQuestionnaire();

        $token = $this->tokenFrom($this->enterCustomer('C-PARTIAL')['url']);
        $answers = $this->completeAnswers();

        unset($answers['answers'][$this->question('business_location')->getKey()]);

        $this->post(route('external.forms.store', ['token' => $token]), $answers)
            ->assertOk()
            ->assertSee('Business Location');

        $this->assertSame('draft', (string) FormSubmission::query()->sole()->status);
    }

    #[Test]
    public function the_other_platform_answer_is_kept_when_other_is_chosen_and_dropped_when_it_is_not(): void
    {
        $this->seedQuestionnaire();

        $token = $this->tokenFrom($this->enterCustomer('C-OTHER')['url']);
        $platform = $this->question('best_platform');
        $other = $this->question('best_platform_other');

        $saving = $this->completeAnswers('save');
        $saving['selections'][$platform->getKey()] = [$platform->options()->where('value', 'other')->sole()->getKey()];
        $saving['answers'][$other->getKey()] = 'Pinterest';

        $this->post(route('external.forms.store', ['token' => $token]), $saving)->assertRedirect();

        $submission = FormSubmission::query()->sole();

        $this->assertSame('Pinterest', $this->answerText($submission, 'best_platform_other'));

        // The participant changes their mind: the platform is no longer Other,
        // so the text they typed is not an answer any more.
        $switching = $this->completeAnswers('save');
        $switching['selections'][$platform->getKey()] = [$platform->options()->where('value', 'google')->sole()->getKey()];
        $switching['answers'][$other->getKey()] = 'Pinterest';

        $this->post(route('external.forms.store', ['token' => $token]), $switching)->assertRedirect();

        $this->assertNull($this->answerText($submission, 'best_platform_other'));
    }

    /*
    |--------------------------------------------------------------------------
    | E. One business's link reaches one business's form
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function one_businesss_link_cannot_reach_another_businesss_questionnaire(): void
    {
        $this->seedQuestionnaire();

        $alphaToken = $this->tokenFrom($this->enterCustomer('C-ALPHA')['url']);
        $betaToken = $this->tokenFrom($this->enterCustomer('C-BETA')['url']);

        $alpha = Customer::query()->where('code', 'C-ALPHA')->sole();
        $beta = Customer::query()->where('code', 'C-BETA')->sole();

        $this->get(route('external.forms.show', ['token' => $alphaToken]))
            ->assertOk()
            ->assertSee('Alpha');

        $this->get(route('external.forms.show', ['token' => $betaToken]))
            ->assertOk()
            ->assertSee('Beta')
            ->assertDontSee('Alpha');

        // Each link opened a draft of its OWN business. There is no id in the
        // request for either of them to substitute - the grant resolves
        // customer, enrolment, version and submission.
        $this->assertSame(
            [(int) $alpha->getKey()],
            FormSubmission::query()->where('customer_id', $alpha->getKey())->pluck('customer_id')->map(fn ($id): int => (int) $id)->unique()->values()->all(),
        );

        $this->post(route('external.forms.store', ['token' => $betaToken]), $this->completeAnswers())->assertOk();

        $this->assertSame(
            (int) $beta->getKey(),
            (int) FormSubmission::query()->where('status', 'submitted')->sole()->customer_id,
        );
        $this->assertSame(
            'draft',
            (string) FormSubmission::query()->where('customer_id', $alpha->getKey())->sole()->status,
            "Beta's submission must not touch Alpha's.",
        );
    }

    #[Test]
    public function a_spent_link_no_longer_opens_the_questionnaire(): void
    {
        $this->seedQuestionnaire();

        $token = $this->tokenFrom($this->enterCustomer('C-SPENT')['url']);

        $this->post(route('external.forms.store', ['token' => $token]), $this->completeAnswers())->assertOk();

        $this->get(route('external.forms.show', ['token' => $token]))->assertNotFound();
        $this->post(route('external.forms.store', ['token' => $token]), $this->completeAnswers())->assertNotFound();
    }

    #[Test]
    public function an_unknown_token_is_refused_without_saying_why(): void
    {
        $this->seedQuestionnaire();

        $this->get(route('external.forms.show', ['token' => str_repeat('a', 64)]))
            ->assertNotFound()
            ->assertSee('This link is not valid.');
    }

    /*
    |--------------------------------------------------------------------------
    | F. The admin sees it
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_questionnaire_appears_in_the_admin_forms_section(): void
    {
        $this->seedQuestionnaire();

        Livewire::actingAs($this->admin())
            ->test(FormTemplates::class)
            ->assertOk()
            ->assertSee('Participant Business Analysis Questionnaire', false);
    }

    #[Test]
    public function the_admin_sees_the_submission_and_can_open_the_answers(): void
    {
        $this->seedQuestionnaire();

        $token = $this->tokenFrom($this->enterCustomer('C-REVIEW')['url']);
        $customer = Customer::query()->where('code', 'C-REVIEW')->sole();

        $this->post(route('external.forms.store', ['token' => $token]), $this->completeAnswers())->assertOk();

        $submission = FormSubmission::query()->sole();

        Livewire::actingAs($this->admin())
            ->test(FormsScreen::class, ['customer' => $customer])
            ->assertOk()
            ->assertSee('Participant Business Analysis Questionnaire', false)
            ->assertSee('Submitted');

        // The renderer binds answers to component state rather than writing
        // them into value attributes, so that is where the answer is asserted.
        Livewire::actingAs($this->admin())
            ->test(FormRenderer::class, ['submission' => $submission])
            ->assertOk()
            ->assertSee('Full Name')
            ->assertSee('Business Readiness Score')
            ->assertSet('answers.'.$this->question('full_name')->getKey(), 'Meera Shah');
    }

    #[Test]
    public function an_admin_can_issue_a_fresh_link_to_copy(): void
    {
        $this->seedQuestionnaire();

        $customer = $this->enteredCustomer('C-COPY');
        $enrollment = $customer->enrollments()->sole();
        $template = app(ParticipantQuestionnaire::class)->template();

        $screen = Livewire::actingAs($this->admin())
            ->test(FormsScreen::class, ['customer' => $customer])
            ->call('copyFormLink', $enrollment->getKey(), $template->getKey())
            ->assertHasNoErrors()
            ->assertSee('Copy link')
            // No transport here, so the screen says so rather than claiming a
            // message went out.
            ->assertSee('No mail transport is configured', false);

        // The URL is on the screen the operator is looking at - which is the
        // whole point of the action, since the token cannot be recovered later.
        preg_match('#/external/forms/([A-Za-z0-9_-]{20,})#', $screen->html(), $matches);

        $this->assertNotEmpty($matches, 'The operator must be shown a link they can copy.');

        Mail::assertNothingSent();

        // The previous link is superseded, so a business never holds two.
        $this->assertSame(
            1,
            AccessGrant::query()
                ->where('customer_id', $customer->getKey())
                ->whereNull('revoked_at')
                ->count(),
        );

        $this->get(route('external.forms.show', ['token' => $matches[1]]))->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function seedQuestionnaire(): void
    {
        // The admin exists first: publishing is audited, and the seeder
        // attributes it to a Super Admin when there is one.
        $this->superAdmin();

        $this->seed(ParticipantQuestionnaireSeeder::class);
    }

    /**
     * Enter a confirmed customer through the real screen, and return what the
     * operator was shown.
     *
     * @return array{customer: Customer, url: string|null}
     */
    private function enterCustomer(string $code): array
    {
        $name = ucfirst(strtolower(str_replace('C-', '', $code))).' Industries';

        Livewire::actingAs($this->admin())
            ->test(ManageCustomer::class)
            ->set('name', $name)
            ->set('code', $code)
            ->set('contactName', 'Programme Contact')
            ->set('contactEmail', strtolower(str_replace('-', '', $code)).'@example.test')
            ->set('batchId', $this->batch()->getKey())
            ->call('save')
            ->assertHasNoErrors();

        return [
            'customer' => Customer::query()->where('code', $code)->sole(),
            'url' => session('issued_link')['url'] ?? null,
        ];
    }

    private function enteredCustomer(string $code): Customer
    {
        return $this->enterCustomer($code)['customer'];
    }

    private function openedSubmissionFor(Customer $customer): FormSubmission
    {
        $grant = AccessGrant::query()->where('customer_id', $customer->getKey())->sole();

        return app(SubmissionService::class)->startDraft(
            Enrollment::query()->findOrFail($grant->enrollment_id),
            $this->version(),
            grant: $grant,
        );
    }

    /**
     * Every required answer, plus a representative spread of the rest.
     *
     * @return array<string, mixed>
     */
    private function completeAnswers(string $action = 'submit'): array
    {
        $years = $this->question('years_in_business');
        $teamSize = $this->question('team_size');
        $turnover = $this->question('annual_turnover');
        $challenges = $this->question('current_challenges');

        return [
            'action' => $action,
            'answers' => [
                $this->question('full_name')->getKey() => 'Meera Shah',
                $this->question('business_name')->getKey() => 'Shah Precision Components',
                $this->question('business_category')->getKey() => 'Precision machining',
                $this->question('business_location')->getKey() => 'Rajkot, Gujarat',
                $this->question('main_products_services')->getKey() => 'Machined housings and sub-assemblies',
                $this->question('business_vision')->getKey() => 'Twice the capacity, half the firefighting.',
                $this->question('goal_1')->getKey() => 'Second shift running without me',
                $this->question('orders_per_month')->getKey() => '140',
                $this->question('dedicated_sales_team')->getKey() => '1',
            ],
            'selections' => [
                $years->getKey() => [$years->options()->where('value', '3_7')->sole()->getKey()],
                $teamSize->getKey() => [$teamSize->options()->where('value', '6_15')->sole()->getKey()],
                $turnover->getKey() => [$turnover->options()->where('value', '3_5_cr')->sole()->getKey()],
                $challenges->getKey() => [
                    $challenges->options()->where('value', 'leads')->sole()->getKey(),
                    $challenges->options()->where('value', 'systems')->sole()->getKey(),
                ],
            ],
        ];
    }

    private function batch(): Batch
    {
        return Batch::query()->firstOr(function (): Batch {
            $program = Program::query()->create([
                'code' => 'BMP',
                'name' => 'Business Mastery Programme',
                'session_count' => 6,
                'status' => 'active',
            ]);

            return Batch::query()->create([
                'program_id' => $program->getKey(),
                'code' => 'B-2026-01',
                'name' => 'Batch 1',
                'starts_on' => now()->subWeek()->toDateString(),
                'ends_on' => now()->addWeeks(9)->toDateString(),
                'capacity' => 20,
                'status' => 'running',
            ]);
        });
    }

    private function version(): FormVersion
    {
        return app(ParticipantQuestionnaire::class)->publishedVersion();
    }

    private function question(string $bankKey): Question
    {
        return $this->version()->questions()->where('bank_key', $bankKey)->sole();
    }

    /**
     * @return array<int, string>
     */
    private function optionLabels(string $bankKey): array
    {
        return $this->question($bankKey)->options()->orderBy('position')->pluck('label')->all();
    }

    /**
     * @return array<int, string>
     */
    private function selectedValues(FormSubmission $submission, string $bankKey): array
    {
        $answer = Answer::query()
            ->where('form_submission_id', $submission->getKey())
            ->where('question_id', $this->question($bankKey)->getKey())
            ->sole();

        return QuestionOption::query()
            ->whereIn('id', $answer->answerOptions()->pluck('question_option_id'))
            ->orderBy('position')
            ->pluck('value')
            ->all();
    }

    private function answerText(FormSubmission $submission, string $bankKey): ?string
    {
        return Answer::query()
            ->where('form_submission_id', $submission->getKey())
            ->where('question_id', $this->question($bankKey)->getKey())
            ->value('value_text');
    }

    private function tokenFrom(?string $url): string
    {
        $this->assertNotNull($url, 'The operator must be given a link to copy.');

        return basename((string) parse_url($url, PHP_URL_PATH));
    }
}
