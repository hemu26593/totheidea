<?php

declare(strict_types=1);

namespace App\Livewire\Customers;

use App\Domain\Customers\CustomerContactService;
use App\Domain\Customers\CustomerService;
use App\Livewire\Concerns\AuthorizesCustomerWorkspace;
use App\Livewire\Concerns\ReportsDomainFailures;
use App\Models\Customer;
use App\Models\CustomerContact;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The customer workspace overview: who this business is, and what is
 * outstanding.
 *
 * ARCHIVE, NEVER DELETE. There is no delete action on this page and no
 * permission that would allow one - customer removal is archival (ADR-011).
 */
class Show extends Component
{
    use AuthorizesCustomerWorkspace;
    use ReportsDomainFailures;

    public bool $addingContact = false;

    public string $contactName = '';

    public string $contactRole = '';

    public string $contactEmail = '';

    public string $contactPhone = '';

    public bool $contactPrimary = false;

    public bool $confirmingArchive = false;

    public function mount(Customer $customer): void
    {
        $this->mountCustomer($customer);
    }

    public function startContact(): void
    {
        $this->authorize('create', CustomerContact::class);

        $this->reset(['contactName', 'contactRole', 'contactEmail', 'contactPhone', 'contactPrimary']);
        $this->addingContact = true;
    }

    public function saveContact(CustomerContactService $contacts): void
    {
        $this->authorize('create', CustomerContact::class);

        $data = $this->validate([
            'contactName' => ['required', 'string', 'max:150'],
            'contactRole' => ['nullable', 'string', 'max:100'],
            'contactEmail' => ['nullable', 'email', 'max:255'],
            'contactPhone' => ['nullable', 'string', 'max:20'],
        ]);

        $saved = $this->runGuarded(function () use ($contacts, $data): void {
            $contacts->create(
                $this->customer(),
                [
                    'name' => $data['contactName'],
                    'role_title' => $data['contactRole'] ?: null,
                    'email' => $data['contactEmail'] ?: null,
                    'phone_e164' => $data['contactPhone'] ?: null,
                ],
                auth()->user(),
                $this->contactPrimary,
            );
        }, 'Contact added.');

        if ($saved) {
            $this->addingContact = false;
        }
    }

    public function makePrimary(int $contactId, CustomerContactService $contacts): void
    {
        $contact = $this->contactInWorkspace($contactId);

        $this->authorize('update', $contact);

        $this->runGuarded(
            fn () => $contacts->makePrimary($contact, auth()->user()),
            'Primary contact updated.',
        );
    }

    public function archiveContact(int $contactId, CustomerContactService $contacts): void
    {
        $contact = $this->contactInWorkspace($contactId);

        $this->authorize('archive', $contact);

        $this->runGuarded(
            fn () => $contacts->archive($contact, auth()->user()),
            'Contact archived.',
        );
    }

    public function confirmArchive(): void
    {
        $this->authorize('archive', $this->customer());

        $this->confirmingArchive = true;
    }

    public function archiveCustomer(CustomerService $customers): void
    {
        $customer = $this->customer();

        $this->authorize('archive', $customer);

        $this->runGuarded(
            fn () => $customers->archive($customer, auth()->user()),
            'Customer archived.',
        );

        $this->confirmingArchive = false;
    }

    public function activateCustomer(CustomerService $customers): void
    {
        $customer = $this->customer();

        $this->authorize('update', $customer);

        $this->runGuarded(
            fn () => $customers->activate($customer, auth()->user()),
            'Customer activated.',
        );
    }

    public function render(): View
    {
        $customer = $this->customer();

        return view('livewire.customers.show', [
            'customer' => $customer,
            'tabs' => $this->workspaceTabs($customer),
            'contacts' => $customer->contacts()->whereNull('archived_at')->orderByDesc('is_primary')->orderBy('name')->get(),
            'enrollments' => $customer->enrollments()->with('batch.program')->latest('enrolled_at')->get(),
        ])->layout('components.layouts.app', ['title' => $customer->name]);
    }

    /**
     * A contact id arriving from the browser is verified against this
     * workspace before anything is done with it.
     */
    private function contactInWorkspace(int $contactId): CustomerContact
    {
        $contact = CustomerContact::query()->findOrFail($contactId);

        $this->assertOwnedByWorkspace((int) $contact->customer_id);

        return $contact;
    }
}
