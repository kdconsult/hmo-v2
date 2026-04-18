@php
    $document = $invoice;
    $parent_invoice_number = null;
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('invoice-pdf.heading.invoice') }} {{ $document->invoice_number }}</title>
    @include('pdf.components._styles')
</head>
<body>
<div class="page">
    @include('pdf.components._header', [
        'heading' => __('invoice-pdf.heading.invoice'),
        'document_number' => $document->invoice_number,
        'issued_at' => $document->issued_at,
        'supplied_at' => $document->supplied_at,
        'due_date' => $document->due_date,
        'parent_invoice' => $parent_invoice_number,
    ])

    @include('pdf.components._parties', [
        'customer' => $document->partner,
    ])

    @include('pdf.components._vat-treatment', [
        'vat_scenario' => $document->vat_scenario,
        'vat_scenario_sub_code' => $document->vat_scenario_sub_code,
        'is_reverse_charge' => $document->is_reverse_charge,
        'vies_request_id' => $document->vies_request_id,
        'vies_checked_at' => $document->vies_checked_at,
    ])

    @include('pdf.components._items-table', ['items' => $document->items])

    @include('pdf.components._totals', [
        'items' => $document->items,
        'subtotal' => $document->subtotal,
        'discount_amount' => $document->discount_amount,
        'total' => $document->total,
        'amount_paid' => $document->amount_paid ?? null,
        'amount_due' => $document->amount_due ?? null,
        'currency_code' => $document->currency_code,
    ])

    @include('pdf.components._footer')
</div>
</body>
</html>
