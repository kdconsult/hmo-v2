<table class="items">
    <thead>
        <tr>
            <th style="width:40%">{{ __('invoice-pdf.description') }}</th>
            <th class="text-right" style="width:10%">{{ __('invoice-pdf.sku') }}</th>
            <th class="text-right" style="width:10%">{{ __('invoice-pdf.qty') }}</th>
            <th class="text-right" style="width:12%">{{ __('invoice-pdf.unit_price') }}</th>
            <th class="text-right" style="width:8%">{{ __('invoice-pdf.discount_percent') }}</th>
            <th class="text-right" style="width:10%">{{ __('invoice-pdf.vat') }}</th>
            <th class="text-right" style="width:10%">{{ __('invoice-pdf.total') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach($items as $item)
            <tr>
                <td>{{ $item->description ?: ($item->productVariant?->product?->name ?? '—') }}</td>
                <td class="text-right">{{ $item->productVariant?->sku ?? '—' }}</td>
                <td class="text-right">{{ number_format((float) $item->quantity, 4) }}</td>
                <td class="text-right">{{ number_format((float) $item->unit_price, 4) }}</td>
                <td class="text-right">{{ number_format((float) $item->discount_percent, 2) }}%</td>
                <td class="text-right">{{ number_format((float) $item->vat_amount, 2) }}</td>
                <td class="text-right">{{ number_format((float) $item->line_total_with_vat, 2) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
