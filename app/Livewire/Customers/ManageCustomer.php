<?php

declare(strict_types=1);

namespace App\Livewire\Customers;

use App\Domain\Customers\CustomerService;
use App\Livewire\Concerns\ReportsDomainFailures;
use App\Models\Customer;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Creating and editing a customer.
 *
 * Only name and code are editable, because only those two are fillable on the
 * model. Status is not a form field: it moves through
 * CustomerService::activate and ::archive, which carry the audit entries.
 */
class ManageCustomer extends Component
{
    use ReportsDomainFailures;

    #[Locked]
    public ?int $customerId = null;

    public string $name = '';

    public string $code = '';

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

        $data = $this->validate([
            'name' => ['required', 'string', 'max:200'],
            'code' => [
                'required', 'string', 'max:30',
                Rule::unique('customers', 'code')->ignore($this->customerId),
            ],
        ]);

        $saved = $this->runGuarded(function () use ($customers, $existing, $data): void {
            $existing === null
                ? $this->customerId = (int) $customers->create($data, auth()->user())->getKey()
                : $customers->update($existing, $data, auth()->user());
        }, $existing === null ? 'Customer created.' : 'Customer updated.');

        if ($saved) {
            $this->redirectRoute('customers.show', $this->customerId, navigate: false);
        }
    }

    public function render(): View
    {
        $existing = $this->existing();

        return view('livewire.customers.manage-customer', [
            'customer' => $existing,
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
}
