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

        /* ── Totals ── */
        .totals-wrap { width: 100%; margin-bottom: 32px; }
        table.totals { width: 280px; border-collapse: collapse; margin-left: auto; margin-bottom: 0; }
        table.totals td { padding: 6px 0; font-size: 12px; color: #4b5563; border-bottom: 1px solid #f3f4f6; }
        table.totals tr.total-row td {
            padding: 10px 0;
            font-size: 14px;
            font-weight: bold;
            color: #111827;
            border-top: 2px solid #374151;
            border-bottom: none;
        }

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
                        @if(tenant('eik'))VAT/EIK: {{ tenant('eik') }}<br>@endif
                        @if(tenant('vat_number'))VAT No: {{ tenant('vat_number') }}<br>@endif
                        @if(tenant('email')){{ tenant('email') }}@endif
                    </div>
                </td>
                <td class="header-right">
                    <div class="document-title">OFFER</div>
                    <div class="document-meta">Ref: {{ $quotation->quotation_number }}</div>
                    <div class="document-meta">Date: {{ $quotation->issued_at?->format('d.m.Y') ?: now()->format('d.m.Y') }}</div>
                    @if($quotation->valid_until)
                        <div class="document-meta">Valid until: {{ $quotation->valid_until->format('d.m.Y') }}</div>
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
                    <div class="party-name">{{ $quotation->partner->company_name ?: $quotation->partner->name }}</div>
                    <div class="party-detail">
                        @if($quotation->partner->vat_number)VAT: {{ $quotation->partner->vat_number }}<br>@endif
                        @if($quotation->partner->eik)EIK: {{ $quotation->partner->eik }}<br>@endif
                        @if($quotation->partner->email){{ $quotation->partner->email }}@endif
                    </div>
                </td>
            </tr>
        </table>
    </div>

    {{-- Meta box --}}
    <div class="meta-box">
        <table class="meta">
            <tr>
                <td class="meta-label">Offer Number:</td>
                <td class="meta-value">{{ $quotation->quotation_number }}</td>
                <td class="meta-label">Currency:</td>
                <td class="meta-value">{{ $quotation->currency_code }}</td>
            </tr>
            @if($quotation->notes)
            <tr>
                <td class="meta-label">Notes:</td>
                <td class="meta-value" colspan="3">{{ $quotation->notes }}</td>
            </tr>
            @endif
        </table>
    </div>

    {{-- Line items --}}
    <table class="items">
        <thead>
            <tr>
                <th style="width:40%">Description</th>
                <th class="text-right" style="width:10%">Qty</th>
                <th class="text-right" style="width:15%">Unit Price</th>
                <th class="text-right" style="width:10%">Disc%</th>
                <th class="text-right" style="width:10%">VAT%</th>
                <th class="text-right" style="width:15%">Net Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($quotation->items as $item)
            <tr>
                <td>
                    {{ $item->description ?: $item->productVariant?->sku }}
                    @if($item->productVariant?->sku && $item->description)
                        <br><span style="font-size:10px; color:#9ca3af">{{ $item->productVariant->sku }}</span>
                    @endif
                </td>
                <td class="text-right">{{ number_format((float) $item->quantity, 2) }}</td>
                <td class="text-right">{{ number_format((float) $item->unit_price, 2) }}</td>
                <td class="text-right">{{ number_format((float) $item->discount_percent, 1) }}%</td>
                <td class="text-right">{{ $item->vatRate?->rate ?? 0 }}%</td>
                <td class="text-right">{{ number_format((float) $item->line_total, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Totals --}}
    <div class="totals-wrap">
        <table class="totals">
            <tr>
                <td>Subtotal (excl. VAT)</td>
                <td class="text-right">{{ $quotation->currency_code }} {{ number_format((float) $quotation->subtotal, 2) }}</td>
            </tr>
            @if((float) $quotation->discount_amount > 0)
            <tr>
                <td>Discount</td>
                <td class="text-right">- {{ $quotation->currency_code }} {{ number_format((float) $quotation->discount_amount, 2) }}</td>
            </tr>
            @endif
            <tr>
                <td>VAT</td>
                <td class="text-right">{{ $quotation->currency_code }} {{ number_format((float) $quotation->tax_amount, 2) }}</td>
            </tr>
            <tr class="total-row">
                <td>TOTAL</td>
                <td class="text-right">{{ $quotation->currency_code }} {{ number_format((float) $quotation->total, 2) }}</td>
            </tr>
        </table>
    </div>

    <div class="footer">
        This is a commercial offer. It is not a VAT invoice.
    </div>

</div>
</body>
</html>
