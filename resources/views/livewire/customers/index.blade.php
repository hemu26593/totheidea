<div>
    <x-ui.page-header title="Customers"
                      subtitle="Businesses in the programme. A customer is not a user and never signs in.">
        <x-slot:actions>
            @can('create', App\Models\Customer::class)
                <x-ui.button variant="primary" :href="route('customers.create')">New customer</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @error('domain')
        <x-ui.alert tone="danger" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    <x-ui.filters class="mb-4">
        <x-ui.search placeholder="Search name or code" />

        <x-ui.select wire:model.live="status" class="w-auto">
            <option value="all">Active directory</option>
            @foreach ($statuses as $value)
                <option value="{{ $value }}">{{ Str::headline($value) }}</option>
            @endforeach
        </x-ui.select>

        <x-ui.loading target="search,status" />
    </x-ui.filters>

    <x-ui.table :headings="['Customer', 'Code', 'Status', 'Primary contact', 'Enrolments', '>Actions']">
        @forelse ($customers as $customer)
            <tr wire:key="customer-{{ $customer->id }}" class="hover:bg-slate-50">
                <x-ui.td>
                    <a href="{{ route('customers.show', $customer) }}" class="font-medium text-slate-900 hover:underline">
                        {{ $customer->name }}
                    </a>
                </x-ui.td>
                <x-ui.td muted><span class="font-mono text-xs">{{ $customer->code }}</span></x-ui.td>
                <x-ui.td><x-ui.status-badge :status="$customer->status" /></x-ui.td>
                <x-ui.td muted>{{ $customer->primaryContact()?->name ?? '—' }}</x-ui.td>
                <x-ui.td muted class="tabular-nums">{{ $customer->active_enrollments_count }}</x-ui.td>
                <x-ui.td align="right">
                    <div class="flex justify-end gap-1.5">
                        @can('update', $customer)
                            <x-ui.button size="sm" :href="route('customers.edit', $customer)">Edit</x-ui.button>
                        @endcan

                        @if ($customer->isArchived())
                            @can('update', $customer)
                                <x-ui.button size="sm" wire:click="activate({{ $customer->id }})">Reactivate</x-ui.button>
                            @endcan
                        @else
                            @can('archive', $customer)
                                {{-- Archive, never delete: customer removal is archival (ADR-011). --}}
                                <x-ui.button size="sm" variant="ghost"
                                             wire:click="archive({{ $customer->id }})"
                                             wire:confirm="Archive {{ $customer->name }}? The record stays, and everything attached to it stays with it.">
                                    Archive
                                </x-ui.button>
                            @endcan
                        @endif
                    </div>
                </x-ui.td>
            </tr>
        @empty
            <x-ui.empty-row :colspan="6"
                            title="No customers match"
                            description="Adjust the search or filter, or create the first customer." />
        @endforelse
    </x-ui.table>

    <div class="mt-4">{{ $customers->links() }}</div>
</div>
