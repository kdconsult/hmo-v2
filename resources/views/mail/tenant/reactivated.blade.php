<x-mail::message>
# Your account has been reactivated

Great news! Your account for **{{ $tenant->name }}** has been reactivated.

You can now log in and continue using {{ config('app.name') }}.

<x-mail::button :url="\App\Support\TenantUrl::to($tenant->slug, 'admin')">
Open {{ $tenant->name }}
</x-mail::button>

If you have any questions, reply to this email.

Thanks,<br>
{{ config('app.name') }} Team
</x-mail::message>
