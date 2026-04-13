<x-mail::message>
# You've been invited

Hi {{ $user->name }},

You've been invited to join **{{ config('app.name') }}**. Click the button below to set up your password and get started.

<x-mail::button :url="$inviteUrl">
Accept Invitation
</x-mail::button>

This link will expire in 48 hours.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
