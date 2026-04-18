@php
    $tenant = tenancy()->tenant;

    $supplierAddressParts = array_filter([
        $tenant?->address_line_1,
        $tenant?->address_line_2 ?? null,
        trim(($tenant?->postal_code ?? '').' '.($tenant?->city ?? '')),
    ], fn ($p) => filled($p));
    $supplierAddress = implode(', ', $supplierAddressParts);

    $billingAddress = $customer->addresses->firstWhere('is_billing', true)
        ?? $customer->addresses->firstWhere('is_default', true)
        ?? $customer->addresses->first();

    $customerAddress = '';
    if ($billingAddress) {
        $customerAddressParts = array_filter([
            $billingAddress->address_line_1,
            $billingAddress->address_line_2 ?? null,
            trim(($billingAddress->postal_code ?? '').' '.($billingAddress->city ?? '')),
        ], fn ($p) => filled($p));
        $customerAddress = implode(', ', $customerAddressParts);
    }
@endphp
<div class="parties">
    <table>
        <tr>
            <td class="party-cell">
                <div class="party-label">{{ __('invoice-pdf.from_supplier') }}</div>
                <div class="party-name">{{ $tenant?->name ?: config('app.name') }}</div>
                <div class="party-detail">
                    @if($supplierAddress){{ $supplierAddress }}<br>@endif
                    @if($tenant?->eik){{ __('invoice-pdf.eik') }}: {{ $tenant->eik }}<br>@endif
                    @if($tenant?->vat_number){{ __('invoice-pdf.vat_id') }}: {{ $tenant->vat_number }}<br>@endif
                    @if($tenant?->email){{ $tenant->email }}@endif
                </div>
            </td>
            <td class="party-cell-right">
                <div class="party-label">{{ __('invoice-pdf.to_customer') }}</div>
                <div class="party-name">{{ $customer->company_name ?: $customer->name }}</div>
                <div class="party-detail">
                    @if($customerAddress){{ $customerAddress }}<br>@endif
                    @if($customer->vat_number){{ __('invoice-pdf.vat_id') }}: {{ $customer->vat_number }}<br>@endif
                    @if($customer->eik){{ __('invoice-pdf.eik') }}: {{ $customer->eik }}<br>@endif
                    @if($customer->email){{ $customer->email }}@endif
                </div>
            </td>
        </tr>
    </table>
</div>
