@php
    $tenant = tenancy()->tenant;
    $suppliedDistinct = $supplied_at && $issued_at && ! $supplied_at->equalTo($issued_at);
@endphp
<div class="header">
    <table>
        <tr>
            <td class="header-left">
                <div class="company-name">{{ $tenant?->name ?: config('app.name') }}</div>
                <div class="company-detail">
                    @if($tenant?->eik)
                        {{ __('invoice-pdf.eik') }}: {{ $tenant->eik }}<br>
                    @endif
                    @if($tenant?->vat_number)
                        {{ __('invoice-pdf.vat_id') }}: {{ $tenant->vat_number }}<br>
                    @endif
                    @if($tenant?->email){{ $tenant->email }}@endif
                </div>
            </td>
            <td class="header-right">
                <div class="document-title">{{ $heading }}</div>
                <div class="document-meta">{{ __('invoice-pdf.no') }}: {{ $document_number }}</div>
                <div class="document-meta">{{ __('invoice-pdf.date_of_issue') }}: {{ $issued_at?->format('d.m.Y') }}</div>
                @if($suppliedDistinct)
                    <div class="document-meta">{{ __('invoice-pdf.date_of_supply') }}: {{ $supplied_at->format('d.m.Y') }}</div>
                @endif
                @if($due_date)
                    <div class="document-meta">{{ __('invoice-pdf.due_date') }}: {{ $due_date->format('d.m.Y') }}</div>
                @endif
                @if($parent_invoice)
                    <div class="document-meta">
                        {{ __('invoice-pdf.parent_invoice') }}: {{ $parent_invoice }}
                        @if(!empty($parent_invoice_issued_at))
                            {{ __('invoice-pdf.issued') }} {{ $parent_invoice_issued_at->format('d.m.Y') }}
                        @endif
                    </div>
                @endif
            </td>
        </tr>
    </table>
</div>
