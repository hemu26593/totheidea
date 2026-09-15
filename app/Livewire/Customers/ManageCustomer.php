<?php

declare(strict_types=1);

namespace App\Livewire\Customers;

use App\Domain\Customers\CustomerService;
use App\Livewire\Concerns\ReportsDomainFailures;
use App\Models\Batch;
use App\Models\Customer;
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
 * Batch is offered on creation only. Every customer here is already a
 * confirmed customer, so choosing a batch is the whole of programme entry -
 * it activates them, with no payment step and no separate enrol action. The
 * enrolment row this produces is an internal ownership record, never a
 * workflow the operator has to drive.
 *
 * Changing which batch an existing customer sits in is a different operation
 * with its own history - withdrawing one run and starting another - so it
 * stays on the Programme screen and is deliberately not an edit field here.
 */
class ManageCustomer extends Component
{
    use ReportsDomainFailures;

    #[Locked]
    public ?int $customerId = null;

    public string $name = '';

    public string $code = '';

    public ?int $batchId = null;

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

        // A batch is only ever assigned while creating. Authorized separately
        // because it writes a second table, and this method is reachable over
        // HTTP whatever the form rendered.
        if ($existing === null && $this->batchId !== null) {
            $this->authorize('create', Enrollment::class);
        }

        $rules = [
            'name' => ['required', 'string', 'max:200'],
            'code' => [
                'required', 'string', 'max:30',
                Rule::unique('customers', 'code')->ignore($this->customerId),
            ],
        ];

        if ($existing === null) {
            $rules['batchId'] = ['nullable', 'integer', Rule::exists('batches', 'id')->whereNull('archived_at')];
        }

        $data = $this->validate($rules);
        $batch = $this->chosenBatch($existing);

        $saved = $this->runGuarded(function () use ($customers, $existing, $data, $batch): void {
            $existing === null
                ? $this->customerId = (int) $customers->createInBatch(
                    ['name' => $data['name'], 'code' => $data['code']],
                    $batch,
                    auth()->user(),
                )->getKey()
                : $customers->update($existing, $data, auth()->user());
        }, $this->outcomeMessage($existing, $batch));

        if ($saved) {
            $this->redirectRoute('customers.show', $this->customerId, navigate: false);
        }
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
