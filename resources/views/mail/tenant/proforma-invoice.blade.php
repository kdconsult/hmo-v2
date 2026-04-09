<x-mail::message>
# Proforma Invoice — {{ $plan->name }} Plan

Hi {{ $user->name }},

Thank you for choosing the **{{ $plan->name }}** plan for **{{ $tenant->name }}**.

<x-mail::panel>
**Plan:** {{ $plan->name }}
**Amount:** €{{ number_format($amount, 2) }}{{ $billingPeriod ? ' / ' . $billingPeriod : '' }}
**Company:** {{ $tenant->name }}
**Payment reference:** {{ $paymentReference }}
</x-mail::panel>

## Payment Instructions

Please transfer the exact amount to our bank account:

| | |
|---|---|
| **Bank** | {{ $bankName ?: '—' }} |
| **IBAN** | {{ $bankIban ?: '—' }} |
| **BIC/SWIFT** | {{ $bankBic ?: '—' }} |
| **Reference** | **{{ $paymentReference }}** |

> Use the payment reference exactly as shown so we can match your payment automatically.

Your subscription will be activated within 1–2 business days after payment is confirmed.

A PDF copy of this proforma invoice is attached to this email.

If you have any questions, reply to this email.

Thanks,<br>
{{ config('app.name') }} Team
</x-mail::message>
