<div>
    <x-ui.page-header :title="$customer ? 'Edit customer' : 'New customer'"
                      :subtitle="$customer ? $customer->name : 'A confirmed customer. Enter who we deal with and which batch they are in — they are ready for the programme on save.'"
                      :breadcrumbs="array_filter([
                          'Customers' => route('customers.index'),
                          ($customer?->name ?? 'New') => $customer ? route('customers.show', $customer) : null,
                      ])" />

    @error('domain')
        <x-ui.alert tone="danger" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    <form wire:submit="save" class="max-w-xl space-y-4">
        <x-ui.card :title="$customer ? null : 'Customer details'">
            <div class="space-y-4">
                <x-ui.field label="Business name" for="name" required :error="$errors->first('name')">
                    <x-ui.input id="name" wire:model="name" autocomplete="organization" />
                </x-ui.field>

                <x-ui.field label="Customer code" for="code" required
                            hint="Short, unique, and used on reports and exports."
                            :error="$errors->first('code')">
                    <x-ui.input id="code" wire:model="code" class="font-mono" />
                </x-ui.field>
            </div>
        </x-ui.card>

        {{-- Creation only. This captures the one person a new business is
             reached through, so a form link can be sent without anybody
             reopening the record. Adding further contacts, changing which is
             primary, consent and archiving all stay on the customer's own
             screen, which already does them. --}}
        @if (! $customer)
            <x-ui.card title="Primary contact"
                       subtitle="Who we deal with. Form links and reminders go to this person.">
                <div class="space-y-4">
                    <x-ui.field label="Contact name" for="contactName" required
                                :error="$errors->first('contactName')">
                        <x-ui.input id="contactName" wire:model="contactName" autocomplete="name" />
                    </x-ui.field>

                    <x-ui.field label="Email" for="contactEmail" required
                                hint="Where the form link is sent."
                                :error="$errors->first('contactEmail')">
                        <x-ui.input id="contactEmail" type="email" wire:model="contactEmail" autocomplete="email" />
                    </x-ui.field>

                    <x-ui.field label="Phone" for="contactPhone"
                                hint="Optional. In E.164 form, such as +919876543210."
                                :error="$errors->first('contactPhone')">
                        <x-ui.input id="contactPhone" wire:model="contactPhone" autocomplete="tel" />
                    </x-ui.field>

                </div>
            </x-ui.card>
        @endif

        {{-- Programme entry, in one step. The batch is required: a customer
             here is already in one, and nothing downstream would supply a
             missing batch later. --}}
        @if (! $customer)
            <x-ui.card title="Programme">
                @if ($batches->isEmpty())
                    <x-ui.alert tone="warning">
                        There is no batch available to put this business into. Create one on the
                        Batches screen first, then add the customer.
                    </x-ui.alert>
                @else
                    <x-ui.field label="Batch" for="batchId" required
                                hint="The business is an active participant in this batch as soon as you save."
                                :error="$errors->first('batchId')">
                        <x-ui.select id="batchId" wire:model="batchId">
                            <option value="">Choose a batch…</option>
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
            </x-ui.card>
        @endif

        <div class="flex items-center gap-2">
            <x-ui.button variant="primary" type="submit">
                {{ $customer ? 'Save changes' : 'Create customer' }}
            </x-ui.button>

            <x-ui.button :href="$customer ? route('customers.show', $customer) : route('customers.index')">
                Cancel
            </x-ui.button>

            <x-ui.loading target="save" label="Saving…" />
        </div>
    </form>

    <p class="mt-4 max-w-xl text-xs text-ink-3">
        Status changes are not made here. A customer becomes active or archived through the domain
        service, which records who did it and when.
    </p>
</div>
