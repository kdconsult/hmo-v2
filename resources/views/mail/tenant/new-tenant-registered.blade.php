<x-mail::message>
# New Tenant Registered

A new tenant has self-registered on **{{ config('app.name') }}**.

<x-mail::panel>
**Company:** {{ $tenant->name }}
**Slug:** {{ $tenant->slug }}
**Email:** {{ $tenant->email }}
**Plan:** {{ $tenant->plan?->name ?? 'None' }}
**Registered at:** {{ $tenant->created_at->format('d M Y H:i') }}
</x-mail::panel>

<x-mail::button :url="$landlordUrl">
View in Landlord Panel
</x-mail::button>

Thanks,<br>
{{ config('app.name') }} System
</x-mail::message>
