{{--
    "Here is your form."

    Carries the business's own name, the form's name and the link, and nothing
    else - no figures, no internal notes, no staff names, no other business.
    The link IS the capability, so the copy says plainly that it is personal and
    that it expires.
--}}
<x-mail::message>
# {{ $formName }}

{{ $greeting }}

Your programme team has asked **{{ $customerName }}** to complete the form below
as part of the Business Mastery Programme.

<x-mail::button :url="$url">
Open the form
</x-mail::button>

You can save your answers and come back to the same link to finish later.

@if ($expiresAt)
This link stops working on **{{ $expiresAt->format('j F Y') }}**. If you need
more time, ask your programme team for a new one.
@endif

<x-mail::subcopy>
This link is personal to {{ $customerName }} and opens this form only — it is not
a login and gives access to nothing else. Please do not forward it. If it reached
you in error, tell your programme team and ignore this message.
</x-mail::subcopy>
</x-mail::message>
