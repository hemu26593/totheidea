<x-ui.workspace :customer="$customer" :tabs="$tabs" current="business"
                subtitle="The org chart and the HR policy library — Sessions 4 and 6.">
    @error('domain')
        <x-ui.alert tone="danger" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    <div class="grid gap-5 xl:grid-cols-2">
        <x-ui.card title="Positions" subtitle="Who reports to whom in this business." :padding="false">
            <x-slot:actions>
                @can('create', App\Models\Position::class)
                    <x-ui.button size="sm" wire:click="startPosition">Add position</x-ui.button>
                @endcan
            </x-slot:actions>

            <x-ui.table :headings="['Position', 'Holder', 'Reports to', '>']" class="rounded-none shadow-none ring-0">
                @forelse ($positions as $position)
                    <tr wire:key="position-{{ $position->id }}">
                        <x-ui.td>
                            <p class="font-medium">{{ $position->title }}</p>
                            @if ($position->kra)
                                <p class="mt-0.5 text-xs text-slate-500">{{ Str::limit($position->kra, 90) }}</p>
                            @endif
                        </x-ui.td>
                        <x-ui.td muted>{{ $position->holder_name ?? 'Vacant' }}</x-ui.td>
                        <x-ui.td muted>{{ $position->parent?->title ?? '—' }}</x-ui.td>
                        <x-ui.td align="right">
                            @can('archive', $position)
                                <x-ui.button size="sm" variant="ghost"
                                             wire:click="archivePosition({{ $position->id }})"
                                             wire:confirm="Archive this position?">Archive</x-ui.button>
                            @endcan
                        </x-ui.td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="4" title="No positions defined"
                                    description="The org chart is where HR policy acknowledgements attach." />
                @endforelse
            </x-ui.table>
        </x-ui.card>

        <x-ui.card title="HR policies" subtitle="Draft, publish, supersede. Published content is immutable."
                   :padding="false">
            <x-slot:actions>
                @can('create', App\Models\HrPolicy::class)
                    <x-ui.button size="sm" wire:click="startPolicy">Draft policy</x-ui.button>
                @endcan
            </x-slot:actions>

            <div class="divide-y divide-slate-100">
                @forelse ($policies as $policy)
                    <div class="px-4 py-3" wire:key="policy-{{ $policy->id }}">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-medium text-slate-900">{{ $policy->title }}</p>
                                <p class="text-xs text-slate-500">
                                    {{ $policy->version_label ?? 'No version label' }}
                                    @if ($policy->published_at)
                                        · published {{ $policy->published_at->format('d M Y') }}
                                    @endif
                                    · {{ $policy->acknowledgements->count() }} acknowledgement(s)
                                </p>
                            </div>

                            <div class="flex shrink-0 items-center gap-1.5">
                                <x-ui.status-badge :status="$policy->status" />

                                @if ($policy->isDraft())
                                    @can('publish', $policy)
                                        <x-ui.button size="sm" variant="primary"
                                                     wire:click="publishPolicy({{ $policy->id }})"
                                                     wire:confirm="Publish this policy? Its content becomes immutable.">
                                            Publish
                                        </x-ui.button>
                                    @endcan
                                @endif

                                @if ($policy->isAcknowledgeable())
                                    @can('create', App\Models\HrPolicyAcknowledgement::class)
                                        <x-ui.button size="sm"
                                                     wire:click="startAcknowledgement({{ $policy->id }})">
                                            Record acknowledgement
                                        </x-ui.button>
                                    @endcan
                                @endif

                                @unless ($policy->status === 'archived')
                                    @can('archive', $policy)
                                        <x-ui.button size="sm" variant="ghost"
                                                     wire:click="archivePolicy({{ $policy->id }})"
                                                     wire:confirm="Archive this policy?">Archive</x-ui.button>
                                    @endcan
                                @endunless
                            </div>
                        </div>

                        @if ($policy->acknowledgements->isNotEmpty())
                            <ul class="mt-2 flex flex-wrap gap-1.5">
                                @foreach ($policy->acknowledgements as $ack)
                                    <li class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600">
                                        {{ $ack->acknowledged_name }}
                                        <span class="text-slate-400">· {{ $ack->position?->title }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @empty
                    <x-ui.empty title="No policies yet"
                                description="Staff may draft a policy; publishing it is an administrator's act." />
                @endforelse
            </div>
        </x-ui.card>
    </div>

    <x-ui.modal :show="$addingPosition" title="Add a position" close="$set('addingPosition', false)">
        <form wire:submit="addPosition" class="space-y-4">
            <x-ui.field label="Title" required :error="$errors->first('positionTitle')">
                <x-ui.input wire:model="positionTitle" />
            </x-ui.field>

            <x-ui.field label="Reports to" hint="Leave blank for a top-level position."
                        :error="$errors->first('parentPositionId')">
                <x-ui.select wire:model="parentPositionId">
                    <option value="">—</option>
                    @foreach ($allPositions as $position)
                        <option value="{{ $position->id }}">{{ $position->title }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

            <x-ui.field label="Holder" hint="The person's name. They do not get an account."
                        :error="$errors->first('holderName')">
                <x-ui.input wire:model="holderName" />
            </x-ui.field>

            <x-ui.field label="Key result areas" :error="$errors->first('kra')">
                <x-ui.textarea wire:model="kra" rows="3" />
            </x-ui.field>

            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button type="button" wire:click="$set('addingPosition', false)">Cancel</x-ui.button>
                <x-ui.button variant="primary" type="submit">Add position</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal :show="$draftingPolicy" title="Draft a policy" close="$set('draftingPolicy', false)">
        <form wire:submit="draftPolicy" class="space-y-4">
            <x-ui.field label="Title" required :error="$errors->first('policyTitle')">
                <x-ui.input wire:model="policyTitle" />
            </x-ui.field>

            <x-ui.field label="Version label" hint="e.g. v1, 2026-A" :error="$errors->first('policyVersionLabel')">
                <x-ui.input wire:model="policyVersionLabel" />
            </x-ui.field>

            <x-ui.field label="Body" :error="$errors->first('policyBody')">
                <x-ui.textarea wire:model="policyBody" rows="8" />
            </x-ui.field>

            <x-ui.alert tone="info">
                A draft can be revised freely. Once published, the content is immutable — a change means
                superseding it with a new policy, so what people acknowledged stays readable.
            </x-ui.alert>

            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button type="button" wire:click="$set('draftingPolicy', false)">Cancel</x-ui.button>
                <x-ui.button variant="primary" type="submit">Save draft</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal :show="$acknowledgingPolicyId !== null" title="Record an acknowledgement"
                close="$set('acknowledgingPolicyId', null)">
        <form wire:submit="recordAcknowledgement" class="space-y-4">
            <p class="text-sm text-slate-600">
                Acknowledgements attach to a position, not to a user account — the people signing off are
                the business's own staff.
            </p>

            <x-ui.field label="Position" required :error="$errors->first('acknowledgingPositionId')">
                <x-ui.select wire:model="acknowledgingPositionId">
                    <option value="">Choose a position…</option>
                    @foreach ($allPositions as $position)
                        <option value="{{ $position->id }}">
                            {{ $position->title }}{{ $position->holder_name ? ' — '.$position->holder_name : '' }}
                        </option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

            <x-ui.field label="Name acknowledged" required
                        hint="The name as given at the time. It stays on the record."
                        :error="$errors->first('acknowledgedName')">
                <x-ui.input wire:model="acknowledgedName" />
            </x-ui.field>

            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button type="button" wire:click="$set('acknowledgingPolicyId', null)">Cancel</x-ui.button>
                <x-ui.button variant="primary" type="submit">Record</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</x-ui.workspace>
