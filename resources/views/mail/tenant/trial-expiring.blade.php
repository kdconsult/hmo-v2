<x-mail::message>
# Your trial ends in {{ $daysLeft }} {{ Str::plural('day', $daysLeft) }}

Hi {{ $user->name }},

Your free trial for **{{ $tenant->name }}** expires on **{{ $trialEndsAt?->format('d M Y') }}**.

After the trial ends, access to your account will be suspended until you upgrade to a paid plan.

<x-mail::button :url="$loginUrl">
Log In Now
</x-mail::button>

To continue using {{ config('app.name') }}, contact us to upgrade your plan. We offer flexible monthly billing via bank transfer.

Thanks,<br>
{{ config('app.name') }} Team
</x-mail::message>
