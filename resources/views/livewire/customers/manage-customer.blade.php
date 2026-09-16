<div>
    <x-ui.page-header :title="$customer ? 'Edit customer' : 'New customer'"
                      :subtitle="$customer ? $customer->name : 'A confirmed customer. Enter who we deal with and choose their batch, and they are ready for the programme on save.'"
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
                    <x-ui.field label="Contact name" for="contactName"
                                :error="$errors->first('contactName')">
                        <x-ui.input id="contactName" wire:model="contactName" autocomplete="name" />
                    </x-ui.field>

                    <x-ui.field label="Email" for="contactEmail"
                                hint="Where the form link is sent."
                                :error="$errors->first('contactEmail')">
                        <x-ui.input id="contactEmail" type="email" wire:model="contactEmail" autocomplete="email" />
                    </x-ui.field>

                    <x-ui.field label="Phone" for="contactPhone"
                                hint="Optional. In E.164 form, such as +919876543210."
                                :error="$errors->first('contactPhone')">
                        <x-ui.input id="contactPhone" wire:model="contactPhone" autocomplete="tel" />
                    </x-ui.field>

                    <p class="text-xs text-ink-3">
                        You can leave this empty and add the contact later, but a form link cannot be
                        sent until the business has one with an email address.
                    </p>
                </div>
            </x-ui.card>
        @endif

        {{-- Programme entry, in one step. Choosing a batch here makes the
             business an active participant on save: there is no payment
             confirmation and no separate enrolment action to follow. --}}
        @if (! $customer && $batches->isNotEmpty())
            <x-ui.card title="Programme">
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
