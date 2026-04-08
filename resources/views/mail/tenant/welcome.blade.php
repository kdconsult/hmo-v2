<x-mail::message>
# Welcome to {{ config('app.name') }}, {{ $user->name }}!

Your account for **{{ $tenant->name }}** has been created and your 14-day free trial has started.

<x-mail::panel>
**Trial ends:** {{ $trialEndsAt?->format('d M Y') }}
</x-mail::panel>

## Getting Started

Your ERP instance is ready at your own subdomain. Log in now and start exploring:

<x-mail::button :url="$loginUrl">
Open {{ $tenant->name }}
</x-mail::button>

**Your login credentials:**
- Email: {{ $user->email }}
- URL: {{ $loginUrl }}

## What's next?

1. Complete your company profile in Settings
2. Add your team members
3. Set up your first invoice or document

If you have any questions, reply to this email.

Thanks,<br>
{{ config('app.name') }} Team
</x-mail::message>
