<div>
    <x-ui.page-header title="Roles &amp; permissions"
                      subtitle="Read-only. config/authorization.php is the source of truth." />

    <x-ui.alert tone="info" class="mb-5" title="Why this screen does not edit">
        Roles are explicit allowlists in version control, so a change is code-reviewed and diffable.
        Editing them from here would move that decision out of review and let a permission added later
        reach a role nobody examined it for.
    </x-ui.alert>

    <x-ui.card title="Super Admin" class="mb-5">
        <p class="text-sm text-slate-700">
            Super Admin holds no stored grants. It is granted by computation through
            <code class="rounded bg-slate-100 px-1 py-0.5 text-xs">Gate::before</code>, so a permission
            added later is covered without a reseed.
        </p>

        <p class="mt-3 text-sm text-slate-700">
            These abilities are <strong>not</strong> auto-granted and fall through to their policies —
            they carry self-protection semantics that exist precisely to constrain a Super Admin:
        </p>

        <div class="mt-2 flex flex-wrap gap-1.5">
            @foreach ($guardedAbilities as $ability)
                <x-ui.badge tone="warning"><span class="font-mono">{{ $ability }}</span></x-ui.badge>
            @endforeach
        </div>
    </x-ui.card>

    @foreach ($groups as $group => $permissions)
        <x-ui.card :title="$group" class="mb-4" :padding="false" wire:key="group-{{ $loop->index }}">
            <x-ui.table :headings="array_merge(['Permission'], array_map(fn ($r) => $r->label(), $roles))"
                        class="rounded-none shadow-none ring-0">
                @foreach ($permissions as $permission)
                    <tr wire:key="permission-{{ $permission }}">
                        <x-ui.td><span class="font-mono text-xs">{{ $permission }}</span></x-ui.td>

                        @foreach ($roles as $role)
                            <x-ui.td>
                                @if ($role === App\Enums\UserRole::SuperAdmin)
                                    <x-ui.badge tone="success">Via gate</x-ui.badge>
                                @elseif (in_array($permission, $granted[$role->value] ?? [], true))
                                    <x-ui.badge tone="success">Granted</x-ui.badge>
                                @else
                                    <span class="text-xs text-slate-300">—</span>
                                @endif
                            </x-ui.td>
                        @endforeach
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endforeach
</div>
