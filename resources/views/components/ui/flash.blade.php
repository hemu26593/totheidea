{{-- Session feedback, rendered once per page rather than per component. --}}
@if (session('status'))
    <x-ui.alert tone="success" class="mb-4">{{ session('status') }}</x-ui.alert>
@endif

@if (session('warning'))
    <x-ui.alert tone="warning" class="mb-4">{{ session('warning') }}</x-ui.alert>
@endif
