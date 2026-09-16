<?php

declare(strict_types=1);

namespace Tests\Feature\Programme;

use App\Domain\Forms\FormBuilderService;
use App\Domain\Forms\FormPublishingService;
use App\Enums\ActorSource;
use App\Enums\QuestionType;
use App\Livewire\Customers\Forms as FormsScreen;
use App\Livewire\Customers\ManageCustomer;
use App\Mail\FormLinkMessage;
use App\Models\AccessGrant;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Enrollment;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\Program;
use App\Models\User;
use App\Services\CustomerDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The confirmed-customer rule: Customer -> Batch, and nothing in between.
 *
 * Every customer entered here is already a final, confirmed customer, so
 * choosing a batch IS programme entry. There is no payment confirmation to
 * clear and no second "enrol" action to remember.
 *
 * Enrolment survives underneath because twelve tables carry a NOT NULL
 * enrollment_id and resolve customer isolation through it. These tests pin
 * the rule at the seam that matters: the operator performs ONE action, and
 * the ownership row the rest of the system needs is already there.
 */
class DirectBatchAssignmentTest extends TestCase
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
    | A. One action puts a confirmed customer into a batch
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function creating_a_customer_with_a_batch_makes_them_active_in_it_immediately(): void
    {
        $batch = $this->batch();

        Livewire::actingAs($this->admin())
            ->test(ManageCustomer::class)
            ->set('name', 'Alpha Metalworks')
            ->set('code', 'C-ALPHA')
            ->set('batchId', $batch->getKey())
            ->call('save')
            ->assertHasNoErrors();

        $customer = Customer::query()->where('code', 'C-ALPHA')->sole();
        $enrollment = Enrollment::query()->where('customer_id', $customer->getKey())->sole();

        $this->assertSame((int) $batch->getKey(), (int) $enrollment->batch_id);
        $this->assertTrue($enrollment->isActive(), 'The customer must be active the moment they are saved.');
        $this->assertNotNull($enrollment->enrolled_at);
        $this->assertNull($enrollment->withdrawn_at);
        $this->assertNull($enrollment->completed_at);
    }

    #[Test]
    public function no_second_enrolment_action_is_needed_anywhere_in_the_flow(): void
    {
        // The Enrolments/Programme screen is never touched in this test. If
        // activation still depended on it, the assertions below would fail.
        $batch = $this->batch();

        Livewire::actingAs($this->admin())
            ->test(ManageCustomer::class)
            ->set('name', 'Beta Textiles')
            ->set('code', 'C-BETA')
            ->set('batchId', $batch->getKey())
            ->call('save')
            ->assertHasNoErrors();

        $customer = Customer::query()->where('code', 'C-BETA')->sole();

        // This is the exact resolution every programme screen performs.
        $resolved = $customer->enrollments()->where('status', 'enrolled')->value('id');

        $this->assertNotNull($resolved, 'Programme screens resolve an active enrolment with no extra step.');
    }

    #[Test]
    public function payment_is_never_a_condition_of_taking_part(): void
    {
        $batch = $this->batch();

        Livewire::actingAs($this->admin())
            ->test(ManageCustomer::class)
            ->set('name', 'Gamma Foods')
            ->set('code', 'C-GAMMA')
            ->set('batchId', $batch->getKey())
            ->call('save')
            ->assertHasNoErrors();

        $enrollment = Enrollment::query()->sole();

        // No payment date was supplied, and the customer is active regardless.
        $this->assertNull($enrollment->payment_due_date);
        $this->assertTrue($enrollment->isActive());

        // And the creation screen neither collects nor mentions payment:
        // it has no place in programme entry.
        $properties = array_map(
            fn (\ReflectionProperty $p): string => $p->getName(),
            (new \ReflectionClass(ManageCustomer::class))->getProperties(\ReflectionProperty::IS_PUBLIC),
        );

        $this->assertNotContains('paymentDueDate', $properties);

        $this->actingAs($this->admin())
            ->get(route('customers.create'))
            ->assertOk()
            ->assertDontSee('Payment')
            ->assertDontSee('payment');
    }

    #[Test]
    public function a_new_customer_is_already_confirmed_rather_than_a_prospect(): void
    {
        // The rule the client confirmed: everybody entered here is a final
        // customer. A prospect stage on the way in would be a second
        // activation step wearing a different name.
        Livewire::actingAs($this->admin())
            ->test(ManageCustomer::class)
            ->set('name', 'Theta Engineering')
            ->set('code', 'C-THETA')
            ->set('batchId', $this->batch()->getKey())
            ->call('save')
            ->assertHasNoErrors();

        $customer = Customer::query()->where('code', 'C-THETA')->sole();

        $this->assertSame('active', $customer->status);
        $this->assertNull($customer->archived_at);

        $this->actingAs($this->admin())
            ->get(route('customers.show', $customer))
            ->assertOk()
            ->assertDontSee('Prospect');
    }

    #[Test]
    public function a_customer_archived_before_this_rule_is_left_exactly_as_it_was(): void
    {
        // Existing rows are not rewritten by the new rule. A prospect recorded
        // under the old workflow stays a prospect, and stays listable.
        $legacy = Customer::factory()->create(['code' => 'C-OLD', 'status' => 'prospect']);

        $this->assertSame('prospect', $legacy->fresh()->status);
        $this->assertContains('prospect', CustomerDirectory::STATUSES);

        $this->actingAs($this->admin())
            ->get(route('customers.show', $legacy))
            ->assertOk();
    }

    #[Test]
    public function a_batch_is_optional_so_a_customer_can_still_be_recorded_first(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ManageCustomer::class)
            ->set('name', 'Delta Plastics')
            ->set('code', 'C-DELTA')
            ->call('save')
            ->assertHasNoErrors();

        $customer = Customer::query()->where('code', 'C-DELTA')->sole();

        $this->assertSame(0, $customer->enrollments()->count());
    }

    #[Test]
    public function the_customer_and_the_assignment_are_written_atomically(): void
    {
        // A full batch is the readable domain refusal. The point is that the
        // customer does NOT survive it: a customer saved without the batch
        // they were meant to be in is the half-finished state this whole
        // change exists to remove.
        $batch = $this->batch(['capacity' => 1]);

        Enrollment::factory()->create([
            'customer_id' => Customer::factory()->create(['code' => 'C-SEAT'])->getKey(),
            'batch_id' => $batch->getKey(),
        ]);

        Livewire::actingAs($this->admin())
            ->test(ManageCustomer::class)
            ->set('name', 'Epsilon Tools')
            ->set('code', 'C-EPSILON')
            ->set('batchId', $batch->getKey())
            ->call('save')
            ->assertHasErrors('domain');

        $this->assertNull(
            Customer::query()->where('code', 'C-EPSILON')->first(),
            'A refused batch assignment must take the customer with it.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | B. Permissions are unchanged
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function creating_a_customer_remains_an_admin_act(): void
    {
        $this->actingAs($this->staff())->get(route('customers.create'))->assertForbidden();
        $this->actingAs($this->admin())->get(route('customers.create'))->assertOk();
    }

    #[Test]
    public function assigning_a_batch_requires_the_same_ability_it_always_did(): void
    {
        $batch = $this->batch();

        // customers.create without batches.edit: the customer is theirs to
        // add, the batch assignment is not. The screen refuses rather than
        // silently creating a customer with no programme.
        $limited = User::factory()->create();
        $limited->givePermissionTo('customers.create', 'customers.view');

        $this->assertTrue($limited->can('create', Customer::class));
        $this->assertFalse($limited->can('create', Enrollment::class));

        Livewire::actingAs($limited)
            ->test(ManageCustomer::class)
            ->set('name', 'Zeta Works')
            ->set('code', 'C-ZETA')
            ->set('batchId', $batch->getKey())
            ->call('save')
            ->assertForbidden();

        $this->assertNull(Customer::query()->where('code', 'C-ZETA')->first());
    }

    #[Test]
    public function a_user_who_cannot_assign_batches_is_not_offered_any(): void
    {
        $this->batch();

        $limited = User::factory()->create();
        $limited->givePermissionTo('customers.create', 'customers.view');

        Livewire::actingAs($limited)
            ->test(ManageCustomer::class)
            ->assertViewHas('batches', fn ($batches) => $batches->isEmpty());
    }

    /*
    |--------------------------------------------------------------------------
    | C. Programme features recognise a directly-assigned customer
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_forms_screen_offers_a_published_form_to_a_directly_assigned_customer(): void
    {
        ['customer' => $customer, 'template' => $template] = $this->assignedCustomerWithForm();

        Livewire::actingAs($this->staff())
            ->test(FormsScreen::class, ['customer' => $customer])
            ->assertOk()
            ->assertSee($template->name);
    }

    #[Test]
    public function every_programme_screen_loads_for_a_directly_assigned_customer(): void
    {
        ['customer' => $customer] = $this->assignedCustomerWithForm();

        $routes = [
            'customers.show', 'customers.enrollments', 'customers.forms', 'customers.sessions',
            'customers.assignments', 'customers.attendance', 'customers.day-plan',
            'customers.time-grid', 'customers.mmd', 'customers.fund-plan', 'customers.action-plan',
            'customers.business', 'customers.documents', 'customers.notes', 'customers.reports',
        ];

        foreach ($routes as $route) {
            $this->actingAs($this->admin())
                ->get(route($route, $customer))
                ->assertOk();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | D. External form links still work, and are still safe
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_external_link_flow_works_end_to_end_from_a_direct_assignment(): void
    {
        $fixture = $this->assignedCustomerWithForm();

        Livewire::actingAs($this->staff())
            ->test(FormsScreen::class, ['customer' => $fixture['customer']])
            ->call('sendFormLink', $fixture['enrollment']->getKey(), $fixture['template']->getKey())
            ->assertHasNoErrors();

        $token = $this->tokenFromSentMail();

        $this->get(route('external.forms.show', ['token' => $token]))
            ->assertOk()
            ->assertSee($fixture['customer']->name);

        $this->post(route('external.forms.store', ['token' => $token]), [
            'action' => 'submit',
            'answers' => [$fixture['question']->getKey() => 'Sheet-metal enclosures'],
        ])->assertOk();

        $submission = FormSubmission::query()->sole();

        $this->assertSame('submitted', $submission->status);
        $this->assertSame(ActorSource::ExternalGrant->value, $submission->source->value ?? $submission->source);
        $this->assertNull($submission->created_by, 'An external submission has no internal author.');

        // The submission binds to the enrolment the batch assignment created.
        $this->assertSame(
            (int) $fixture['enrollment']->getKey(),
            (int) $submission->enrollment_id,
        );
    }

    #[Test]
    public function the_external_link_remains_single_use(): void
    {
        $token = $this->sentTokenFor($fixture = $this->assignedCustomerWithForm());

        $this->post(route('external.forms.store', ['token' => $token]), [
            'action' => 'submit',
            'answers' => [$fixture['question']->getKey() => 'First and only'],
        ])->assertOk();

        $this->get(route('external.forms.show', ['token' => $token]))->assertNotFound();
    }

    #[Test]
    public function the_link_still_expires_after_fourteen_days(): void
    {
        $this->assertSame(14, (int) config('access.link_expiry_days'));

        $this->sentTokenFor($this->assignedCustomerWithForm());

        $grant = AccessGrant::query()->latest('id')->sole();

        $this->assertSame(
            14,
            (int) $grant->created_at->startOfSecond()->diffInDays($grant->expires_at->startOfSecond()),
        );
    }

    #[Test]
    public function the_token_is_never_stored_in_the_clear(): void
    {
        $token = $this->sentTokenFor($this->assignedCustomerWithForm());

        $grant = AccessGrant::query()->latest('id')->sole();

        $this->assertNotSame($token, $grant->token_hash);
        $this->assertSame(hash('sha256', $token), $grant->token_hash);

        foreach ($grant->getAttributes() as $column => $value) {
            $this->assertNotSame($token, $value, "The plaintext token leaked into {$column}.");
        }
    }

    #[Test]
    public function one_customers_link_never_shows_another_customers_form(): void
    {
        $alpha = $this->assignedCustomerWithForm('Alpha Metalworks', 'C-ALPHA');
        $beta = $this->assignedCustomerWithForm('Beta Textiles', 'C-BETA');

        $alphaToken = $this->sentTokenFor($alpha);

        $this->get(route('external.forms.show', ['token' => $alphaToken]))
            ->assertOk()
            ->assertSee($alpha['customer']->name)
            ->assertDontSee($beta['customer']->name);
    }

    /*
    |--------------------------------------------------------------------------
    | E. Existing data is untouched
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_enrolment_made_the_old_way_remains_valid_and_active(): void
    {
        // A row created before this change - through EnrollmentService, with a
        // payment date set - must keep working exactly as it did.
        $customer = Customer::factory()->create(['code' => 'C-LEGACY']);
        $batch = $this->batch();

        $enrollment = Enrollment::factory()->create([
            'customer_id' => $customer->getKey(),
            'batch_id' => $batch->getKey(),
            'payment_due_date' => now()->addDays(30)->toDateString(),
        ]);

        $this->assertTrue($enrollment->fresh()->isActive());
        $this->assertNotNull($enrollment->fresh()->payment_due_date);

        $this->actingAs($this->admin())
            ->get(route('customers.enrollments', $customer))
            ->assertOk()
            ->assertSee($batch->name);
    }

    #[Test]
    public function editing_a_customer_never_touches_their_batch(): void
    {
        ['customer' => $customer, 'enrollment' => $enrollment] = $this->assignedCustomerWithForm();

        Livewire::actingAs($this->admin())
            ->test(ManageCustomer::class, ['customer' => $customer])
            ->set('name', 'Alpha Metalworks Pvt Ltd')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, $customer->enrollments()->count());
        $this->assertSame(
            (int) $enrollment->batch_id,
            (int) $customer->enrollments()->sole()->batch_id,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function batch(array $attributes = []): Batch
    {
        return Batch::factory()->create([
            'program_id' => Program::factory()->create(['session_count' => 6])->getKey(),
            ...$attributes,
        ]);
    }

    /**
     * A customer put into a batch the new way - through the Add Customer
     * screen, in one action - with a published form waiting for them.
     *
     * @return array{customer: Customer, enrollment: Enrollment, template: FormTemplate, question: mixed}
     */
    private function assignedCustomerWithForm(string $name = 'Alpha Metalworks', string $code = 'C-ALPHA'): array
    {
        $batch = $this->batch();

        Livewire::actingAs($this->admin())
            ->test(ManageCustomer::class)
            ->set('name', $name)
            ->set('code', $code)
            ->set('batchId', $batch->getKey())
            ->call('save')
            ->assertHasNoErrors();

        $customer = Customer::query()->where('code', $code)->sole();

        CustomerContact::factory()->create([
            'customer_id' => $customer->getKey(),
            'is_primary' => true,
            'email' => strtolower(str_replace('-', '', $code)).'@example.test',
        ]);

        $builder = app(FormBuilderService::class);

        $template = $builder->createTemplate([
            'customer_id' => null,
            'key' => 'intake_'.strtolower(str_replace('-', '_', $code)),
            'name' => 'Business Intake '.$code,
            'is_scored' => false,
        ]);

        $version = $builder->createDraftVersion($template);
        $section = $builder->addSection($version, 'About the business', 0);
        $question = $builder->addQuestion(
            $version,
            $section,
            QuestionType::Text,
            'What does the business make?',
            0,
            ['is_required' => true],
        );

        app(FormPublishingService::class)->publish($version, $this->admin());

        return [
            'customer' => $customer,
            'enrollment' => $customer->enrollments()->sole(),
            'template' => $template,
            'question' => $question,
        ];
    }

    /**
     * @param  array{customer: Customer, enrollment: Enrollment, template: FormTemplate, question: mixed}  $fixture
     */
    private function sentTokenFor(array $fixture): string
    {
        Livewire::actingAs($this->staff())
            ->test(FormsScreen::class, ['customer' => $fixture['customer']])
            ->call('sendFormLink', $fixture['enrollment']->getKey(), $fixture['template']->getKey())
            ->assertHasNoErrors();

        return $this->tokenFromSentMail();
    }

    private function tokenFromSentMail(): string
    {
        $token = null;

        Mail::assertSent(FormLinkMessage::class, function ($mail) use (&$token): bool {
            if (preg_match('#/external/forms/([A-Za-z0-9_-]+)#', $mail->url, $m) === 1) {
                $token = $m[1];
            }

            return true;
        });

        $this->assertNotNull($token, 'No external form link was sent.');

        return $token;
    }
}
