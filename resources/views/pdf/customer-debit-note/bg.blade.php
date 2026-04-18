@php
    $document = $note;
    $document->document_number = $note->debit_note_number;
    $document->supplied_at = $note->customerInvoice?->supplied_at;
    $document->vat_scenario = $note->customerInvoice?->vat_scenario;
    $document->is_reverse_charge = $note->customerInvoice?->is_reverse_charge;
    $document->vies_request_id = $note->customerInvoice?->vies_request_id;
    $document->vies_checked_at = $note->customerInvoice?->vies_checked_at;
    $parent_invoice_number = $note->customerInvoice?->invoice_number;
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('invoice-pdf.heading.debit_note') }} {{ $document->document_number }}</title>
    @include('pdf.components._styles')
</head>
<body>
<div class="page">
    @include('pdf.components._header', [
        'heading' => __('invoice-pdf.heading.debit_note'),
        'document_number' => $document->document_number,
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
