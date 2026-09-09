<?php

declare(strict_types=1);

namespace Tests\Feature\External;

use App\Domain\Access\AccessGrantService;
use App\Domain\Forms\FormBuilderService;
use App\Domain\Forms\FormPublishingService;
use App\Domain\Scoring\ScoringService;
use App\Enums\ActorSource;
use App\Enums\GrantAbility;
use App\Enums\QuestionType;
use App\Models\AccessGrant;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\Enrollment;
use App\Models\FormSubmission;
use App\Models\Program;
use App\Models\Question;
use App\Models\SkillArea;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The external participant surface, driven over HTTP.
 *
 * Every test here goes through the real routes with NO authenticated session.
 * The point is not that the domain refuses - Phase 5 proved that - but that the
 * two new routes cannot be talked into anything the domain would not allow, and
 * that a participant never becomes an internal actor by using them.
 */
class ExternalFormAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
        $this->actor = $this->admin();
    }

    /*
    |--------------------------------------------------------------------------
    | Opening the link
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_valid_link_opens_the_form_the_grant_names(): void
    {
        $alpha = $this->fixture();
        $token = $this->grantFor($alpha)['token'];

        $this->get(route('external.forms.show', ['token' => $token]))
            ->assertOk()
            ->assertSee('Business Intake')
            ->assertSee('Alpha Metalworks')
            ->assertSee('What does the business make?')
            ->assertSee('How many people work here?')
            ->assertSee('Do you hold a weekly review?');
    }

    #[Test]
    public function opening_the_link_does_not_consume_the_grant(): void
    {
        $alpha = $this->fixture();
        ['token' => $token, 'grant' => $grant] = $this->grantFor($alpha);

        $this->get(route('external.forms.show', ['token' => $token]))->assertOk();
        $this->get(route('external.forms.show', ['token' => $token]))->assertOk();
        $this->get(route('external.forms.show', ['token' => $token]))->assertOk();

        $this->assertSame(0, (int) $grant->fresh()->use_count, 'authorize() must consume nothing.');
    }

    #[Test]
    public function the_page_carries_nothing_from_the_internal_application(): void
    {
        $alpha = $this->fixture();
        $token = $this->grantFor($alpha)['token'];

        $response = $this->get(route('external.forms.show', ['token' => $token]));

        $html = $response->getContent();

        foreach ([route('dashboard'), route('customers.index'), route('login'), route('logout')] as $internal) {
            $this->assertStringNotContainsString($internal, $html, 'The external page must offer no doorway inwards.');
        }

        foreach (['Sign out', 'Dashboard', 'Notes', 'Documents', 'Reports', 'Audit'] as $internal) {
            $response->assertDontSee($internal);
        }

        // The internal staff who issued the link are not named to the participant.
        $response->assertDontSee($this->actor->name);
        $response->assertDontSee($this->actor->email);
    }

    /*
    |--------------------------------------------------------------------------
    | Refusals - all indistinguishable
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_unknown_link_is_refused(): void
    {
        $this->get(route('external.forms.show', ['token' => 'not-a-real-token']))
            ->assertNotFound()
            ->assertSee('This link is not valid.');
    }

    #[Test]
    public function an_expired_link_is_refused(): void
    {
        $alpha = $this->fixture();
        ['token' => $token, 'grant' => $grant] = $this->grantFor($alpha);

        $grant->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->get(route('external.forms.show', ['token' => $token]))
            ->assertNotFound()
            ->assertSee('This link is not valid.');
    }

    #[Test]
    public function a_revoked_link_is_refused(): void
    {
        $alpha = $this->fixture();
        ['token' => $token, 'grant' => $grant] = $this->grantFor($alpha);

        app(AccessGrantService::class)->revoke($grant, $this->actor, 'Sent to the wrong address.');

        $this->get(route('external.forms.show', ['token' => $token]))
            ->assertNotFound()
            ->assertSee('This link is not valid.');
    }

    #[Test]
    public function an_exhausted_link_is_refused(): void
    {
        $alpha = $this->fixture();
        ['token' => $token, 'grant' => $grant] = $this->grantFor($alpha);

        $grant->forceFill(['use_count' => $grant->max_uses])->save();

        $this->get(route('external.forms.show', ['token' => $token]))
            ->assertNotFound()
            ->assertSee('This link is not valid.');
    }

    #[Test]
    public function a_link_issued_for_another_action_cannot_open_a_form(): void
    {
        $alpha = $this->fixture();

        // accept_terms, not complete_form.
        $issued = app(AccessGrantService::class)->issue(
            customer: $alpha['customer'],
            enrollment: $alpha['enrollment'],
            subject: $alpha['enrollment'],
            ability: GrantAbility::AcceptTerms,
            expiresAt: now()->addDays(7),
            actor: $this->actor,
        );

        $this->get(route('external.forms.show', ['token' => $issued->plaintextToken]))
            ->assertNotFound()
            ->assertSee('This link is not valid.');
    }

    #[Test]
    public function every_refusal_is_word_for_word_identical(): void
    {
        $alpha = $this->fixture();

        $expired = $this->grantFor($alpha);
        $expired['grant']->forceFill(['expires_at' => now()->subMinute()])->save();

        $revoked = $this->grantFor($alpha);
        app(AccessGrantService::class)->revoke($revoked['grant'], $this->actor);

        $spent = $this->grantFor($alpha);
        $spent['grant']->forceFill(['use_count' => $spent['grant']->max_uses])->save();

        $bodies = [];

        foreach (['completely-unknown', $expired['token'], $revoked['token'], $spent['token']] as $token) {
            $response = $this->get(route('external.forms.show', ['token' => $token]));
            $response->assertNotFound();
            $bodies[] = $response->getContent();
        }

        $this->assertCount(1, array_unique($bodies), 'A refusal must not say which kind of refusal it is.');
    }

    /*
    |--------------------------------------------------------------------------
    | Submitting
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_participant_can_complete_and_submit_the_form(): void
    {
        $alpha = $this->fixture();
        ['token' => $token, 'grant' => $grant] = $this->grantFor($alpha);

        $this->get(route('external.forms.show', ['token' => $token]))->assertOk();

        $this->post(route('external.forms.store', ['token' => $token]), $this->completeAnswersFor($alpha))
            ->assertOk()
            ->assertSee('your form has been submitted')
            ->assertSee('Alpha Metalworks');

        $submission = FormSubmission::query()->sole();

        $this->assertSame('submitted', $submission->status);
        $this->assertNotNull($submission->submitted_at);

        // The answers landed, by type.
        $answers = $submission->answers()->with('question')->get()->keyBy('question_id');

        $this->assertSame('Sheet-metal enclosures', $answers[$alpha['text']->getKey()]->value_text);
        $this->assertSame(18.0, (float) $answers[$alpha['number']->getKey()]->value_number);
        $this->assertTrue((bool) $answers[$alpha['boolean']->getKey()]->value_boolean);
        $this->assertSame(
            [$alpha['optionB']->getKey()],
            $answers[$alpha['choice']->getKey()]->answerOptions->pluck('question_option_id')->map(fn ($id): int => (int) $id)->all(),
        );

        $this->assertSame(1, (int) $grant->fresh()->use_count, 'Submitting is what spends the use.');
    }

    #[Test]
    public function the_submission_belongs_to_the_grants_customer_and_enrolment(): void
    {
        $alpha = $this->fixture();
        $token = $this->grantFor($alpha)['token'];

        $this->post(route('external.forms.store', ['token' => $token]), $this->completeAnswersFor($alpha))->assertOk();

        $submission = FormSubmission::query()->sole();

        $this->assertSame((int) $alpha['customer']->getKey(), (int) $submission->customer_id);
        $this->assertSame((int) $alpha['enrollment']->getKey(), (int) $submission->enrollment_id);
        $this->assertSame((int) $alpha['version']->getKey(), (int) $submission->form_version_id);
    }

    #[Test]
    public function the_submission_records_a_capability_rather_than_an_actor(): void
    {
        $alpha = $this->fixture();
        ['token' => $token, 'grant' => $grant] = $this->grantFor($alpha);

        $this->post(route('external.forms.store', ['token' => $token]), $this->completeAnswersFor($alpha))->assertOk();

        $submission = FormSubmission::query()->sole();

        $this->assertSame(ActorSource::ExternalGrant->value, $submission->source->value ?? $submission->source);
        $this->assertNull($submission->created_by, 'An external submission has no internal author.');
        $this->assertSame((int) $grant->getKey(), (int) $submission->access_grant_id);
    }

    #[Test]
    public function progress_can_be_saved_without_submitting_or_spending_the_link(): void
    {
        $alpha = $this->fixture();
        ['token' => $token, 'grant' => $grant] = $this->grantFor($alpha);

        $this->post(route('external.forms.store', ['token' => $token]), [
            'action' => 'save',
            'answers' => [$alpha['text']->getKey() => 'Half an answer'],
        ])->assertRedirect(route('external.forms.show', ['token' => $token]));

        $submission = FormSubmission::query()->sole();
        $this->assertSame('draft', $submission->status);
        $this->assertSame(0, (int) $grant->fresh()->use_count);

        // Coming back to the same link shows the saved answer.
        $this->get(route('external.forms.show', ['token' => $token]))
            ->assertOk()
            ->assertSee('Half an answer');
    }

    #[Test]
    public function an_incomplete_form_is_refused_at_submit_and_nothing_is_spent(): void
    {
        $alpha = $this->fixture();
        ['token' => $token, 'grant' => $grant] = $this->grantFor($alpha);

        $this->post(route('external.forms.store', ['token' => $token]), [
            'action' => 'submit',
            'answers' => [$alpha['text']->getKey() => 'Only the first one'],
        ])
            ->assertOk()
            ->assertSee('Please answer these before submitting')
            ->assertSee('How many people work here?');

        $this->assertSame('draft', FormSubmission::query()->sole()->status);
        $this->assertSame(0, (int) $grant->fresh()->use_count);
    }

    #[Test]
    public function a_used_link_cannot_be_replayed(): void
    {
        $alpha = $this->fixture();
        ['token' => $token, 'grant' => $grant] = $this->grantFor($alpha);

        $this->post(route('external.forms.store', ['token' => $token]), $this->completeAnswersFor($alpha))->assertOk();
        $this->assertSame(1, (int) $grant->fresh()->use_count);

        // The link is spent. Both verbs now refuse, identically.
        $this->get(route('external.forms.show', ['token' => $token]))->assertNotFound();
        $this->post(route('external.forms.store', ['token' => $token]), $this->completeAnswersFor($alpha))->assertNotFound();

        $this->assertSame(1, (int) $grant->fresh()->use_count, 'A refused replay must not increment the count.');
        $this->assertSame(1, FormSubmission::query()->count());
    }

    #[Test]
    public function a_multi_use_link_follows_its_own_use_count_contract(): void
    {
        $alpha = $this->fixture();
        ['token' => $token, 'grant' => $grant] = $this->grantFor($alpha, maxUses: 2);

        // max_uses = 2 means two submissions, and that is what happens: the
        // second open finds no draft to resume and starts a fresh one.
        $this->post(route('external.forms.store', ['token' => $token]), $this->completeAnswersFor($alpha))->assertOk();
        $this->assertSame(1, (int) $grant->fresh()->use_count);

        $this->get(route('external.forms.show', ['token' => $token]))->assertOk();
        $this->post(route('external.forms.store', ['token' => $token]), $this->completeAnswersFor($alpha))->assertOk();
        $this->assertSame(2, (int) $grant->fresh()->use_count);
        $this->assertSame(2, FormSubmission::query()->where('status', 'submitted')->count());

        // The third is refused: the count is the contract, and it is exhausted.
        $this->get(route('external.forms.show', ['token' => $token]))->assertNotFound();
        $this->post(route('external.forms.store', ['token' => $token]), $this->completeAnswersFor($alpha))->assertNotFound();
        $this->assertSame(2, (int) $grant->fresh()->use_count);
    }

    /*
    |--------------------------------------------------------------------------
    | Tampering
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_posted_customer_enrolment_form_or_submission_id_is_ignored(): void
    {
        $alpha = $this->fixture();
        $beta = $this->fixture('Beta Textiles', 'C-BETA');
        $token = $this->grantFor($alpha)['token'];

        $this->post(route('external.forms.store', ['token' => $token]), array_merge(
            $this->completeAnswersFor($alpha),
            [
                'customer_id' => $beta['customer']->getKey(),
                'enrollment_id' => $beta['enrollment']->getKey(),
                'form_version_id' => $beta['version']->getKey(),
                'form_template_id' => $beta['version']->form_template_id,
                'submission_id' => 999,
                'batch_id' => $beta['enrollment']->batch_id,
                'source' => 'internal',
                'created_by' => $this->actor->getKey(),
                'status' => 'submitted',
            ],
        ))->assertOk();

        $submission = FormSubmission::query()->sole();

        // Every one of those was resolved from the grant instead.
        $this->assertSame((int) $alpha['customer']->getKey(), (int) $submission->customer_id);
        $this->assertSame((int) $alpha['enrollment']->getKey(), (int) $submission->enrollment_id);
        $this->assertSame((int) $alpha['version']->getKey(), (int) $submission->form_version_id);
        $this->assertNull($submission->created_by);
        $this->assertSame(ActorSource::ExternalGrant->value, $submission->source->value ?? $submission->source);
    }

    #[Test]
    public function an_answer_for_another_forms_question_is_dropped(): void
    {
        $alpha = $this->fixture();
        $beta = $this->fixture('Beta Textiles', 'C-BETA');
        $token = $this->grantFor($alpha)['token'];

        $this->post(route('external.forms.store', ['token' => $token]), array_merge(
            $this->completeAnswersFor($alpha),
            ['answers' => array_replace(
                $this->completeAnswersFor($alpha)['answers'],
                [$beta['text']->getKey() => 'Answering Beta from Alphas link'],
            )],
        ))->assertOk();

        $submission = FormSubmission::query()->sole();

        $this->assertSame(
            0,
            $submission->answers()->where('question_id', $beta['text']->getKey())->count(),
            'A question from another form has no place in this submission.',
        );

        $this->assertSame(
            $alpha['version']->questions()->count(),
            $submission->answers()->count(),
        );
    }

    #[Test]
    public function an_option_belonging_to_another_question_is_dropped(): void
    {
        $alpha = $this->fixture();
        $beta = $this->fixture('Beta Textiles', 'C-BETA');
        $token = $this->grantFor($alpha)['token'];

        $payload = $this->completeAnswersFor($alpha);
        $payload['selections'][$alpha['choice']->getKey()] = [$beta['optionA']->getKey()];

        $this->post(route('external.forms.store', ['token' => $token]), $payload)
            ->assertOk()
            ->assertSee('Please answer these before submitting');

        $submission = FormSubmission::query()->sole();

        $this->assertSame(
            0,
            $submission->answers()
                ->where('question_id', $alpha['choice']->getKey())
                ->first()
                ?->answerOptions()->count() ?? 0,
        );
    }

    #[Test]
    public function one_businesss_link_cannot_reach_anothers_form(): void
    {
        $alpha = $this->fixture();
        $beta = $this->fixture('Beta Textiles', 'C-BETA');

        $alphaToken = $this->grantFor($alpha)['token'];
        $betaToken = $this->grantFor($beta)['token'];

        // Alpha's link opens Alpha's form and says so.
        $this->get(route('external.forms.show', ['token' => $alphaToken]))
            ->assertOk()
            ->assertSee('Alpha Metalworks')
            ->assertDontSee('Beta Textiles');

        // Beta's link opens Beta's, and neither can see the other's answers.
        $this->post(route('external.forms.store', ['token' => $betaToken]), $this->completeAnswersFor($beta))->assertOk();

        $this->get(route('external.forms.show', ['token' => $alphaToken]))
            ->assertOk()
            ->assertDontSee('Beta Textiles')
            ->assertDontSee('Sheet-metal enclosures');

        $betaSubmission = FormSubmission::query()->where('customer_id', $beta['customer']->getKey())->sole();
        $this->assertSame((int) $beta['enrollment']->getKey(), (int) $betaSubmission->enrollment_id);
    }

    /*
    |--------------------------------------------------------------------------
    | What the surface must never become
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function using_a_link_creates_no_user_no_password_and_no_session_identity(): void
    {
        $usersBefore = User::query()->count();

        $alpha = $this->fixture();
        $token = $this->grantFor($alpha)['token'];

        $this->get(route('external.forms.show', ['token' => $token]))->assertOk();
        $this->post(route('external.forms.store', ['token' => $token]), $this->completeAnswersFor($alpha))->assertOk();

        $this->assertSame($usersBefore, User::query()->count(), 'No account is created by external participation.');
        $this->assertGuest();
        $this->assertGuest('web');

        $customer = $alpha['customer']->fresh();
        $this->assertNotInstanceOf(Authenticatable::class, $customer);
        $this->assertArrayNotHasKey('password', $customer->getAttributes());
        $this->assertSame(0, User::query()->where('name', $customer->name)->count());
    }

    #[Test]
    public function an_external_visitor_cannot_reach_the_internal_application(): void
    {
        $alpha = $this->fixture();
        $token = $this->grantFor($alpha)['token'];

        // Use the link first: whatever session it leaves behind must be worthless.
        $this->get(route('external.forms.show', ['token' => $token]))->assertOk();

        foreach ([
            route('dashboard'),
            route('customers.index'),
            route('customers.show', $alpha['customer']),
            route('reports.index'),
            route('users.index'),
            route('admin.roles'),
        ] as $internal) {
            $this->get($internal)->assertRedirect(route('login'));
        }
    }

    #[Test]
    public function the_external_routes_are_the_only_unauthenticated_application_routes(): void
    {
        $unprotected = [];

        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();

            if (in_array('auth', $middleware, true) || in_array('guest', $middleware, true)) {
                continue;
            }

            $uri = $route->uri();

            if (preg_match('#^(_|up$|storage/|livewire-|sanctum/)#', $uri)) {
                continue;
            }

            // Fortify's own session endpoints. None of them read customer data.
            if (in_array($uri, [
                '/', 'login', 'logout',
                'user/password', 'user/confirm-password', 'user/confirmed-password-status',
            ], true)) {
                continue;
            }

            if (str_starts_with($uri, 'reset-password') || str_starts_with($uri, 'forgot-password')) {
                continue;
            }

            $unprotected[] = $route->methods()[0].' '.$uri;
        }

        // Exactly the two external routes, and nothing else has slipped out of
        // the authenticated area alongside them.
        $this->assertSame(
            ['GET external/forms/{token}', 'POST external/forms/{token}'],
            $unprotected,
        );
    }

    #[Test]
    public function the_raw_token_is_never_stored_or_logged(): void
    {
        $written = [];
        Log::listen(function ($message) use (&$written): void {
            $written[] = $message->message.' '.json_encode($message->context);
        });

        $alpha = $this->fixture();
        ['token' => $token, 'grant' => $grant] = $this->grantFor($alpha);

        $this->get(route('external.forms.show', ['token' => $token]))->assertOk();
        $this->post(route('external.forms.store', ['token' => $token]), $this->completeAnswersFor($alpha))->assertOk();
        $this->get(route('external.forms.show', ['token' => 'a-guess']))->assertNotFound();

        // Not in the grant row.
        $this->assertNotSame($token, $grant->fresh()->token_hash);
        $this->assertStringNotContainsString($token, json_encode($grant->fresh()->getAttributes()));

        // Not in any audit entry - including the refusal, which records a reason.
        foreach (AuditLog::query()->get() as $entry) {
            $this->assertStringNotContainsString($token, json_encode($entry->getAttributes()));
        }

        // Not in anything the application logged.
        foreach ($written as $line) {
            $this->assertStringNotContainsString($token, $line);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | The internal side of the same submission
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function staff_see_the_external_submission_against_the_right_business(): void
    {
        $alpha = $this->fixture();
        $token = $this->grantFor($alpha)['token'];

        $this->post(route('external.forms.store', ['token' => $token]), $this->completeAnswersFor($alpha))->assertOk();

        $submission = FormSubmission::query()->sole();

        $this->actingAs($this->staff())
            ->get(route('customers.forms', $alpha['customer']))
            ->assertOk()
            ->assertSee('Business Intake');

        $this->actingAs($this->staff())
            ->get(route('forms.show', $submission))
            ->assertOk()
            ->assertSee('Sheet-metal enclosures')
            ->assertSee('External Grant');
    }

    #[Test]
    public function scoring_remains_an_internal_act_on_an_external_submission(): void
    {
        $alpha = $this->fixture();
        $token = $this->grantFor($alpha)['token'];

        $this->post(route('external.forms.store', ['token' => $token]), $this->completeAnswersFor($alpha))->assertOk();

        $submission = FormSubmission::query()->sole();

        // Nothing was scored by the act of submitting.
        $this->assertSame(0, $submission->scores()->count());

        app(ScoringService::class)->score($submission);

        $scores = $submission->fresh()->scores;
        $this->assertGreaterThan(0, $scores->count());

        // Every figure carries the scheme that produced it, and no band.
        foreach ($scores as $score) {
            $this->assertNotNull($score->scheme_version);
            $this->assertNotNull($score->computed_at);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * A published four-question instrument covering text, number, boolean and
     * single-choice, plus a customer and an enrolment to hang it on.
     *
     * @return array<string, mixed>
     */
    private function fixture(string $name = 'Alpha Metalworks', string $code = 'C-ALPHA'): array
    {
        $customer = Customer::factory()->create(['name' => $name, 'code' => $code]);

        $program = Program::factory()->create(['session_count' => 6]);
        $batch = Batch::factory()->create(['program_id' => $program->getKey()]);

        $enrollment = Enrollment::factory()->create([
            'customer_id' => $customer->getKey(),
            'batch_id' => $batch->getKey(),
        ]);

        $builder = app(FormBuilderService::class);
        $skill = SkillArea::factory()->create(['key' => 'ops_'.strtolower($code), 'name' => 'Operations']);

        $template = $builder->createTemplate([
            'customer_id' => null,
            'key' => 'intake_'.strtolower($code),
            'name' => 'Business Intake',
            'description' => 'A short picture of how the business runs today.',
            'is_scored' => true,
        ]);

        $version = $builder->createDraftVersion($template);
        $about = $builder->addSection($version, 'About the business', 0, 'What you make and who makes it.');
        $systems = $builder->addSection($version, 'Systems', 1);

        $text = $builder->addQuestion($version, $about, QuestionType::Text, 'What does the business make?', 0, ['is_required' => true]);
        $number = $builder->addQuestion($version, $about, QuestionType::Number, 'How many people work here?', 1, ['is_required' => true, 'skill_area_id' => $skill->getKey(), 'max_score' => 5]);
        $boolean = $builder->addQuestion($version, $systems, QuestionType::Boolean, 'Do you hold a weekly review?', 2, ['is_required' => true]);
        $choice = $builder->addQuestion($version, $systems, QuestionType::SelectOne, 'How would you rate your systems?', 3, ['is_required' => true, 'skill_area_id' => $skill->getKey()]);

        $optionA = $builder->addOption($choice, 'none', 'Nothing written down', 0);
        $optionB = $builder->addOption($choice, 'followed', 'Written down and followed', 1);

        app(FormPublishingService::class)->publish($version, $this->actor);

        return [
            'customer' => $customer,
            'enrollment' => $enrollment,
            'version' => $version->fresh(),
            'text' => $text,
            'number' => $number,
            'boolean' => $boolean,
            'choice' => $choice,
            'optionA' => $optionA,
            'optionB' => $optionB,
        ];
    }

    /**
     * @param  array<string, mixed>  $fixture
     * @return array{token: string, grant: AccessGrant}
     */
    private function grantFor(array $fixture, int $maxUses = 1): array
    {
        $issued = app(AccessGrantService::class)->issue(
            customer: $fixture['customer'],
            enrollment: $fixture['enrollment'],
            subject: $fixture['version'],
            ability: GrantAbility::CompleteForm,
            expiresAt: now()->addDays(7),
            actor: $this->actor,
            maxUses: $maxUses,
        );

        return ['token' => $issued->plaintextToken, 'grant' => $issued->grant];
    }

    /**
     * @param  array<string, mixed>  $fixture
     * @return array<string, mixed>
     */
    private function completeAnswersFor(array $fixture): array
    {
        return [
            'action' => 'submit',
            'answers' => [
                $fixture['text']->getKey() => 'Sheet-metal enclosures',
                $fixture['number']->getKey() => '18',
                $fixture['boolean']->getKey() => '1',
            ],
            'selections' => [
                $fixture['choice']->getKey() => [$fixture['optionB']->getKey()],
            ],
        ];
    }
}
