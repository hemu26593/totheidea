<?php

declare(strict_types=1);

namespace Tests\Feature\Uat;

use App\Domain\Access\AccessGrantService;
use App\Domain\Access\ExternalFormSubmissionService;
use App\Domain\Forms\FormBuilderService;
use App\Domain\Forms\FormPublishingService;
use App\Enums\GrantAbility;
use App\Enums\QuestionType;
use App\Exceptions\AccessGrantDeniedException;
use App\Exceptions\CustomerIsolationException;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Enrollment;
use App\Models\FormVersion;
use App\Models\Program;
use App\Models\Question;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * UAT section 6: how a participating business interacts with the platform
 * without an account.
 *
 * FINDING (UAT-C1, reported - deliberately not "fixed" here).
 *
 * The AccessGrant domain is complete and correct: tokens are issued hashed,
 * scoped to one customer, one enrolment and one subject, expire, count their
 * uses atomically, and refuse a replay. What does not exist is any HTTP
 * surface that a participant could reach. No route redeems a token, and no
 * Livewire component or view references AccessGrantService,
 * AccessGrantRedeemer or ExternalFormSubmissionService. Every application
 * route sits behind `auth`.
 *
 * The consequence for UAT is concrete: a business cannot fill in its own
 * intake form. Staff can capture answers on their behalf, and everything
 * downstream of that works, but the participant-facing half of the workflow
 * cannot be exercised because it has not been built. Building it is a module,
 * not a defect fix, so UAT records it rather than inventing it.
 *
 * The tests below therefore verify two things that must hold whatever is built
 * next: the domain that a future external surface will sit on works, and a
 * Customer never becomes an authenticatable User.
 */
class ExternalAccessUatTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function no_route_in_the_application_lets_an_unauthenticated_visitor_reach_customer_data(): void
    {
        $unprotected = [];

        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();

            if (in_array('auth', $middleware, true) || in_array('guest', $middleware, true)) {
                continue;
            }

            $uri = $route->uri();

            // Framework and asset plumbing, plus the password-reset flow, are
            // reachable without a session by design.
            if (preg_match('#^(_|up$|storage/|livewire-|sanctum/)#', $uri)) {
                continue;
            }

            // Fortify's own session endpoints: signing in, signing out, and the
            // password-confirmation flow. None of them read customer data.
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

        $this->assertSame(
            [],
            $unprotected,
            'A route outside the authenticated area appeared. If it is the external participant surface, '
            .'it must redeem an AccessGrant rather than simply being public.',
        );
    }

    #[Test]
    public function a_customer_is_never_an_authenticatable_user(): void
    {
        $customer = Customer::factory()->create();

        $this->assertNotInstanceOf(Authenticatable::class, $customer);
        $this->assertFalse(in_array('password', $customer->getFillable(), true));
        $this->assertArrayNotHasKey('password', $customer->getAttributes());
        $this->assertNull($customer->getAttribute('remember_token'));

        // Onboarding a business creates no account.
        $this->assertSame(0, User::query()->where('name', $customer->name)->count());
    }

    #[Test]
    public function a_scoped_grant_lets_the_business_fill_in_and_submit_exactly_one_form(): void
    {
        $this->seedAuthorization();

        $fixture = $this->publishedIntakeFixture();

        $contact = CustomerContact::factory()->create([
            'customer_id' => $fixture['customer']->getKey(),
            'is_primary' => true,
        ]);

        // A grant is issued against the FORM VERSION - the instrument put to
        // every business - and scoped to one customer and one enrolment. The
        // submission it produces is created against that enrolment, which is
        // where the isolation lives.
        $issued = app(AccessGrantService::class)->issue(
            customer: $fixture['customer'],
            enrollment: $fixture['enrollment'],
            subject: $fixture['version'],
            ability: GrantAbility::CompleteForm,
            expiresAt: now()->addDays(7),
            actor: $this->admin(),
            contact: $contact,
            maxUses: 1,
        );

        $external = app(ExternalFormSubmissionService::class);
        $token = $issued->plaintextToken;

        // Opening the link does not spend it - the business may come back.
        $submission = $external->open($token, '203.0.113.9');
        $this->assertSame((int) $fixture['enrollment']->getKey(), (int) $submission->enrollment_id);
        $this->assertSame($submission->getKey(), $external->open($token, '203.0.113.9')->getKey());
        $this->assertSame(0, (int) $issued->grant->fresh()->use_count);

        // Answering saves progress without spending the link either.
        $external->answer($token, $submission, $fixture['question'], ['value_text' => 'Sheet-metal enclosures'], ip: '203.0.113.9');
        $this->assertSame(0, (int) $issued->grant->fresh()->use_count);

        // Submitting is what consumes the single use.
        $external->submit($token, $submission, '203.0.113.9');
        $this->assertSame(1, (int) $issued->grant->fresh()->use_count);

        // And the spent link is refused, not silently re-served.
        $this->expectException(AccessGrantDeniedException::class);
        $external->submit($token, $submission->fresh(), '203.0.113.9');
    }

    #[Test]
    public function one_businesss_link_cannot_write_to_anothers_submission(): void
    {
        $this->seedAuthorization();

        $alpha = $this->publishedIntakeFixture('Alpha Metalworks', 'C-ALPHA');
        $beta = $this->publishedIntakeFixture('Beta Textiles', 'C-BETA');

        $issued = app(AccessGrantService::class)->issue(
            customer: $alpha['customer'],
            enrollment: $alpha['enrollment'],
            subject: $alpha['version'],
            ability: GrantAbility::CompleteForm,
            expiresAt: now()->addDays(7),
            actor: $this->admin(),
        );

        $external = app(ExternalFormSubmissionService::class);

        // Alpha's link, pointed at a submission belonging to Beta.
        $betaSubmission = $external->open(
            app(AccessGrantService::class)->issue(
                customer: $beta['customer'],
                enrollment: $beta['enrollment'],
                subject: $beta['version'],
                ability: GrantAbility::CompleteForm,
                expiresAt: now()->addDays(7),
                actor: $this->admin(),
            )->plaintextToken,
            '198.51.100.4',
        );

        // The refusal is a flat "this link is not valid" - the same answer a
        // bad token gets. Saying "that submission belongs to someone else"
        // would confirm the other business's record exists.
        $this->expectException(AccessGrantDeniedException::class);

        $external->answer(
            $issued->plaintextToken,
            $betaSubmission,
            $beta['question'],
            ['value_text' => 'Crossing the line'],
            ip: '203.0.113.9',
        );
    }

    #[Test]
    public function a_grant_cannot_be_issued_for_a_subject_outside_the_named_enrolment(): void
    {
        $this->seedAuthorization();

        $alpha = $this->publishedIntakeFixture('Alpha Metalworks', 'C-ALPHA');
        $beta = $this->publishedIntakeFixture('Beta Textiles', 'C-BETA');

        // accept_terms is scoped to the enrolment itself, so naming another
        // business's enrolment as the subject is refused at issue time.
        $this->expectException(CustomerIsolationException::class);

        app(AccessGrantService::class)->issue(
            customer: $alpha['customer'],
            enrollment: $alpha['enrollment'],
            subject: $beta['enrollment'],
            ability: GrantAbility::AcceptTerms,
            expiresAt: now()->addDays(7),
            actor: $this->admin(),
        );
    }

    /**
     * A customer, an enrolment, and a published single-question intake form.
     *
     * @return array{customer: Customer, enrollment: Enrollment, version: FormVersion, question: Question}
     */
    private function publishedIntakeFixture(string $name = 'Alpha Metalworks', string $code = 'C-ALPHA'): array
    {
        $actor = $this->admin();

        $customer = Customer::factory()->create(['name' => $name, 'code' => $code]);

        $program = Program::factory()->create(['session_count' => 6]);
        $batch = Batch::factory()->create(['program_id' => $program->getKey()]);

        $enrollment = Enrollment::factory()->create([
            'customer_id' => $customer->getKey(),
            'batch_id' => $batch->getKey(),
        ]);

        $builder = app(FormBuilderService::class);

        $template = $builder->createTemplate([
            'customer_id' => null,
            'key' => 'intake_'.strtolower($code),
            'name' => 'Business Intake',
            'is_scored' => false,
        ]);

        $version = $builder->createDraftVersion($template);
        $section = $builder->addSection($version, 'About the business', 0);
        $question = $builder->addQuestion($version, $section, QuestionType::Text, 'What does the business make?', 0);

        app(FormPublishingService::class)->publish($version, $actor);

        return [
            'customer' => $customer,
            'enrollment' => $enrollment,
            'version' => $version->fresh(),
            'question' => $question,
        ];
    }
}
