{{--
    A BMP reminder.

    Plain, short, and carrying no business data by design - see
    App\Domain\Notifications\NotificationContent. Written with Laravel's
    markdown mail components so it needs no build step and renders as both HTML
    and text.
--}}
<x-mail::message>
# {{ config('app.name') }}

@if ($businessName)
This message concerns **{{ $businessName }}**.
@endif

{{ $line }}

@if ($signInUrl)
<x-mail::button :url="$signInUrl">
Open the platform
</x-mail::button>
@else
Your programme team can give you the details and, where you need to enter
something yourself, will send you a link for it.
@endif

<x-mail::subcopy>
You are receiving this because your business is enrolled on the Business
Mastery Programme. If this reached you in error, please tell your programme
team.
</x-mail::subcopy>
</x-mail::message>
