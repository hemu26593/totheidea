<?php

declare(strict_types=1);

namespace Tests\Feature\Uat;

use App\Domain\Forms\FormBuilderService;
use App\Domain\Forms\FormPublishingService;
use App\Domain\Forms\SubmissionService;
use App\Domain\Scoring\ScoringService;
use App\Enums\QuestionType;
use App\Livewire\Customers\Enrollments;
use App\Livewire\Customers\Forms as CustomerForms;
use App\Livewire\Customers\ManageCustomer;
use App\Livewire\Customers\Show;
use App\Livewire\Forms\FormRenderer;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\Enrollment;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\FormVersion;
use App\Models\Program;
use App\Models\SubmissionScore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * UAT: onboarding a business, and taking it through intake.
 *
 * Driven through the SCREENS a staff member actually uses, not through the
 * services behind them - the question this phase answers is whether the
 * workflow holds together, not whether each service works in isolation.
 */
class OnboardingAndIntakeUatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
    }

    #[Test]
    public function staff_can_edit_a_business_but_bringing_a_new_one_in_is_an_admin_act(): void
    {
        // UAT observation, not a defect: the permission matrix gives Staff
        // customers.view and customers.edit but NOT customers.create. A
        // consultant maintains the businesses they run; adding one to the
        // programme is an administrative decision. The screen refuses cleanly
        // rather than half-working.
        $alpha = Customer::factory()->create(['name' => 'Alpha Metalworks', 'code' => 'C-ALPHA']);
        $staff = $this->staff();

        $this->assertTrue($staff->can('update', $alpha));
        $this->assertFalse($staff->can('create', Customer::class));

        Livewire::actingAs($staff)
            ->test(ManageCustomer::class, ['customer' => $alpha])
            ->set('name', 'Alpha Metalworks Pvt Ltd')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Alpha Metalworks Pvt Ltd', $alpha->fresh()->name);
    }

    #[Test]
    public function staff_cannot_create_a_customer_but_admin_can(): void
    {
        // Staff hold customers.view and customers.edit - not customers.create.
        // A staff member joining a new business to the programme is therefore
        // an Admin act, and the screen refuses rather than half-working.
        $this->actingAs($this->staff())->get(route('customers.create'))->assertForbidden();
        $this->actingAs($this->admin())->get(route('customers.create'))->assertOk();
    }

    #[Test]
    public function the_full_onboarding_path_produces_a_coherent_workspace(): void
    {
        $admin = $this->admin();

        // 1. Create.
        Livewire::actingAs($admin)
            ->test(ManageCustomer::class)
            ->set('name', 'Alpha Metalworks')
            ->set('code', 'C-ALPHA')
            ->call('save');

        $alpha = Customer::query()->firstWhere('code', 'C-ALPHA');
        $this->assertSame('prospect', $alpha->status, 'A new customer starts as a prospect.');

        // 2. Add the contact reminders will go to.
        Livewire::actingAs($admin)
            ->test(Show::class, ['customer' => $alpha])
            ->call('startContact')
            ->set('contactName', 'Rakesh Patel')
            ->set('contactRole', 'Managing Director')
            ->set('contactEmail', 'rakesh@alpha.test')
            ->set('contactPrimary', true)
            ->call('saveContact')
            ->assertHasNoErrors();

        $this->assertSame('Rakesh Patel', $alpha->fresh()->primaryContact()?->name);

        // 3. Enrol into a batch.
        $batch = $this->batch();

        Livewire::actingAs($admin)
            ->test(Enrollments::class, ['customer' => $alpha])
            ->call('startEnrolment')
            ->set('batchId', $batch->getKey())
            ->call('enrol')
            ->assertHasNoErrors();

        $enrollment = Enrollment::query()->firstWhere('customer_id', $alpha->getKey());
        $this->assertNotNull($enrollment);
        $this->assertSame('enrolled', $enrollment->status);

        // 4. Every workspace tab now opens for this business.
        foreach ([
            'customers.show', 'customers.enrollments', 'customers.forms', 'customers.sessions',
            'customers.assignments', 'customers.attendance', 'customers.day-plan',
            'customers.time-grid', 'customers.mmd', 'customers.fund-plan', 'customers.action-plan',
            'customers.business', 'customers.documents', 'customers.notes', 'customers.reports',
            'customers.ai',
        ] as $tab) {
            $this->actingAs($admin)
                ->get(route($tab, $alpha))
                ->assertOk()
                // The customer's identity is on every screen in the workspace,
                // so a staff member always knows whose data they are editing.
                ->assertSee('Alpha Metalworks')
                ->assertSee('C-ALPHA');
        }
    }

    #[Test]
    public function a_duplicate_customer_code_is_refused_with_a_readable_message(): void
    {
        $admin = $this->admin();
        Customer::factory()->create(['code' => 'C-ALPHA']);

        Livewire::actingAs($admin)
            ->test(ManageCustomer::class)
            ->set('name', 'Another business')
            ->set('code', 'C-ALPHA')
            ->call('save')
            ->assertHasErrors(['code' => 'unique']);

        $this->assertSame(1, Customer::query()->where('code', 'C-ALPHA')->count());
    }

    #[Test]
    public function a_business_cannot_be_enrolled_into_the_same_batch_twice(): void
    {
        $admin = $this->admin();
        $alpha = Customer::factory()->create();
        $batch = $this->batch();

        $screen = Livewire::actingAs($admin)->test(Enrollments::class, ['customer' => $alpha]);
        $screen->call('startEnrolment')->set('batchId', $batch->getKey())->call('enrol');

        // The picker no longer offers it, which is the honest UI answer to a
        // database constraint that would otherwise produce an error.
        $screen->call('startEnrolment')
            ->assertViewHas('availableBatches', fn ($batches) => $batches->isEmpty());

        $this->assertSame(1, Enrollment::query()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Intake
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_intake_workflow_runs_from_a_published_version_to_a_score(): void
    {
        $admin = $this->admin();
        [$alpha, $enrollment] = $this->enrolledCustomer('Alpha Metalworks', 'C-ALPHA');
        $version = $this->publishedIntake($admin);

        // Start the form from the customer's Forms tab.
        Livewire::actingAs($admin)
            ->test(CustomerForms::class, ['customer' => $alpha])
            ->call('startSubmission')
            ->set('enrollmentId', $enrollment->getKey())
            ->set('templateId', $version->form_template_id)
            ->call('start')
            ->assertHasNoErrors();

        $submission = FormSubmission::query()->firstWhere('customer_id', $alpha->getKey());
        $this->assertNotNull($submission);
        $this->assertSame((int) $version->getKey(), (int) $submission->form_version_id,
            'A new submission binds to the PUBLISHED version, never to the template.');

        $questions = $version->questions()->get()->keyBy('label');
        $screen = Livewire::actingAs($admin)->test(FormRenderer::class, ['submission' => $submission]);

        // The renderer shows what this version actually contains.
        $screen->assertSee('Business intake')
            ->assertSee('How many people work in the business?')
            ->assertSee('Do you hold a documented daily routine?')
            // Categorical options, with no numeric mapping shown anywhere.
            ->assertSee('Average')->assertSee('Good')->assertSee('Better')->assertSee('Best');

        // Required fields are refused while blank.
        $screen->call('submit')->assertHasErrors('domain');
        $this->assertTrue($submission->fresh()->isDraft(), 'An incomplete intake is not submitted.');

        // Answer each type.
        $screen->set('answers.'.$questions['How many people work in the business?']->getKey(), 18)
            ->call('saveAnswer', $questions['How many people work in the business?']->getKey());

        $screen->set('answers.'.$questions['Describe your busiest day.']->getKey(), 'Tuesdays. Dispatch and accounts collide.')
            ->call('saveAnswer', $questions['Describe your busiest day.']->getKey());

        $rating = $questions['How would you rate your marketing system?'];
        $good = $rating->options()->where('label', 'Good')->firstOrFail();
        $screen->set('selections.'.$rating->getKey(), [$good->getKey()])
            ->call('saveAnswer', $rating->getKey());

        $screen->set('answers.'.$questions['Do you hold a documented daily routine?']->getKey(), '1')
            ->call('saveAnswer', $questions['Do you hold a documented daily routine?']->getKey());

        $this->assertSame(4, $submission->fresh()->answers()->count());

        // Submit.
        $screen->call('submit')->assertHasNoErrors();
        $submission->refresh();
        $this->assertSame('submitted', $submission->status);
        $this->assertNotNull($submission->submitted_at);

        // Reopening shows the recorded answers, including the boolean.
        Livewire::actingAs($admin)
            ->test(FormRenderer::class, ['submission' => $submission])
            ->assertSet('answers.'.$questions['Do you hold a documented daily routine?']->getKey(), '1')
            ->assertSet('answers.'.$questions['How many people work in the business?']->getKey(), 18);

        // Score it - an explicit act, not a side effect of submitting.
        $this->assertSame(0, $submission->scores()->count(), 'Submitting does not score.');

        Livewire::actingAs($admin)
            ->test(FormRenderer::class, ['submission' => $submission])
            ->call('score')
            ->assertHasNoErrors();

        $this->assertGreaterThan(0, $submission->fresh()->scores()->count());
    }

    #[Test]
    public function scores_are_the_scoring_services_and_carry_no_band_or_verdict(): void
    {
        $admin = $this->admin();
        [$alpha, $enrollment] = $this->enrolledCustomer('Alpha Metalworks', 'C-ALPHA');
        $submission = $this->submittedIntake($admin, $enrollment);

        Livewire::actingAs($admin)->test(FormRenderer::class, ['submission' => $submission])->call('score');

        $scores = $submission->fresh()->scores()->get();
        $this->assertNotEmpty($scores);

        foreach ($scores as $score) {
            // Every stored score names the scheme that produced it.
            $this->assertSame('v1', $score->scheme_version);
        }

        // The screen shows figures and no interpretation of them.
        $rendered = Livewire::actingAs($admin)
            ->test(FormRenderer::class, ['submission' => $submission->fresh()])
            ->assertSee('No band or verdict is shown');

        foreach (['Weak', 'Strong', 'Red zone', 'Amber', 'Green zone', 'Grade'] as $invented) {
            $rendered->assertDontSee($invented);
        }
    }

    #[Test]
    public function the_yes_count_rule_counts_yes_answers_and_derives_the_threshold(): void
    {
        // Recorded as the domain actually behaves, not as UAT assumed.
        //
        //   raw_score  = how many booleans were answered Yes
        //   max_score  = how many boolean questions the version asks
        //   threshold  = a CONSTANT, applied on read and never stored
        //
        // Nothing persists a pass or a fail, because the SOW states the
        // threshold but not its consequence.
        $admin = $this->admin();
        [$alpha, $enrollment] = $this->enrolledCustomer('Alpha Metalworks', 'C-ALPHA');

        $scoring = app(ScoringService::class);
        $this->assertSame(10, $scoring::YES_COUNT_THRESHOLD);

        // An eighteen-question yes/no instrument, twelve answered Yes.
        $submission = $this->answeredYesNoInstrument($admin, $enrollment, yes: 12, total: 18);
        $scoring->score($submission);

        $score = SubmissionScore::query()
            ->where('form_submission_id', $submission->getKey())
            ->where('score_type', 'yes_count')
            ->firstOrFail();

        $this->assertSame(12.0, (float) $score->raw_score);
        $this->assertSame(18.0, (float) $score->max_score, 'The maximum is the question count.');
        $this->assertTrue($scoring->meetsYesCountThreshold($submission->fresh()));

        // Nine Yes answers fall below the threshold - and still store no verdict.
        [$beta, $betaEnrollment] = $this->enrolledCustomer('Beta Textiles', 'C-BETA');
        $below = $this->answeredYesNoInstrument($admin, $betaEnrollment, yes: 9, total: 18);
        $scoring->score($below);

        $this->assertFalse($scoring->meetsYesCountThreshold($below->fresh()));

        foreach (SubmissionScore::query()->get() as $stored) {
            $this->assertNotContains($stored->score_type, ['band', 'verdict', 'grade', 'outcome']);
        }
    }

    #[Test]
    public function a_heat_map_band_is_refused_rather_than_invented(): void
    {
        $admin = $this->admin();
        [$alpha, $enrollment] = $this->enrolledCustomer('Alpha Metalworks', 'C-ALPHA');
        $submission = $this->submittedIntake($admin, $enrollment);

        app(ScoringService::class)->score($submission);
        $score = $submission->fresh()->scores()->firstOrFail();

        $this->expectException(\RuntimeException::class);
        app(ScoringService::class)->bandFor($score);
    }

    #[Test]
    public function the_four_categorical_values_carry_no_numeric_mapping(): void
    {
        $admin = $this->admin();
        $version = $this->publishedIntake($admin);

        $rating = $version->questions()->where('type', QuestionType::SelectOne->value)->firstOrFail();

        foreach ($rating->options as $option) {
            $this->assertNull(
                $option->score_value,
                "[{$option->label}] must stay categorical - no numeric mapping has been agreed.",
            );
        }
    }

    #[Test]
    public function a_submitted_intake_is_read_only_on_screen(): void
    {
        $admin = $this->admin();
        [$alpha, $enrollment] = $this->enrolledCustomer('Alpha Metalworks', 'C-ALPHA');
        $submission = $this->submittedIntake($admin, $enrollment);

        $question = $submission->formVersion->questions()->first();

        Livewire::actingAs($admin)
            ->test(FormRenderer::class, ['submission' => $submission])
            ->set('answers.'.$question->getKey(), 999)
            ->call('saveAnswer', $question->getKey())
            ->assertHasErrors('domain');
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers - the UAT fixture, built the way staff would build it
    |--------------------------------------------------------------------------
    */

    private function batch(): Batch
    {
        $program = Program::first()
            ?? Program::factory()->create(['name' => 'Business Mastery Programme', 'session_count' => 6]);

        return Batch::factory()->create([
            'program_id' => $program->getKey(),
            'name' => 'Vadodara Batch '.chr(65 + Batch::count()),
        ]);
    }

    /**
     * @return array{0: Customer, 1: Enrollment}
     */
    private function enrolledCustomer(string $name, string $code): array
    {
        $customer = Customer::factory()->create(['name' => $name, 'code' => $code]);

        $enrollment = Enrollment::factory()->create([
            'customer_id' => $customer->getKey(),
            'batch_id' => $this->batch()->getKey(),
        ]);

        return [$customer, $enrollment];
    }

    private function publishedIntake(User $actor): FormVersion
    {
        $builder = app(FormBuilderService::class);

        $template = FormTemplate::factory()->scored()->create(['name' => 'Business intake']);
        $version = $builder->createDraftVersion($template);

        $about = $builder->addSection($version, 'About the business', 0);
        $systems = $builder->addSection($version, 'Systems', 1);

        $builder->addQuestion($version, $about, QuestionType::Number,
            'How many people work in the business?', 0, ['is_required' => true, 'max_score' => 5]);
        $builder->addQuestion($version, $about, QuestionType::Textarea,
            'Describe your busiest day.', 1, ['is_required' => true]);

        $rating = $builder->addQuestion($version, $systems, QuestionType::SelectOne,
            'How would you rate your marketing system?', 2, ['is_required' => true]);
        $builder->addScoringCategoryOptions($rating);

        $builder->addQuestion($version, $systems, QuestionType::Boolean,
            'Do you hold a documented daily routine?', 3, ['is_required' => true]);

        return app(FormPublishingService::class)->publish($version, $actor, 'v1');
    }

    /**
     * A yes/no instrument of $total questions with $yes answered Yes.
     */
    private function answeredYesNoInstrument(User $actor, Enrollment $enrollment, int $yes, int $total): FormSubmission
    {
        $builder = app(FormBuilderService::class);

        $template = FormTemplate::factory()->scored()->create(['name' => 'Yes/no check']);
        $version = $builder->createDraftVersion($template);
        $section = $builder->addSection($version, 'Checks', 0);

        for ($i = 0; $i < $total; $i++) {
            $builder->addQuestion($version, $section, QuestionType::Boolean, 'Check '.($i + 1), $i);
        }

        $version = app(FormPublishingService::class)->publish($version, $actor, 'v1');

        $submissions = app(SubmissionService::class);
        $submission = $submissions->startDraft($enrollment, $version, $actor);

        foreach ($version->questions()->get() as $index => $question) {
            $submissions->answer($submission, $question, ['value_boolean' => $index < $yes]);
        }

        return $submissions->submit($submission, $actor)->fresh();
    }

    private function submittedIntake(User $actor, Enrollment $enrollment): FormSubmission
    {
        $version = $this->publishedIntake($actor);
        $submissions = app(SubmissionService::class);

        $submission = $submissions->startDraft($enrollment, $version, $actor);
        $questions = $version->questions()->get();

        $submissions->answer($submission, $questions[0], ['value_number' => 18]);
        $submissions->answer($submission, $questions[1], ['value_text' => 'Tuesdays.']);
        $submissions->answer($submission, $questions[2], [], [$questions[2]->options()->firstOrFail()]);
        $submissions->answer($submission, $questions[3], ['value_boolean' => true]);

        return $submissions->submit($submission, $actor)->fresh();
    }
}
