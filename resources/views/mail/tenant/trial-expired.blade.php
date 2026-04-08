<x-mail::message>
# Your trial has ended

Hi {{ $user->name }},

Your 14-day free trial for **{{ $tenant->name }}** has ended. Access to your account has been suspended.

Your data is safe and will be preserved. To restore access, please upgrade to a paid plan by contacting us.

**To upgrade:** Reply to this email or contact our team. We will send you a proforma invoice and activate your account upon payment confirmation.

Thanks,<br>
{{ config('app.name') }} Team
</x-mail::message>
