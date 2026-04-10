<!DOCTYPE html>
<html lang="bg">
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
        /* .page {
            padding: 40px 48px;
        } */

        /* ── Header ── */
        .header {
            width: 100%;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 20px;
            margin-bottom: 32px;
        }
        .header table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 0;
        }
        .header-left { vertical-align: top; }
        .header-right { vertical-align: top; text-align: right; }
        .company-name {
            font-size: 20px;
            font-weight: bold;
            color: #111827;
        }
        .document-title {
            font-size: 18px;
            font-weight: bold;
            color: #374151;
        }
        .document-title-sub {
            font-size: 13px;
            font-weight: bold;
            color: #374151;
        }
        .document-meta {
            font-size: 11px;
            color: #6b7280;
            margin-top: 4px;
        }
        .company-detail {
            font-size: 11px;
            color: #6b7280;
            margin-top: 4px;
            line-height: 1.6;
        }

        /* ── Parties ── */
        .parties {
            width: 100%;
            margin-bottom: 32px;
        }
        .parties table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 0;
        }
        .party-cell { vertical-align: top; width: 50%; }
        .party-cell-right { vertical-align: top; width: 50%; text-align: right; }
        .party-label {
            font-size: 10px;
            text-transform: uppercase;
            color: #9ca3af;
            letter-spacing: 0.05em;
            margin-bottom: 6px;
        }
        .party-name {
            font-size: 14px;
            font-weight: bold;
            color: #111827;
            margin-bottom: 4px;
        }
        .party-detail {
            font-size: 11px;
            color: #4b5563;
            line-height: 1.6;
        }

        /* ── Line items ── */
        table.items {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
        }
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
        .totals-wrap {
            width: 100%;
            margin-bottom: 32px;
        }
        table.totals {
            width: 280px;
            border-collapse: collapse;
            margin-left: auto;
            margin-bottom: 0;
        }
        table.totals td {
            padding: 6px 0;
            font-size: 12px;
            color: #4b5563;
            border-bottom: 1px solid #f3f4f6;
        }
        table.totals tr.total-row td {
            padding: 10px 0;
            font-size: 14px;
            font-weight: bold;
            color: #111827;
            border-top: 2px solid #374151;
            border-bottom: none;
        }

        /* ── Payment section ── */
        .payment-section {
            background-color: #f9fafb;
            border: 1px solid #e5e7eb;
            padding: 16px 20px;
            margin-bottom: 24px;
        }
        .payment-title {
            font-size: 13px;
            font-weight: bold;
            color: #374151;
            margin-bottom: 12px;
        }
        table.payment {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 0;
        }
        table.payment td {
            padding: 3px 0;
            font-size: 11px;
            vertical-align: top;
            border-bottom: none;
        }
        .payment-label { width: 140px; color: #6b7280; }
        .payment-value { color: #111827; font-weight: bold; }
        .reference-highlight {
            background-color: #fef3c7;
            padding: 2px 6px;
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
                    <div class="company-name">{{ $landlord?->name ?: config('app.name') }}</div>
                    <div class="company-detail">
                        @if($landlord?->formattedAddress()){{ $landlord->formattedAddress() }}<br>@endif
                        @if($landlord?->eik)ЕИК: {{ $landlord->eik }}<br>@endif
                        @if($landlord?->vat_number)ДДС №: {{ $landlord->vat_number }}@endif
                    </div>
                </td>
                <td class="header-right">
                    <div class="document-title">ПРОФОРМА ФАКТУРА</div>
                    <div class="document-title-sub">PROFORMA INVOICE</div>
                    <div class="document-meta">Reference: {{ $paymentReference }}</div>
                    <div class="document-meta">Date: {{ now()->format('d.m.Y') }}</div>
                </td>
            </tr>
        </table>
    </div>

    {{-- Parties --}}
    <div class="parties">
        <table>
            <tr>
                <td class="party-cell">
                    <div class="party-label">From (Supplier)</div>
                    <div class="party-name">{{ $landlord?->name ?: config('app.name') }}</div>
                    <div class="party-detail">
                        @if($landlord?->formattedAddress()){{ $landlord->formattedAddress() }}<br>@endif
                        @if($landlord?->eik)ЕИК: {{ $landlord->eik }}<br>@endif
                        @if($landlord?->vat_number)ДДС №: {{ $landlord->vat_number }}@endif
                    </div>
                </td>
                <td class="party-cell-right">
                    <div class="party-label">To (Customer)</div>
                    <div class="party-name">{{ $tenant->name }}</div>
                    <div class="party-detail">
                        @if($tenant->vat_number)ДДС №: {{ $tenant->vat_number }}<br>@endif
                        @if($tenant->eik)ЕИК: {{ $tenant->eik }}<br>@endif
                        @if($tenant->email){{ $tenant->email }}@endif
                    </div>
                </td>
            </tr>
        </table>
    </div>

    {{-- Line items --}}
    <table class="items">
        <thead>
            <tr>
                <th style="width:50%">Description</th>
                <th>Period</th>
                <th class="text-right">Unit Price</th>
                <th class="text-right">Qty</th>
                <th class="text-right">Total</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ config('app.name') }} — {{ $plan->name }} Plan
                    @if($plan->billing_period)
                        <br><span style="font-size:10px; color:#6b7280">({{ ucfirst($plan->billing_period) }} subscription)</span>
                    @endif
                </td>
                <td>{{ now()->format('m.Y') }}</td>
                <td class="text-right">€{{ number_format($plan->price, 2) }}</td>
                <td class="text-right">1</td>
                <td class="text-right">€{{ number_format($plan->price, 2) }}</td>
            </tr>
        </tbody>
    </table>

    {{-- Totals --}}
    <div class="totals-wrap">
        <table class="totals">
            <tr>
                <td>Subtotal (excl. VAT)</td>
                <td class="text-right">€{{ number_format($plan->price, 2) }}</td>
            </tr>
            <tr>
                <td>VAT (0%)</td>
                <td class="text-right">€0.00</td>
            </tr>
            <tr class="total-row">
                <td>TOTAL DUE</td>
                <td class="text-right">€{{ number_format($plan->price, 2) }}</td>
            </tr>
        </table>
    </div>

    {{-- Payment instructions --}}
    <div class="payment-section">
        <div class="payment-title">Payment Instructions / Платежни инструкции</div>
        <table class="payment">
            <tr>
                <td class="payment-label">Bank / Банка:</td>
                <td class="payment-value">{{ $landlord?->bank_name ?: '—' }}</td>
            </tr>
            <tr>
                <td class="payment-label">IBAN:</td>
                <td class="payment-value">{{ $landlord?->iban ?: '—' }}</td>
            </tr>
            <tr>
                <td class="payment-label">BIC/SWIFT:</td>
                <td class="payment-value">{{ $landlord?->bic ?: '—' }}</td>
            </tr>
            <tr>
                <td class="payment-label">Amount / Сума:</td>
                <td class="payment-value">€{{ number_format($plan->price, 2) }}</td>
            </tr>
            <tr>
                <td class="payment-label">Reference / Основание:</td>
                <td class="payment-value">
                    <span class="reference-highlight">{{ $paymentReference }}</span>
                </td>
            </tr>
        </table>
    </div>

    <div class="footer">
        This is a proforma invoice. It is not a VAT invoice. Subscription is activated upon confirmed payment receipt.<br>
        Това е проформа фактура. Не е данъчен документ по ЗДДС.
    </div>

</div>
</body>
</html>
