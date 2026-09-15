<div>
    <x-ui.page-header :title="$customer ? 'Edit customer' : 'New customer'"
                      :subtitle="$customer ? $customer->name : 'A confirmed customer. Choose their batch and they are active in the programme straight away.'"
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

            {{-- Programme entry, in one step. Choosing a batch here makes the
                 business an active participant on save: there is no payment
                 confirmation and no separate enrolment action to follow. --}}
            @if (! $customer && $batches->isNotEmpty())
                <x-ui.field label="Batch" for="batchId"
                            hint="The business becomes an active participant in this batch as soon as you save. You can leave it unset and assign a batch later."
                            :error="$errors->first('batchId')">
                    <x-ui.select id="batchId" wire:model="batchId">
                        <option value="">Assign a batch later</option>
                        @foreach ($batches as $batch)
                            <option value="{{ $batch->id }}">
                                {{ $batch->program?->name ? $batch->program->name.' — ' : '' }}{{ $batch->name }}
                                @if ($batch->starts_on)
                                    ({{ $batch->starts_on->format('d M Y') }})
                                @endif
                            </option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            @endif

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

    <p class="mt-4 max-w-xl text-xs text-ink-3">
        Status changes are not made here. A customer becomes active or archived through the domain
        service, which records who did it and when.
    </p>
</div>
