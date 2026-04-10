<x-mail::message>
# Final notice: Your account will be deleted

Your account for **{{ $tenant->name }}** is scheduled for permanent deletion.

<x-mail::panel>
**Deletion date:** {{ $tenant->deletion_scheduled_for?->format('d M Y') }}
</x-mail::panel>

On the date above, your account and all associated data will be permanently deleted. This action cannot be undone.

If you believe this is a mistake or wish to reactivate your account, contact us before the deletion date:

<x-mail::button :url="'mailto:'.config('hmo.landlord_email')" color="red">
Contact Support Immediately
</x-mail::button>

Thanks,<br>
{{ config('app.name') }} Team
</x-mail::message>
