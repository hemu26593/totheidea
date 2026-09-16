@props([
    'customer',
    'tabs' => [],
    'current' => 'overview',
    'title' => null,
    'subtitle' => null,
])

{{-- The customer workspace chrome: identity, status, and secondary nav. --}}
<div>
    <x-ui.page-header :title="$title ?? $customer->name"
                      :subtitle="$subtitle"
                      :breadcrumbs="['Customers' => route('customers.index'), $customer->name => route('customers.show', $customer)]">
        <x-slot:actions>
            <x-ui.status-badge :status="$customer->status" />
            <span class="rounded bg-elevated px-2 py-1 font-mono text-xs text-gold ring-1 ring-inset ring-gold-line">{{ $customer->code }}</span>
            {{ $actions ?? '' }}
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.tabs :tabs="$tabs" :current="$current" class="mb-5" />

    {{ $slot }}
</div>
