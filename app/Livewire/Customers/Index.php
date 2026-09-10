<?php

declare(strict_types=1);

namespace App\Livewire\Customers;

use App\Domain\Customers\CustomerService;
use App\Livewire\Concerns\ReportsDomainFailures;
use App\Models\Customer;
use App\Services\CustomerDirectory;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The customer directory.
 *
 * NO DELETE ACTION EXISTS HERE, and that is structural rather than an
 * omission: customer removal is archival (ADR-011), there is no
 * customers.delete permission to hold, and CustomerPolicy::delete returns
 * false. Archiving is offered instead.
 */
class Index extends Component
{
    use ReportsDomainFailures;
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = 'all';

    public function mount(): void
    {
        $this->authorize('viewAny', Customer::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function archive(int $customerId): void
    {
        $customer = Customer::query()->findOrFail($customerId);

        $this->authorize('archive', $customer);

        $this->runGuarded(
            fn () => app(CustomerService::class)->archive($customer, auth()->user()),
            'Customer archived.',
        );
    }

    public function activate(int $customerId): void
    {
        $customer = Customer::query()->findOrFail($customerId);

        $this->authorize('update', $customer);

        $this->runGuarded(
            fn () => app(CustomerService::class)->activate($customer, auth()->user()),
            'Customer activated.',
        );
    }

    public function render(): View
    {
        return view('livewire.customers.index', [
            'customers' => $this->results(),
            'statuses' => CustomerDirectory::STATUSES,
        ])->layout('components.layouts.app', ['title' => 'Customers']);
    }

    /**
     * @return LengthAwarePaginator<int, Customer>
     */
    private function results(): LengthAwarePaginator
    {
        return app(CustomerDirectory::class)
            ->query($this->search, $this->status)
            ->withCount([
                'enrollments as active_enrollments_count' => fn ($q) => $q->where('status', 'enrolled'),
            ])
            ->with('contacts')
            ->paginate(20);
    }
}
