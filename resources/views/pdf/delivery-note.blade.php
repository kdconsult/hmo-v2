<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            color: #1a1a1a;
            margin: 0;
            padding: 0;
        }
        .page { padding: 40px 48px; }

        /* ── Header ── */
        .header {
            width: 100%;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 20px;
            margin-bottom: 32px;
        }
        .header table { width: 100%; border-collapse: collapse; margin-bottom: 0; }
        .header-left { vertical-align: top; }
        .header-right { vertical-align: top; text-align: right; }
        .company-name { font-size: 20px; font-weight: bold; color: #111827; }
        .document-title { font-size: 18px; font-weight: bold; color: #374151; }
        .document-meta { font-size: 11px; color: #6b7280; margin-top: 4px; }
        .company-detail { font-size: 11px; color: #6b7280; margin-top: 4px; line-height: 1.6; }

        /* ── Parties ── */
        .parties { width: 100%; margin-bottom: 32px; }
        .parties table { width: 100%; border-collapse: collapse; margin-bottom: 0; }
        .party-cell { vertical-align: top; width: 50%; }
        .party-cell-right { vertical-align: top; width: 50%; text-align: right; }
        .party-label { font-size: 10px; text-transform: uppercase; color: #9ca3af; letter-spacing: 0.05em; margin-bottom: 6px; }
        .party-name { font-size: 14px; font-weight: bold; color: #111827; margin-bottom: 4px; }
        .party-detail { font-size: 11px; color: #4b5563; line-height: 1.6; }

        /* ── Meta box ── */
        .meta-box {
            background-color: #f9fafb;
            border: 1px solid #e5e7eb;
            padding: 12px 16px;
            margin-bottom: 24px;
        }
        table.meta { width: 100%; border-collapse: collapse; margin-bottom: 0; }
        table.meta td { padding: 3px 0; font-size: 11px; border-bottom: none; }
        .meta-label { width: 160px; color: #6b7280; }
        .meta-value { color: #111827; font-weight: bold; }

        /* ── Line items ── */
        table.items { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
        table.items thead th {
            background-color: #f3f4f6;
            padding: 8px 12px;
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            color: #6b7280;
            letter-spacing: 0.05em;
        }
        table.items tbody td {
            padding: 10px 12px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 12px;
            color: #374151;
        }
        .text-right { text-align: right; }

        /* ── Footer ── */
        .footer {
            margin-top: 40px;
            border-top: 1px solid #e5e7eb;
            padding-top: 16px;
            font-size: 10px;
            color: #9ca3af;
            text-align: center;
        }
    </style>
</head>
<body>
<div class="page">

    {{-- Header --}}
    <div class="header">
        <table>
            <tr>
                <td class="header-left">
                    <div class="company-name">{{ tenant('name') ?: config('app.name') }}</div>
                    <div class="company-detail">
                        @if(tenant('eik'))EIK: {{ tenant('eik') }}<br>@endif
                        @if(tenant('vat_number'))VAT No: {{ tenant('vat_number') }}<br>@endif
                        @if(tenant('email')){{ tenant('email') }}@endif
                    </div>
                </td>
                <td class="header-right">
                    <div class="document-title">DELIVERY NOTE</div>
                    <div class="document-meta">Ref: {{ $deliveryNote->dn_number }}</div>
                    <div class="document-meta">Date: {{ $deliveryNote->delivered_at?->format('d.m.Y') ?: now()->format('d.m.Y') }}</div>
                    @if($deliveryNote->salesOrder)
                        <div class="document-meta">SO: {{ $deliveryNote->salesOrder->so_number }}</div>
                    @endif
                </td>
            </tr>
        </table>
    </div>

    {{-- Parties --}}
    <div class="parties">
        <table>
            <tr>
                <td class="party-cell">
                    <div class="party-label">From (Seller)</div>
                    <div class="party-name">{{ tenant('name') ?: config('app.name') }}</div>
                    <div class="party-detail">
                        @if(tenant('eik'))EIK: {{ tenant('eik') }}<br>@endif
                        @if(tenant('vat_number'))VAT: {{ tenant('vat_number') }}<br>@endif
                        @if(tenant('email')){{ tenant('email') }}@endif
                    </div>
                </td>
                <td class="party-cell-right">
                    <div class="party-label">To (Buyer)</div>
                    <div class="party-name">{{ $deliveryNote->partner->company_name ?: $deliveryNote->partner->name }}</div>
                    <div class="party-detail">
                        @if($deliveryNote->partner->vat_number)VAT: {{ $deliveryNote->partner->vat_number }}<br>@endif
                        @if($deliveryNote->partner->eik)EIK: {{ $deliveryNote->partner->eik }}<br>@endif
                        @if($deliveryNote->partner->email){{ $deliveryNote->partner->email }}@endif
                    </div>
                </td>
            </tr>
        </table>
    </div>

    {{-- Meta box --}}
    <div class="meta-box">
        <table class="meta">
            <tr>
                <td class="meta-label">DN Number:</td>
                <td class="meta-value">{{ $deliveryNote->dn_number }}</td>
                <td class="meta-label">Warehouse:</td>
                <td class="meta-value">{{ $deliveryNote->warehouse->name }}</td>
            </tr>
            @if($deliveryNote->notes)
            <tr>
                <td class="meta-label">Notes:</td>
                <td class="meta-value" colspan="3">{{ $deliveryNote->notes }}</td>
            </tr>
            @endif
        </table>
    </div>

    {{-- Line items --}}
    <table class="items">
        <thead>
            <tr>
                <th style="width:50%">Product / Description</th>
                <th class="text-right" style="width:15%">SKU</th>
                <th class="text-right" style="width:15%">Quantity</th>
                <th class="text-right" style="width:20%">Unit Cost</th>
            </tr>
        </thead>
        <tbody>
            @foreach($deliveryNote->items as $item)
            <tr>
                <td>{{ $item->productVariant?->product?->name ?? $item->notes ?? '—' }}</td>
                <td class="text-right">{{ $item->productVariant?->sku ?? '—' }}</td>
                <td class="text-right">{{ number_format((float) $item->quantity, 4) }}</td>
                <td class="text-right">{{ number_format((float) $item->unit_cost, 4) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        This is a delivery note. It is not a VAT invoice.
    </div>

</div>
</body>
</html>
