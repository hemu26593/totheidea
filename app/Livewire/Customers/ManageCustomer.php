<?php

declare(strict_types=1);

namespace App\Livewire\Customers;

use App\Domain\Customers\CustomerService;
use App\Domain\Customers\ProgrammeEntry;
use App\Domain\Forms\ParticipantQuestionnaire;
use App\Livewire\Concerns\ReportsDomainFailures;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Enrollment;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Creating and editing a customer.
 *
 * Only name and code are editable, because only those two are fillable on the
 * model. Status is not a form field: it moves through
 * CustomerService::activate and ::archive, which carry the audit entries.
 *
 * Batch is chosen on creation, and is required. Every customer here is already
 * a confirmed customer in a batch, so this screen is the whole of programme
 * entry: no payment, no activation, no separate enrol action, and no second
 * screen that finishes the job. The enrolment row it produces is an internal
 * ownership record - twelve tables resolve customer isolation through it -
 * never a workflow the operator has to drive.
 *
 * Batch is not an edit field. Moving a business between batches ends one
 * programme run and starts another, which is a different operation from
 * correcting a name, and no screen offers it.
 *
 * The contact fields are likewise creation-only, and exist to capture the ONE
 * person a new business is reached through - the primary contact a form link
 * goes to. Managing contacts after that (adding more, changing which is
 * primary, archiving, consent) stays on the customer's own screen, which
 * already does all of it; nothing here duplicates that.
 *
 * SAVING ALSO HANDS OVER THE QUESTIONNAIRE LINK. The domain does the work -
 * CustomerService enters the business and ParticipantQuestionnaire issues the
 * link - and this screen's part is to show the URL once, because the plaintext
 * token cannot be recovered afterwards (ADR-018). It is flashed, so it lives
 * for exactly the redirect that follows and is then gone.
 */
class ManageCustomer extends Component
{
    use ReportsDomainFailures;

    #[Locked]
    public ?int $customerId = null;

    public string $name = '';

    public string $code = '';

    public ?int $batchId = null;

    public string $contactName = '';

    public string $contactEmail = '';

    public string $contactPhone = '';

    public function mount(?Customer $customer = null): void
    {
        if ($customer?->exists) {
            $this->authorize('update', $customer);

            $this->customerId = (int) $customer->getKey();
            $this->name = (string) $customer->name;
            $this->code = (string) $customer->code;

            return;
        }

        $this->authorize('create', Customer::class);
    }

    public function save(CustomerService $customers): void
    {
        $existing = $this->existing();

        // Re-authorized here, not only in mount(): a public Livewire method is
        // an HTTP endpoint whatever the rendered page offers.
        $existing === null
            ? $this->authorize('create', Customer::class)
            : $this->authorize('update', $existing);

        // A batch and a contact are only ever written while creating, and each
        // writes a second table, so each is authorized in its own right. This
        // method is reachable over HTTP whatever the form rendered.
        if ($existing === null && $this->batchId !== null) {
            $this->authorize('create', Enrollment::class);
        }

        if ($existing === null && $this->hasContactDetails()) {
            $this->authorize('create', CustomerContact::class);
        }

        $rules = [
            'name' => ['required', 'string', 'max:200'],
            'code' => [
                'required', 'string', 'max:30',
                Rule::unique('customers', 'code')->ignore($this->customerId),
            ],
        ];

        if ($existing === null) {
            // All three are required because a customer reaching this system is
            // already confirmed and already in a batch: that IS what being a
            // customer here means. There is no later step that would supply a
            // missing batch - no enrolment screen exists - so accepting one
            // without it would create a record nothing can finish.
            $rules['batchId'] = ['required', 'integer', Rule::exists('batches', 'id')->whereNull('archived_at')];

            // The same contact rules the customer's own screen uses. An address
            // is required here rather than optional because the point of the
            // block is somebody a form link can reach, and RecipientResolver
            // skips a contact that has none.
            $rules['contactName'] = ['required', 'string', 'max:150'];
            $rules['contactEmail'] = ['required', 'email', 'max:255'];
            $rules['contactPhone'] = ['nullable', 'string', 'max:20'];
        }

        $data = $this->validate($rules);
        $batch = $this->chosenBatch($existing);
        $entry = null;

        $saved = $this->runGuarded(function () use ($customers, $existing, $data, $batch, &$entry): void {
            if ($existing !== null) {
                $customers->update($existing, ['name' => $data['name'], 'code' => $data['code']], auth()->user());

                return;
            }

            $entry = $customers->createInBatch(
                ['name' => $data['name'], 'code' => $data['code']],
                $batch,
                $this->contactAttributes(),
                auth()->user(),
            );

            $this->customerId = (int) $entry->customer->getKey();
        }, $this->outcomeMessage($existing, $batch));

        if (! $saved) {
            return;
        }

        if ($entry !== null) {
            $this->reportQuestionnaireOutcome($entry);
        }

        $this->redirectRoute('customers.show', $this->customerId, navigate: false);
    }

