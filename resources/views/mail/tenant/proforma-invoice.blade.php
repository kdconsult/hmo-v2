<x-mail::message>
# Proforma Invoice — {{ $plan->name }} Plan

Hi {{ $user->name }},

Thank you for choosing the **{{ $plan->name }}** plan for **{{ $tenant->name }}**.

<x-mail::panel>
**Plan:** {{ $plan->name }}
**Amount:** €{{ number_format($amount, 2) }} / {{ $billingPeriod }}
**Company:** {{ $tenant->name }}
</x-mail::panel>

## Payment Instructions

Please transfer the amount to our bank account:

- **IBAN:** *(to be configured)*
- **BIC/SWIFT:** *(to be configured)*
- **Reference:** {{ $tenant->slug }}-{{ now()->format('Ymd') }}

Your subscription will be activated within 1–2 business days after payment is confirmed.

If you have any questions, reply to this email.

Thanks,<br>
{{ config('app.name') }} Team
</x-mail::message>
