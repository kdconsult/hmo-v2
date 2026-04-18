@php
    $itemsByRate = $items->groupBy(fn ($i) => number_format((float) ($i->vatRate?->rate ?? 0), 2, '.', ''));
@endphp
<div class="totals-wrapper">
    <table class="totals">
        <tr>
            <td class="totals-label">{{ __('invoice-pdf.subtotal') }}:</td>
            <td class="totals-value">{{ number_format((float) $subtotal, 2) }} {{ $currency_code }}</td>
        </tr>

        @if(bccomp((string) $discount_amount, '0', 2) > 0)
            <tr>
                <td class="totals-label">{{ __('invoice-pdf.discount') }}:</td>
                <td class="totals-value">- {{ number_format((float) $discount_amount, 2) }} {{ $currency_code }}</td>
            </tr>
        @endif

        @foreach($itemsByRate as $rate => $group)
            @php
                $netAtRate = $group->sum(fn ($i) => (float) $i->line_total);
                $vatAtRate = $group->sum(fn ($i) => (float) $i->vat_amount);
                $rateLabel = rtrim(rtrim($rate, '0'), '.');
            @endphp
            <tr>
                <td class="totals-label">{{ __('invoice-pdf.net_at_rate', ['rate' => $rateLabel]) }}:</td>
                <td class="totals-value">{{ number_format($netAtRate, 2) }} {{ $currency_code }}</td>
            </tr>
            <tr>
                <td class="totals-label">{{ __('invoice-pdf.vat_at_rate', ['rate' => $rateLabel]) }}:</td>
                <td class="totals-value">{{ number_format($vatAtRate, 2) }} {{ $currency_code }}</td>
            </tr>
        @endforeach

        <tr class="totals-grand">
            <td class="totals-label">{{ __('invoice-pdf.grand_total') }}:</td>
            <td class="totals-value">{{ number_format((float) $total, 2) }} {{ $currency_code }}</td>
        </tr>

        @if(isset($amount_paid) && bccomp((string) $amount_paid, '0', 2) > 0)
            <tr>
                <td class="totals-label">{{ __('invoice-pdf.amount_paid') }}:</td>
                <td class="totals-value">{{ number_format((float) $amount_paid, 2) }} {{ $currency_code }}</td>
            </tr>
        @endif

        @if(isset($amount_due) && bccomp((string) $amount_due, '0', 2) > 0)
            <tr class="totals-due">
                <td class="totals-label">{{ __('invoice-pdf.amount_due') }}:</td>
                <td class="totals-value">{{ number_format((float) $amount_due, 2) }} {{ $currency_code }}</td>
            </tr>
        @endif
    </table>
</div>
