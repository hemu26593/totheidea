<div>
    <x-ui.page-header :title="$customer ? 'Edit customer' : 'New customer'"
                      :subtitle="$customer ? $customer->name : 'A business in the programme. Customers do not have accounts and never sign in.'"
                      :breadcrumbs="array_filter([
                          'Customers' => route('customers.index'),
                          ($customer?->name ?? 'New') => $customer ? route('customers.show', $customer) : null,
                      ])" />

    @error('domain')
        <x-ui.alert tone="danger" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    <x-ui.card class="max-w-xl">
        <form wire:submit="save" class="space-y-4">
            <x-ui.field label="Business name" for="name" required :error="$errors->first('name')">
                <x-ui.input id="name" wire:model="name" autocomplete="organization" />
            </x-ui.field>

            <x-ui.field label="Customer code" for="code" required
                        hint="Short, unique, and used on reports and exports."
                        :error="$errors->first('code')">
                <x-ui.input id="code" wire:model="code" class="font-mono" />
            </x-ui.field>

            <div class="flex items-center gap-2 pt-2">
                <x-ui.button variant="primary" type="submit">
                    {{ $customer ? 'Save changes' : 'Create customer' }}
                </x-ui.button>

                <x-ui.button :href="$customer ? route('customers.show', $customer) : route('customers.index')">
                    Cancel
                </x-ui.button>

                <x-ui.loading target="save" label="Saving…" />
            </div>
        </form>
    </x-ui.card>

    <p class="mt-4 max-w-xl text-xs text-slate-500">
        Status changes are not made here. A customer becomes active or archived through the domain
        service, which records who did it and when.
    </p>
</div>
