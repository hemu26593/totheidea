@props([
    'status',
    'label' => null,
])

{{--
    One place that maps a domain status string to a colour, so the same status
    never reads green on one screen and amber on another. The vocabulary is the
    domain's; nothing here invents a status.
--}}
@php
    $value = $status instanceof BackedEnum ? $status->value : (string) $status;

    $tone = match ($value) {
        'active', 'present', 'approved', 'accepted', 'published', 'completed', 'done', 'sent', 'succeeded'
            => 'success',
        'prospect', 'planned', 'scheduled', 'draft', 'not_started', 'enrolled', 'open', 'pending'
            => 'neutral',
        'in_progress', 'released', 'submitted', 'awaiting_approval', 'late', 'carried_forward'
            => 'warning',
        'absent', 'failed', 'rejected', 'returned', 'overdue', 'cancelled', 'withdrawn'
            => 'danger',
        'excused', 'archived', 'superseded', 'closed', 'dropped', 'suppressed'
            => 'info',
        default => 'neutral',
    };
@endphp

<x-ui.badge :tone="$tone" {{ $attributes }}>
    {{ $label ?? Str::headline($value) }}
</x-ui.badge>
