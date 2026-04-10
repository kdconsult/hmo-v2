<x-mail::message>
# Your account has been suspended

Your account for **{{ $tenant->name }}** has been suspended.

<x-mail::panel>
**Reason:** {{ $tenant->deactivation_reason ?? 'Administrative action' }}<br>
**Suspended on:** {{ $tenant->deactivated_at?->format('d M Y') }}
</x-mail::panel>

Access to your ERP instance has been disabled. All your data is preserved and can be restored.

To reactivate your account, please contact us:

<x-mail::button :url="'mailto:'.config('hmo.landlord_email')">
Contact Support
</x-mail::button>

Thanks,<br>
{{ config('app.name') }} Team
</x-mail::message>
