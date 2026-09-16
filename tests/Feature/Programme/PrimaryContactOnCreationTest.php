<?php

declare(strict_types=1);

namespace Tests\Feature\Programme;

use App\Domain\Customers\CustomerContactService;
use App\Domain\Forms\FormBuilderService;
use App\Domain\Forms\FormPublishingService;
use App\Domain\Notifications\RecipientResolver;
use App\Enums\QuestionType;
use App\Livewire\Customers\Forms as FormsScreen;
use App\Livewire\Customers\ManageCustomer;
use App\Mail\FormLinkMessage;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Enrollment;
use App\Models\FormTemplate;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The person we deal with, captured when the business is.
 *
 * A form link goes to a business's primary contact, so a customer created
 * without one could not be sent anything until somebody reopened the record.
 * The contact is now entered alongside the business and the batch, and all of
 * it lands in one transaction.
 *
 * There is no second primary-contact mechanism here: the contact is created
 * through CustomerContactService with its existing primary flag, which is what
 * RecipientResolver and FormLinkService already read.
 */
class PrimaryContactOnCreationTest extends TestCase
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
    | A. One save produces business, contact and batch
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function one_save_creates_the_customer_its_primary_contact_and_the_batch_assignment(): void
    {
        $batch = $this->batch();

        Livewire::actingAs($this->admin())
            ->test(ManageCustomer::class)
            ->set('name', 'Alpha Metalworks')
            ->set('code', 'C-ALPHA')
            ->set('contactName', 'Rakesh Patel')
            ->set('contactEmail', 'rakesh@alpha.test')
            ->set('contactPhone', '+919876543210')
            ->set('batchId', $batch->getKey())
            ->call('save')
            ->assertHasNoErrors();

        $customer = Customer::query()->where('code', 'C-ALPHA')->sole();
        $contact = CustomerContact::query()->where('customer_id', $customer->getKey())->sole();

        // The business.
        $this->assertSame('active', $customer->status);

        // The person, in the Contact model's own field names.
        $this->assertSame('Rakesh Patel', $contact->name);
        $this->assertSame('rakesh@alpha.test', $contact->email);
        $this->assertSame('+919876543210', $contact->phone_e164);
        $this->assertTrue($contact->is_primary);
        $this->assertNull($contact->archived_at);

        // The batch, through the enrolment that was never a step.
        $enrollment = Enrollment::query()->where('customer_id', $customer->getKey())->sole();
        $this->assertSame((int) $batch->getKey(), (int) $enrollment->batch_id);
        $this->assertTrue($enrollment->isActive());
    }

    #[Test]
    public function the_contact_is_the_one_the_existing_primary_mechanism_returns(): void
    {
        $customer = $this->createdCustomer();

        // Customer::primaryContact() and RecipientResolver are what the rest of
        // the application asks. Neither was taught anything new.
        $this->assertSame('rakesh@alpha.test', $customer->primaryContact()?->email);

        $resolved = app(RecipientResolver::class)->primaryContactFor((int) $customer->getKey());

        $this->assertNotNull($resolved);
        $this->assertSame('rakesh@alpha.test', $resolved->email);
        $this->assertTrue($resolved->is_primary);
    }

    #[Test]
    public function a_form_link_reaches_the_contact_entered_at_creation_with_no_further_setup(): void
    {
        // The friction this removes: create, then straight to Forms and send.
        // Nothing reopens the customer to add a contact in between.
        $customer = $this->createdCustomer();
        $template = $this->publishedTemplate();

        Livewire::actingAs($this->staff())
            ->test(FormsScreen::class, ['customer' => $customer])
            ->call(
                'sendFormLink',
                $customer->enrollments()->sole()->getKey(),
                $template->getKey(),
            )
            ->assertHasNoErrors();

        Mail::assertSent(
            FormLinkMessage::class,
            fn (FormLinkMessage $mail): bool => $mail->hasTo('rakesh@alpha.test'),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | B. All of it, or none of it
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_refused_batch_assignment_leaves_no_customer_and_no_orphaned_contact(): void
    {
        // The batch fills up between opening the form and saving it. The
        // contact is written before the enrolment, so this is the ordering
        // that would strand one if the transaction were not doing its job.
        $batch = $this->batch(['capacity' => 1]);

        Enrollment::factory()->create([
            'customer_id' => Customer::factory()->create(['code' => 'C-SEAT'])->getKey(),
            'batch_id' => $batch->getKey(),
        ]);

        Livewire::actingAs($this->admin())
            ->test(ManageCustomer::class)
            ->set('name', 'Epsilon Tools')
            ->set('code', 'C-EPSILON')
            ->set('contactName', 'Meera Joshi')
            ->set('contactEmail', 'meera@epsilon.test')
            ->set('batchId', $batch->getKey())
            ->call('save')
            ->assertHasErrors('domain');

        $this->assertNull(Customer::query()->where('code', 'C-EPSILON')->first());
        $this->assertSame(
            0,
            CustomerContact::query()->where('email', 'meera@epsilon.test')->count(),
            'A rolled-back creation must not leave the contact behind.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | C. Validation
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_contact_block_can_be_left_empty(): void
    {
        // Still a valid way to work: the business is known before the person
        // is. The customer's own screen adds the contact later.
        Livewire::actingAs($this->admin())
            ->test(ManageCustomer::class)
            ->set('name', 'Delta Plastics')
            ->set('code', 'C-DELTA')
            ->set('batchId', $this->batch()->getKey())
            ->call('save')
            ->assertHasNoErrors();

        $customer = Customer::query()->where('code', 'C-DELTA')->sole();

        $this->assertSame(0, $customer->contacts()->count());
        $this->assertSame(1, $customer->enrollments()->count());
    }

    #[Test]
    public function a_half_entered_contact_is_refused_rather_than_saved_unreachable(): void
    {
        // A contact with no address reaches nobody - RecipientResolver skips
        // it - so a name on its own would silently defeat the point of the
        // block. Both directions are refused.
        Livewire::actingAs($this->admin())
            ->test(ManageCustomer::class)
            ->set('name', 'Gamma Foods')
            ->set('code', 'C-GAMMA')
            ->set('contactName', 'Anita Rao')
            ->call('save')
            ->assertHasErrors(['contactEmail']);

        Livewire::actingAs($this->admin())
            ->test(ManageCustomer::class)
            ->set('name', 'Gamma Foods')
            ->set('code', 'C-GAMMA')
            ->set('contactEmail', 'anita@gamma.test')
            ->call('save')
            ->assertHasErrors(['contactName']);

        // A phone alone is not a contact either.
        Livewire::actingAs($this->admin())
            ->test(ManageCustomer::class)
            ->set('name', 'Gamma Foods')
            ->set('code', 'C-GAMMA')
            ->set('contactPhone', '+919876543210')
            ->call('save')
            ->assertHasErrors(['contactName', 'contactEmail']);

        $this->assertNull(Customer::query()->where('code', 'C-GAMMA')->first());
    }

    #[Test]
    public function the_email_must_be_an_email(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ManageCustomer::class)
            ->set('name', 'Zeta Works')
            ->set('code', 'C-ZETA')
            ->set('contactName', 'Sunil Mehta')
            ->set('contactEmail', 'not-an-address')
            ->call('save')
            ->assertHasErrors(['contactEmail' => 'email']);

        $this->assertNull(Customer::query()->where('code', 'C-ZETA')->first());
    }

    /*
    |--------------------------------------------------------------------------
    | D. Permissions are unchanged
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function writing_a_contact_needs_the_ability_contacts_have_always_needed(): void
    {
        // CustomerContactPolicy::create asks for customers.edit, and it still
        // does. Someone who may add a business but not edit one cannot smuggle
        // a contact in through this form.
        $limited = User::factory()->create();
        $limited->givePermissionTo('customers.create', 'customers.view');

        $this->assertTrue($limited->can('create', Customer::class));
        $this->assertFalse($limited->can('create', CustomerContact::class));

        Livewire::actingAs($limited)
            ->test(ManageCustomer::class)
            ->set('name', 'Theta Engineering')
            ->set('code', 'C-THETA')
            ->set('contactName', 'Priya Shah')
            ->set('contactEmail', 'priya@theta.test')
            ->call('save')
            ->assertForbidden();

        $this->assertNull(Customer::query()->where('code', 'C-THETA')->first());
        $this->assertSame(0, CustomerContact::query()->where('email', 'priya@theta.test')->count());
    }

    /*
    |--------------------------------------------------------------------------
    | E. Nothing existing was disturbed
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function editing_a_customer_neither_offers_nor_creates_a_contact(): void
    {
        // The fields are for capturing the FIRST contact. Editing a business
        // must not quietly add another one on every save.
        $customer = $this->createdCustomer();

        Livewire::actingAs($this->admin())
            ->test(ManageCustomer::class, ['customer' => $customer])
            ->set('name', 'Alpha Metalworks Pvt Ltd')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, $customer->contacts()->count());
        $this->assertSame('Alpha Metalworks Pvt Ltd', $customer->fresh()->name);

        $this->actingAs($this->admin())
            ->get(route('customers.edit', $customer))
            ->assertOk()
            ->assertDontSee('Primary contact');
    }

    #[Test]
    public function the_existing_contact_screen_still_owns_contact_management(): void
    {
        // Adding a second contact and promoting it goes through the same
        // service as always, and demotes the one created at sign-up. One
        // primary mechanism, not two.
        $customer = $this->createdCustomer();
        $first = $customer->contacts()->sole();

        $second = app(CustomerContactService::class)->create(
            $customer,
            ['name' => 'Second Person', 'email' => 'second@alpha.test'],
            $this->admin(),
            primary: true,
        );

        $this->assertTrue($second->fresh()->is_primary);
        $this->assertFalse($first->fresh()->is_primary, 'Promoting a contact must demote the earlier primary.');
        $this->assertSame('second@alpha.test', $customer->fresh()->primaryContact()?->email);
    }

    #[Test]
    public function a_customer_recorded_before_this_change_is_left_alone(): void
    {
        $legacy = Customer::factory()->create(['code' => 'C-OLD']);
        $contact = CustomerContact::factory()->create([
            'customer_id' => $legacy->getKey(),
            'email' => 'old@legacy.test',
        ]);

        $before = $contact->fresh()->toArray();

        Livewire::actingAs($this->admin())
            ->test(ManageCustomer::class)
            ->set('name', 'Brand New Ltd')
            ->set('code', 'C-NEW')
            ->set('contactName', 'New Person')
            ->set('contactEmail', 'new@brandnew.test')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($before, $contact->fresh()->toArray(), 'An unrelated contact must be untouched.');
        $this->assertSame(1, $legacy->fresh()->contacts()->count());
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
     * A business brought in the new way: one save, with its contact and batch.
     */
    private function createdCustomer(): Customer
    {
        Livewire::actingAs($this->admin())
            ->test(ManageCustomer::class)
            ->set('name', 'Alpha Metalworks')
            ->set('code', 'C-ALPHA')
            ->set('contactName', 'Rakesh Patel')
            ->set('contactEmail', 'rakesh@alpha.test')
            ->set('contactPhone', '+919876543210')
            ->set('batchId', $this->batch()->getKey())
            ->call('save')
            ->assertHasNoErrors();

        return Customer::query()->where('code', 'C-ALPHA')->sole();
    }

    private function publishedTemplate(): FormTemplate
    {
        $builder = app(FormBuilderService::class);

        $template = $builder->createTemplate([
            'customer_id' => null,
            'key' => 'intake_alpha',
            'name' => 'Business Intake',
            'is_scored' => false,
        ]);

        $version = $builder->createDraftVersion($template);
        $section = $builder->addSection($version, 'About the business', 0);
        $builder->addQuestion($version, $section, QuestionType::Text, 'What does the business make?', 0, ['is_required' => true]);

        app(FormPublishingService::class)->publish($version, $this->admin());

        return $template;
    }
}
