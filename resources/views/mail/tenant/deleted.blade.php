<x-mail::message>
# Your account has been permanently deleted

Your account for **{{ $tenantName }}** has been permanently deleted along with all associated data.

This action was completed as per the scheduled deletion process. All data has been irreversibly removed from our systems.

If you have any questions, please contact us:

<x-mail::button :url="'mailto:'.config('hmo.landlord_email')">
Contact Support
</x-mail::button>

Thanks,<br>
{{ config('app.name') }} Team
</x-mail::message>
