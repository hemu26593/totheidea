<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Domain\Access\FormLinkService;
use App\Domain\Forms\FormBuilderService;
use App\Domain\Forms\FormPublishingService;
use App\Enums\ActorSource;
use App\Enums\AuditAction;
use App\Enums\GrantAbility;
use App\Enums\QuestionType;
use App\Livewire\Customers\Forms as FormsScreen;
use App\Mail\FormLinkMessage;
use App\Models\AccessGrant;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Enrollment;
use App\Models\FormSubmission;
use App\Models\FormVersion;
use App\Models\Note;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * One-click delivery of a business's own form link.
 *
 * The acceptance test is the whole path, not the pieces: staff press one
 * button, the business receives an email, opens the link, fills the form in,
 * and the submission comes back as external_grant with no internal author.
 */
class FormLinkDeliveryTest extends TestCase
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
    | A. Authorization
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function super_admin_admin_and_staff_can_all_send_a_form_link(): void
    {
        foreach (['superAdmin', 'admin', 'staff'] as $index => $role) {
            $fixture = $this->fixture('Business '.$index, 'C-ROLE'.$index);
            $actor = $this->{$role}();

            $this->assertTrue($actor->can('create', AccessGrant::class), "[{$role}] must be able to issue a grant.");

            Livewire::actingAs($actor)
                ->test(FormsScreen::class, ['customer' => $fixture['customer']])
                ->call('sendFormLink', $fixture['enrollment']->getKey(), $fixture['template']->getKey())
                ->assertHasNoErrors();

            $this->assertSame(
                1,
                AccessGrant::query()->where('customer_id', $fixture['customer']->getKey())->count(),
                "[{$role}] pressed the button and no grant was issued.",
            );
        }
    }

    #[Test]
    public function an_actor_without_the_permission_is_refused_server_side(): void
    {
        $fixture = $this->fixture();

        // Someone who can OPEN the forms screen but holds no access_grants.issue -
        // so the refusal is the policy's, proved at the action rather than by the
        // button being absent.
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('customers.view', 'forms.view');

        $this->assertTrue($viewer->can('customers.view'));
        $this->assertFalse($viewer->can('create', AccessGrant::class));

        Livewire::actingAs($viewer)
            ->test(FormsScreen::class, ['customer' => $fixture['customer']])
            ->call('sendFormLink', $fixture['enrollment']->getKey(), $fixture['template']->getKey())
            ->assertForbidden();

        $this->assertSame(0, AccessGrant::query()->count());
        Mail::assertNothingSent();
    }

    #[Test]
    public function one_business_cannot_send_another_businesss_form(): void
    {
        $alpha = $this->fixture('Alpha Metalworks', 'C-ALPHA');
        $beta = $this->fixture('Beta Textiles', 'C-BETA');

        // Beta's submission id, injected into Alpha's workspace.
        Livewire::actingAs($this->admin())
            ->test(FormsScreen::class, ['customer' => $alpha['customer']])
            ->call('sendFormLink', $beta['enrollment']->getKey(), $beta['template']->getKey())
            ->assertNotFound();

        $this->assertSame(0, AccessGrant::query()->count());
        Mail::assertNothingSent();
    }

    /*
    |--------------------------------------------------------------------------
    | B. Validation
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_business_with_no_contact_email_is_refused_with_an_actionable_message(): void
    {
        $fixture = $this->fixture(withContact: false);

        Livewire::actingAs($this->admin())
            ->test(FormsScreen::class, ['customer' => $fixture['customer']])
            ->call('sendFormLink', $fixture['enrollment']->getKey(), $fixture['template']->getKey())
            ->assertHasErrors('domain');

        $this->assertSame(0, AccessGrant::query()->count());
        Mail::assertNothingSent();
    }

    #[Test]
    public function an_unpublished_form_cannot_be_sent(): void
    {
        $fixture = $this->fixture();

        // Drop the version out of published after the submission was bound.
        $fixture['version']->forceFill(['status' => 'draft'])->save();

        $this->expectExceptionMessage('must be published');
        app(FormLinkService::class)->send($fixture['enrollment'], $fixture['template'], $this->admin());
    }

    #[Test]
    public function an_enrolment_that_is_not_active_cannot_be_sent_a_form(): void
    {
        $fixture = $this->fixture();

        $fixture['enrollment']->forceFill(['status' => 'withdrawn'])->save();

        $this->expectExceptionMessage('not active');
        app(FormLinkService::class)->send($fixture['enrollment']->fresh(), $fixture['template'], $this->admin());
    }

    #[Test]
    public function a_form_belonging_to_another_business_cannot_be_sent(): void
    {
        $alpha = $this->fixture('Alpha Metalworks', 'C-ALPHA');
        $beta = $this->fixture('Beta Textiles', 'C-BETA');

        // Beta's private template, against Alpha's enrolment.
        $betaPrivate = app(FormBuilderService::class)->createTemplate([
            'customer_id' => $beta['customer']->getKey(),
            'key' => 'private_beta',
            'name' => 'Beta Private',
            'is_scored' => false,
        ]);

        $this->expectExceptionMessage('does not belong to this business');
        app(FormLinkService::class)->send($alpha['enrollment'], $betaPrivate, $this->admin());
    }

    #[Test]
    public function a_link_expires_fourteen_days_after_it_is_sent(): void
    {
        // Fourteen days is the client's confirmed production default. Pinned
        // here so a change to it is a deliberate one rather than a drift.
        $this->assertSame(14, (int) config('access.link_expiry_days'));

        $fixture = $this->fixture();

        $this->travelTo('2026-09-12 09:00:00');
        app(FormLinkService::class)->send($fixture['enrollment'], $fixture['template'], $this->admin());

        $this->assertSame(
            '2026-09-26 09:00:00',
            AccessGrant::query()->sole()->expires_at->format('Y-m-d H:i:s'),
        );

        $this->travelBack();
    }

    #[Test]
    public function a_grant_is_never_issued_without_a_configured_expiry(): void
    {
        config(['access.link_expiry_days' => 0]);

        $fixture = $this->fixture();

        $this->expectExceptionMessage('No form link expiry is configured');

        try {
            app(FormLinkService::class)->send($fixture['enrollment'], $fixture['template'], $this->admin());
        } finally {
            $this->assertSame(0, AccessGrant::query()->count());
        }
    }

    /*
    |--------------------------------------------------------------------------
    | C. The grant
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_grant_is_scoped_to_the_business_enrolment_contact_and_form_version(): void
    {
        $fixture = $this->fixture();

        app(FormLinkService::class)->send($fixture['enrollment'], $fixture['template'], $this->admin());

        $grant = AccessGrant::query()->sole();

        $this->assertSame((int) $fixture['customer']->getKey(), (int) $grant->customer_id);
        $this->assertSame((int) $fixture['enrollment']->getKey(), (int) $grant->enrollment_id);
        $this->assertSame((int) $fixture['contact']->getKey(), (int) $grant->customer_contact_id);
        $this->assertSame(FormVersion::class, $grant->subject_type);
        $this->assertSame((int) $fixture['version']->getKey(), (int) $grant->subject_id);
        $this->assertSame(GrantAbility::CompleteForm, $grant->ability);
        $this->assertSame(1, (int) $grant->max_uses);
        $this->assertSame(0, (int) $grant->use_count);
        $this->assertTrue($grant->expires_at->isFuture());
        $this->assertNull($grant->revoked_at);
    }

    #[Test]
    public function only_the_hash_is_stored_and_the_plaintext_token_is_nowhere_in_the_database(): void
    {
        $fixture = $this->fixture();

        app(FormLinkService::class)->send($fixture['enrollment'], $fixture['template'], $this->admin());

        $grant = AccessGrant::query()->sole();

        // The URL that was emailed carries the plaintext; recover it from the
        // message and prove it appears in no stored row.
        $token = $this->tokenFromSentMail();

        $this->assertNotSame($token, $grant->token_hash);
        $this->assertSame(hash('sha256', $token), $grant->token_hash);

        $this->assertStringNotContainsString($token, json_encode($grant->getAttributes()));

        foreach (AuditLog::query()->get() as $entry) {
            $this->assertStringNotContainsString($token, json_encode($entry->getAttributes()));
        }
    }

    #[Test]
    public function issuing_is_audited_through_the_existing_audit_trail(): void
    {
        $fixture = $this->fixture();
        $actor = $this->admin();

        app(FormLinkService::class)->send($fixture['enrollment'], $fixture['template'], $actor);

        $entry = AuditLog::query()->where('action', AuditAction::AccessGrantIssued)->sole();

        $this->assertSame((int) $actor->getKey(), (int) $entry->actor_id);
        $this->assertStringContainsString('complete_form', json_encode($entry->new_values));
        $this->assertStringContainsString((string) $fixture['customer']->getKey(), json_encode($entry->new_values));
    }

    /*
    |--------------------------------------------------------------------------
    | D. The email
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_email_goes_to_the_businesss_own_contact_and_carries_a_working_link(): void
    {
        $fixture = $this->fixture();

        app(FormLinkService::class)->send($fixture['enrollment'], $fixture['template'], $this->admin());

        Mail::assertSent(FormLinkMessage::class, function (FormLinkMessage $mail) use ($fixture): bool {
            $this->assertTrue($mail->hasTo($fixture['contact']->email));

            $rendered = $mail->render();

            // The business's own details, and the link.
            $this->assertStringContainsString($fixture['customer']->name, $rendered);
            $this->assertStringContainsString('Business Intake', $rendered);
            $this->assertStringContainsString(route('external.forms.show', ['token' => $this->tokenFrom($mail->url)]), $rendered);

            return true;
        });
    }

    #[Test]
    public function the_link_is_built_from_the_route_helper_not_a_hard_coded_host(): void
    {
        $fixture = $this->fixture();
        app(FormLinkService::class)->send($fixture['enrollment'], $fixture['template'], $this->admin());

        Mail::assertSent(FormLinkMessage::class, function (FormLinkMessage $mail): bool {
            // Whatever APP_URL is, the link must be exactly what the external
            // route generates - so a subdirectory deployment is honoured and no
            // host is written into the codebase.
            $expectedRoot = rtrim(route('external.forms.show', ['token' => 'X']), 'X');

            $this->assertStringStartsWith($expectedRoot, $mail->url);
            $this->assertStringStartsWith(url('/'), $mail->url);

            return true;
        });

        // And no host literal appears in the source that builds it.
        $source = (string) file_get_contents(app_path('Domain/Access/FormLinkService.php'));
        $this->assertStringNotContainsString('http://', $source);
        $this->assertStringNotContainsString('https://', $source);
    }

    #[Test]
    public function the_email_leaks_no_internal_information(): void
    {
        $fixture = $this->fixture();
        $actor = $this->admin();

        // Something internal and sensitive sitting alongside the form.
        Note::factory()->create([
            'notable_type' => Customer::class,
            'notable_id' => $fixture['customer']->getKey(),
            'body' => 'INTERNAL: this business is behind on payments.',
            'author_id' => $actor->getKey(),
            'is_internal' => true,
        ]);

        app(FormLinkService::class)->send($fixture['enrollment'], $fixture['template'], $actor);

        Mail::assertSent(FormLinkMessage::class, function (FormLinkMessage $mail) use ($actor): bool {
            $rendered = $mail->render();

            foreach ([
                'INTERNAL', 'behind on payments',
                $actor->name, $actor->email,          // no staff identity
                route('dashboard'), route('login'),   // no internal doorway
                'Beta Textiles',                      // no other business
            ] as $mustNotAppear) {
                $this->assertStringNotContainsString($mustNotAppear, $rendered);
            }

            return true;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | E. The external flow, end to end
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_business_opens_the_emailed_link_and_completes_the_form(): void
    {
        $fixture = $this->fixture();

        Livewire::actingAs($this->staff())
            ->test(FormsScreen::class, ['customer' => $fixture['customer']])
            ->call('sendFormLink', $fixture['enrollment']->getKey(), $fixture['template']->getKey())
            ->assertHasNoErrors();

        $token = $this->tokenFromSentMail();

        // The business, with no session of any kind.
        $this->get(route('external.forms.show', ['token' => $token]))
            ->assertOk()
            ->assertSee('Business Intake')
            ->assertSee($fixture['customer']->name)
            ->assertSee('What does the business make?');

        $this->post(route('external.forms.store', ['token' => $token]), [
            'action' => 'submit',
            'answers' => [$fixture['question']->getKey() => 'Sheet-metal enclosures'],
        ])->assertOk()->assertSee('your form has been submitted');

        $submission = FormSubmission::query()->sole();

        $this->assertSame('submitted', $submission->status);
        $this->assertSame(ActorSource::ExternalGrant->value, $submission->source->value ?? $submission->source);
        $this->assertNull($submission->created_by, 'An external submission has no internal author.');
        $this->assertSame((int) AccessGrant::query()->sole()->getKey(), (int) $submission->access_grant_id);
        $this->assertSame((int) $fixture['customer']->getKey(), (int) $submission->customer_id);
        $this->assertSame((int) $fixture['enrollment']->getKey(), (int) $submission->enrollment_id);

        // And the internal user sees the answer.
        $this->actingAs($this->staff())
            ->get(route('forms.show', $submission))
            ->assertOk()
            ->assertSee('External Grant');
    }

    #[Test]
    public function one_businesss_link_cannot_open_anothers_form(): void
    {
        $alpha = $this->fixture('Alpha Metalworks', 'C-ALPHA');
        $beta = $this->fixture('Beta Textiles', 'C-BETA');

        app(FormLinkService::class)->send($alpha['enrollment'], $alpha['template'], $this->admin());
        $alphaToken = $this->tokenFromSentMail();

        $this->get(route('external.forms.show', ['token' => $alphaToken]))
            ->assertOk()
            ->assertSee('Alpha Metalworks')
            ->assertDontSee('Beta Textiles');

        $this->assertSame(0, FormSubmission::query()
            ->where('customer_id', $beta['customer']->getKey())
            ->where('source', ActorSource::ExternalGrant->value)
            ->count());
    }

    /*
    |--------------------------------------------------------------------------
    | F. Expiry and revocation
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_expired_link_no_longer_opens_the_form(): void
    {
        $fixture = $this->fixture();

        app(FormLinkService::class)->send($fixture['enrollment'], $fixture['template'], $this->admin());
        $token = $this->tokenFromSentMail();

        AccessGrant::query()->sole()->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->get(route('external.forms.show', ['token' => $token]))->assertNotFound();
    }

    #[Test]
    public function a_revoked_link_no_longer_opens_the_form_and_the_ui_says_so(): void
    {
        $fixture = $this->fixture();
        $actor = $this->admin();

        Livewire::actingAs($actor)
            ->test(FormsScreen::class, ['customer' => $fixture['customer']])
            ->call('sendFormLink', $fixture['enrollment']->getKey(), $fixture['template']->getKey());

        $token = $this->tokenFromSentMail();
        $this->get(route('external.forms.show', ['token' => $token]))->assertOk();

        Livewire::actingAs($actor)
            ->test(FormsScreen::class, ['customer' => $fixture['customer']])
            ->call('revokeFormLink', $fixture['enrollment']->getKey(), $fixture['template']->getKey())
            ->assertHasNoErrors()
            ->assertSee('Withdrawn');

        $this->get(route('external.forms.show', ['token' => $token]))->assertNotFound();

        $grant = AccessGrant::query()->sole();
        $this->assertNotNull($grant->revoked_at);
        $this->assertSame((int) $actor->getKey(), (int) $grant->revoked_by);

        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::AccessGrantRevoked)->count());
    }

    /*
    |--------------------------------------------------------------------------
    | G. Double click and resend
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function pressing_send_twice_never_leaves_two_working_links(): void
    {
        $fixture = $this->fixture();
        $actor = $this->admin();

        $screen = Livewire::actingAs($actor)->test(FormsScreen::class, ['customer' => $fixture['customer']]);

        $screen->call('sendFormLink', $fixture['enrollment']->getKey(), $fixture['template']->getKey())->assertHasNoErrors();
        $firstToken = $this->tokenFromSentMail();

        $screen->call('sendFormLink', $fixture['enrollment']->getKey(), $fixture['template']->getKey())->assertHasNoErrors();
        $secondToken = $this->tokenFromSentMail();

        $this->assertNotSame($firstToken, $secondToken, 'The plaintext cannot be recovered, so a resend is a new grant.');

        // Two grants exist as a record, but only ONE of them opens anything.
        $this->assertSame(2, AccessGrant::query()->count());
        $this->assertSame(1, AccessGrant::query()
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->whereColumn('use_count', '<', 'max_uses')
            ->count());

        $this->get(route('external.forms.show', ['token' => $firstToken]))->assertNotFound();
        $this->get(route('external.forms.show', ['token' => $secondToken]))->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | H. The screen
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_screen_offers_send_then_reports_the_link_state(): void
    {
        $fixture = $this->fixture();

        $screen = Livewire::actingAs($this->admin())
            ->test(FormsScreen::class, ['customer' => $fixture['customer']]);

        $screen->assertSee('Send form link')->assertSee('Not sent');

        $screen->call('sendFormLink', $fixture['enrollment']->getKey(), $fixture['template']->getKey())
            ->assertHasNoErrors()
            // The confirmation is rendered INSIDE the component: the layout's
            // flash is never re-rendered by a Livewire update, so a success
            // shown only there would be invisible until the next page load.
            ->assertSee('Form link sent to '.$fixture['contact']->email)
            ->assertSee('Resend form link')
            ->assertSee('Revoke link')
            ->assertSee('Active')
            ->assertSee($fixture['contact']->email);
    }

    #[Test]
    public function a_business_with_no_published_form_is_offered_nothing_to_send(): void
    {
        $customer = Customer::factory()->create(['name' => 'Gamma Works', 'code' => 'C-GAMMA']);
        CustomerContact::factory()->create(['customer_id' => $customer->getKey(), 'is_primary' => true]);

        Livewire::actingAs($this->admin())
            ->test(FormsScreen::class, ['customer' => $customer])
            ->assertDontSee('Send form link')
            ->assertSee('Nothing to send yet');
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * A business, a contact with an email, an enrolment, a published form and a
     * draft submission bound to it - the state the workflow starts from.
     *
     * @return array<string, mixed>
     */
    private function fixture(
        string $name = 'Alpha Metalworks',
        string $code = 'C-ALPHA',
        bool $withContact = true,
    ): array {
        $actor = $this->admin();

        $customer = Customer::factory()->create(['name' => $name, 'code' => $code]);

        $contact = $withContact
            ? CustomerContact::factory()->create([
                'customer_id' => $customer->getKey(),
                'is_primary' => true,
                'email' => strtolower(str_replace('-', '', $code)).'@example.test',
            ])
            : null;

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
        $question = $builder->addQuestion($version, $section, QuestionType::Text, 'What does the business make?', 0, ['is_required' => true]);

        app(FormPublishingService::class)->publish($version, $actor);

        // NO draft is created here, deliberately. The business creates the
        // submission by opening the link, which is what makes the answers
        // theirs: source = external_grant, created_by = NULL.
        return [
            'customer' => $customer,
            'contact' => $contact,
            'enrollment' => $enrollment,
            'template' => $template,
            'version' => $version->fresh(),
            'question' => $question,
        ];
    }

    /**
     * The plaintext token out of the most recently sent link email.
     *
     * It exists nowhere else by design, so the email is the only place to read
     * it - which is itself the guarantee being relied on.
     */
    private function tokenFromSentMail(): string
    {
        $token = null;

        Mail::assertSent(FormLinkMessage::class, function (FormLinkMessage $mail) use (&$token): bool {
            $token = $this->tokenFrom($mail->url);

            return true;
        });

        $this->assertNotNull($token);

        return $token;
    }

    private function tokenFrom(string $url): string
    {
        return (string) basename(parse_url($url, PHP_URL_PATH) ?: '');
    }

    private function refreshApplicationWithUrl(string $url): void
    {
        URL::forceRootUrl($url);
    }
}