    public function render(): View
    {
        $existing = $this->existing();

        return view('livewire.customers.manage-customer', [
            'customer' => $existing,
            'batches' => $existing === null ? $this->assignableBatches() : new Collection,
        ])->layout('components.layouts.app', [
            'title' => $existing === null ? 'New customer' : 'Edit customer',
        ]);
    }

    /**
     * Say what happened to the questionnaire link, including when nothing did.
     *
     * The URL is flashed, which means it lives in the session for exactly one
     * render of the customer's screen and is then gone - the same lifetime the
     * plaintext token has in memory. It is never written to a row, a log or a
     * redirect of its own.
     */
    private function reportQuestionnaireOutcome(ProgrammeEntry $entry): void
    {
        if ($entry->questionnaireProblem !== null) {
            session()->flash(
                'warning',
                'The customer was created, but the questionnaire link could not be issued: '
                .$entry->questionnaireProblem,
            );

            return;
        }

        $questionnaire = app(ParticipantQuestionnaire::class);
        $link = $entry->questionnaireLink;

        if ($link === null) {
            // Nothing was issued because there is nothing to issue. Worth
            // saying: an operator who expects a link should know why there
            // isn't one, rather than assume the screen forgot.
            if ($entry->enrollment !== null && ! $questionnaire->isAvailable()) {
                session()->flash(
                    'warning',
                    'No participant questionnaire is published yet, so no form link was created. '
                    .'Once it is published, send the link from this business\'s Forms tab.',
                );
            }

            return;
        }

        session()->flash('issued_link', [
            'url' => $link->url,
            'form' => (string) ($questionnaire->template()?->name ?? 'Questionnaire'),
            'contact' => (string) ($link->contact?->email ?? ''),
            'expires_at' => $link->expiresAt()?->format('d M Y'),
            'delivered' => $link->delivered,
        ]);
    }

    private function existing(): ?Customer
    {
        return $this->customerId === null
            ? null
            : Customer::query()->findOrFail($this->customerId);
    }

    /**
     * Batches a new customer can be put into. Archived ones are excluded
     * because assigning into one is not a programme anybody is running.
     *
     * @return Collection<int, Batch>
     */
    private function assignableBatches(): Collection
    {
        if (! auth()->user()?->can('create', Enrollment::class)) {
            return new Collection;
        }

        return Batch::query()
            ->with('program:id,name')
            ->whereNull('archived_at')
            ->orderByDesc('starts_on')
            ->get();
    }

    private function hasContactDetails(): bool
    {
        return trim($this->contactName) !== ''
            || trim($this->contactEmail) !== ''
            || trim($this->contactPhone) !== '';
    }

    /**
     * The contact to create alongside the customer, in the Contact model's own
     * field names. Null when the operator left the block empty.
     *
     * @return array{name: string, email: string|null, phone_e164: string|null}|null
     */
    private function contactAttributes(): ?array
    {
        if (! $this->hasContactDetails()) {
            return null;
        }

        return [
            'name' => trim($this->contactName),
            'email' => trim($this->contactEmail) ?: null,
            'phone_e164' => trim($this->contactPhone) ?: null,
        ];
    }

    private function chosenBatch(?Customer $existing): ?Batch
    {
        if ($existing !== null || $this->batchId === null) {
            return null;
        }

        return Batch::query()->findOrFail($this->batchId);
    }

    private function outcomeMessage(?Customer $existing, ?Batch $batch): string
    {
        if ($existing !== null) {
            return 'Customer updated.';
        }

        return $batch === null
            ? 'Customer created.'
            : 'Customer created and active in '.$batch->name.'.';
    }
}
