<x-ui.workspace :customer="$customer" :tabs="$tabs" current="overview">
    <x-slot:actions>
        @can('update', $customer)
            <x-ui.button size="sm" :href="route('customers.edit', $customer)">Edit</x-ui.button>
        @endcan

        @if ($customer->isArchived())
            @can('update', $customer)
                <x-ui.button size="sm" wire:click="activateCustomer">Reactivate</x-ui.button>
            @endcan
        @else
            @can('archive', $customer)
                <x-ui.button size="sm" variant="ghost" wire:click="confirmArchive">Archive</x-ui.button>
            @endcan
        @endif
    </x-slot:actions>

    @error('domain')
        <x-ui.alert tone="danger" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    <div class="grid gap-5 lg:grid-cols-3">
        <x-ui.card title="Overview" class="lg:col-span-1">
            <dl class="divide-y divide-line">
                <x-ui.definition term="Business">{{ $customer->name }}</x-ui.definition>
                <x-ui.definition term="Code"><span class="font-mono">{{ $customer->code }}</span></x-ui.definition>
                <x-ui.definition term="Status"><x-ui.status-badge :status="$customer->status" /></x-ui.definition>
                <x-ui.definition term="Created">{{ $customer->created_at?->format('d M Y') }}</x-ui.definition>

                @if ($customer->isArchived())
                    <x-ui.definition term="Archived">
                        {{ $customer->archived_at?->format('d M Y') }}
                        by {{ $customer->archivedBy?->name ?? 'unknown' }}
                    </x-ui.definition>
                @endif
            </dl>

            <p class="mt-3 border-t border-line pt-3 text-xs text-ink-3">
                A customer is a business, not an account. External participation happens through
                scoped, expiring access links — never a login.
            </p>
        </x-ui.card>

        <x-ui.card title="Contacts"
                   subtitle="Who we actually talk to. Notification consent lives here."
                   class="lg:col-span-2"
                   :padding="false">
            <x-slot:actions>
                @can('create', App\Models\CustomerContact::class)
                    <x-ui.button size="sm" wire:click="startContact">Add contact</x-ui.button>
                @endcan
            </x-slot:actions>

            <x-ui.table :headings="['Name', 'Role', 'Email', 'Phone', '>Actions']" class="rounded-none shadow-none ring-0">
                @forelse ($contacts as $contact)
                    <tr wire:key="contact-{{ $contact->id }}">
                        <x-ui.td>
                            <span class="font-medium">{{ $contact->name }}</span>
                            @if ($contact->is_primary)
                                <x-ui.badge tone="info" class="ml-1.5">Primary</x-ui.badge>
                            @endif
                        </x-ui.td>
                        <x-ui.td muted>{{ $contact->role_title ?? '—' }}</x-ui.td>
                        <x-ui.td muted>{{ $contact->email ?? '—' }}</x-ui.td>
                        <x-ui.td muted>{{ $contact->phone_e164 ?? '—' }}</x-ui.td>
                        <x-ui.td align="right">
                            <div class="flex justify-end gap-1.5">
                                @if (! $contact->is_primary)
                                    @can('update', $contact)
                                        <x-ui.button size="sm" wire:click="makePrimary({{ $contact->id }})">
                                            Make primary
                                        </x-ui.button>
                                    @endcan
                                @endif

                                @can('archive', $contact)
                                    <x-ui.button size="sm" variant="ghost"
                                                 wire:click="archiveContact({{ $contact->id }})"
                                                 wire:confirm="Archive this contact?">Archive</x-ui.button>
                                @endcan
                            </div>
                        </x-ui.td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="5" title="No contacts yet"
                                    description="Reminders need somewhere to go. Add the person we deal with." />
                @endforelse
            </x-ui.table>
        </x-ui.card>
    </div>

    <x-ui.card title="Enrolments" subtitle="Programme runs this business is part of." class="mt-5" :padding="false">
        <x-slot:actions>
            <x-ui.button size="sm" :href="route('customers.enrollments', $customer)">Manage</x-ui.button>
        </x-slot:actions>

        <x-ui.table :headings="['Programme', 'Batch', 'Status', 'Enrolled', '>']" class="rounded-none shadow-none ring-0">
            @forelse ($enrollments as $enrollment)
                <tr wire:key="enrollment-{{ $enrollment->id }}">
                    <x-ui.td class="font-medium">{{ $enrollment->batch?->program?->name ?? '—' }}</x-ui.td>
                    <x-ui.td muted>{{ $enrollment->batch?->name }}</x-ui.td>
                    <x-ui.td><x-ui.status-badge :status="$enrollment->status" /></x-ui.td>
                    <x-ui.td muted>{{ $enrollment->enrolled_at?->format('d M Y') }}</x-ui.td>
                    <x-ui.td align="right">
                        <x-ui.button size="sm" :href="route('customers.enrollments', $customer)">Open</x-ui.button>
                    </x-ui.td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="5" title="Not enrolled yet"
                                description="Enrol this business in a batch to start the programme." />
            @endforelse
        </x-ui.table>
    </x-ui.card>

    {{-- Contact modal --}}
    <x-ui.modal :show="$addingContact" title="Add contact" close="$set('addingContact', false)">
        <form wire:submit="saveContact" class="space-y-4">
            <x-ui.field label="Name" required :error="$errors->first('contactName')">
                <x-ui.input wire:model="contactName" />
            </x-ui.field>

            <x-ui.field label="Role" :error="$errors->first('contactRole')">
                <x-ui.input wire:model="contactRole" placeholder="Owner, Manager…" />
            </x-ui.field>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Email" :error="$errors->first('contactEmail')">
                    <x-ui.input type="email" wire:model="contactEmail" />
                </x-ui.field>

                <x-ui.field label="Phone" :error="$errors->first('contactPhone')">
                    <x-ui.input wire:model="contactPhone" placeholder="+91…" />
                </x-ui.field>
            </div>

            <label class="flex items-center gap-2 text-sm text-ink-2">
                <input type="checkbox" wire:model="contactPrimary"
                       class="rounded border-line text-ink focus:ring-gold">
                Make this the primary contact
            </label>

            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button wire:click="$set('addingContact', false)" type="button">Cancel</x-ui.button>
                <x-ui.button variant="primary" type="submit">Add contact</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    {{-- Archive confirmation. There is no delete: removal is archival. --}}
    <x-ui.modal :show="$confirmingArchive" title="Archive customer" close="$set('confirmingArchive', false)">
        <p class="text-sm text-ink-2">
            Archiving hides <span class="font-medium text-ink">{{ $customer->name }}</span> from the
            active directory. Nothing is deleted — sessions, submissions, attendance and reports all stay
            exactly as they are, and the record can be reactivated.
        </p>

        <x-slot:footer>
            <x-ui.button wire:click="$set('confirmingArchive', false)">Cancel</x-ui.button>
            <x-ui.button variant="danger" wire:click="archiveCustomer">Archive customer</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</x-ui.workspace>
