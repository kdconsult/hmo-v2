<x-mail::message>
# Important: Your account is pending deletion

Your account for **{{ $tenant->name }}** has been marked for deletion.

<x-mail::panel>
**Marked on:** {{ $tenant->marked_for_deletion_at?->format('d M Y') }}
</x-mail::panel>

Your account will proceed to scheduled deletion unless you take action. All your data is still intact.

To prevent deletion and reactivate your account, please contact us immediately:

<x-mail::button :url="'mailto:'.config('hmo.landlord_email')" color="red">
Contact Support Now
</x-mail::button>

Thanks,<br>
{{ config('app.name') }} Team
</x-mail::message>
