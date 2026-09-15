@props(['colspan' => 1, 'title' => 'Nothing here yet', 'description' => null])

<tr>
    <td colspan="{{ $colspan }}" class="px-4 py-10 text-center">
        <p class="text-sm font-medium text-ink-2">{{ $title }}</p>

        @if ($description)
            <p class="mx-auto mt-1 max-w-md text-sm text-ink-3">{{ $description }}</p>
        @endif
    </td>
</tr>
