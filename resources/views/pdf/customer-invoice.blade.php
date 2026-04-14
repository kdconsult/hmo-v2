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
        .totals-wrapper { width: 100%; margin-bottom: 32px; }
        table.totals { width: 300px; margin-left: auto; border-collapse: collapse; }
        table.totals td { padding: 4px 8px; font-size: 12px; }
        .totals-label { color: #6b7280; text-align: right; }
        .totals-value { text-align: right; font-weight: bold; color: #111827; }
        .totals-grand td { border-top: 2px solid #111827; font-size: 14px; font-weight: bold; }
        .totals-due td { color: #dc2626; font-size: 14px; font-weight: bold; }

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
                    <div class="document-title">INVOICE</div>
                    <div class="document-meta">No: {{ $invoice->invoice_number }}</div>
                    <div class="document-meta">Date: {{ $invoice->issued_at?->format('d.m.Y') ?: now()->format('d.m.Y') }}</div>
                    @if($invoice->due_date)
                        <div class="document-meta">Due: {{ $invoice->due_date->format('d.m.Y') }}</div>
                    @endif
                    @if($invoice->salesOrder)
                        <div class="document-meta">SO: {{ $invoice->salesOrder->so_number }}</div>
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
                    <div class="party-label">To (Customer)</div>
                    <div class="party-name">{{ $invoice->partner->company_name ?: $invoice->partner->name }}</div>
                    <div class="party-detail">
                        @if($invoice->partner->vat_number)VAT: {{ $invoice->partner->vat_number }}<br>@endif
                        @if($invoice->partner->eik)EIK: {{ $invoice->partner->eik }}<br>@endif
                        @if($invoice->partner->email){{ $invoice->partner->email }}@endif
                    </div>
                </td>
            </tr>
        </table>
    </div>

    {{-- Meta box --}}
    <div class="meta-box">
        <table class="meta">
            <tr>
                <td class="meta-label">Invoice Number:</td>
                <td class="meta-value">{{ $invoice->invoice_number }}</td>
                <td class="meta-label">Payment Method:</td>
                <td class="meta-value">{{ $invoice->payment_method?->getLabel() ?? '—' }}</td>
            </tr>
            @if($invoice->is_reverse_charge)
            <tr>
                <td class="meta-label">VAT Treatment:</td>
                <td class="meta-value" colspan="3">Reverse Charge — VAT accounted for by the recipient</td>
            </tr>
            @endif
            @if($invoice->notes)
            <tr>
                <td class="meta-label">Notes:</td>
                <td class="meta-value" colspan="3">{{ $invoice->notes }}</td>
            </tr>
            @endif
        </table>
    </div>

    {{-- Line items --}}
    <table class="items">
        <thead>
            <tr>
                <th style="width:40%">Description</th>
                <th class="text-right" style="width:10%">SKU</th>
                <th class="text-right" style="width:10%">Qty</th>
                <th class="text-right" style="width:12%">Unit Price</th>
                <th class="text-right" style="width:8%">Disc%</th>
                <th class="text-right" style="width:10%">VAT</th>
                <th class="text-right" style="width:10%">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $item)
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

    {{-- Totals --}}
    <div class="totals-wrapper">
        <table class="totals">
            <tr>
                <td class="totals-label">Subtotal:</td>
                <td class="totals-value">{{ number_format((float) $invoice->subtotal, 2) }} {{ $invoice->currency_code }}</td>
            </tr>
            @if(bccomp((string) $invoice->discount_amount, '0', 2) > 0)
            <tr>
                <td class="totals-label">Discount:</td>
                <td class="totals-value">- {{ number_format((float) $invoice->discount_amount, 2) }} {{ $invoice->currency_code }}</td>
            </tr>
            @endif
            <tr>
                <td class="totals-label">VAT:</td>
                <td class="totals-value">{{ number_format((float) $invoice->tax_amount, 2) }} {{ $invoice->currency_code }}</td>
            </tr>
            <tr class="totals-grand">
                <td class="totals-label">Total:</td>
                <td class="totals-value">{{ number_format((float) $invoice->total, 2) }} {{ $invoice->currency_code }}</td>
            </tr>
            @if(bccomp((string) $invoice->amount_paid, '0', 2) > 0)
            <tr>
                <td class="totals-label">Paid:</td>
                <td class="totals-value">{{ number_format((float) $invoice->amount_paid, 2) }} {{ $invoice->currency_code }}</td>
            </tr>
            @endif
            <tr class="totals-due">
                <td class="totals-label">Amount Due:</td>
                <td class="totals-value">{{ number_format((float) $invoice->amount_due, 2) }} {{ $invoice->currency_code }}</td>
            </tr>
        </table>
    </div>

    <div class="footer">
        This is a VAT invoice. Please retain for your records.
    </div>

</div>
</body>
</html>
