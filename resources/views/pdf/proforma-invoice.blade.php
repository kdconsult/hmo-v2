<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            color: #1a1a1a;
            margin: 0;
            padding: 0;
        }
        .page {
            padding: 40px 48px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 32px;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 20px;
        }
        .company-name {
            font-size: 20px;
            font-weight: bold;
            color: #111827;
        }
        .document-title {
            font-size: 18px;
            font-weight: bold;
            color: #374151;
            text-align: right;
        }
        .document-meta {
            font-size: 11px;
            color: #6b7280;
            text-align: right;
            margin-top: 4px;
        }
        .parties {
            display: flex;
            justify-content: space-between;
            margin-bottom: 32px;
        }
        .party {
            width: 45%;
        }
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
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
        }
        thead th {
            background-color: #f3f4f6;
            padding: 8px 12px;
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            color: #6b7280;
            letter-spacing: 0.05em;
        }
        tbody td {
            padding: 10px 12px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 12px;
            color: #374151;
        }
        .text-right { text-align: right; }
        .totals {
            width: 280px;
            margin-left: auto;
            margin-bottom: 32px;
        }
        .totals-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            font-size: 12px;
            color: #4b5563;
            border-bottom: 1px solid #f3f4f6;
        }
        .totals-total {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            font-size: 14px;
            font-weight: bold;
            color: #111827;
            border-top: 2px solid #374151;
            margin-top: 4px;
        }
        .payment-section {
            background-color: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
            padding: 16px 20px;
            margin-bottom: 24px;
        }
        .payment-title {
            font-size: 13px;
            font-weight: bold;
            color: #374151;
            margin-bottom: 12px;
        }
        .payment-row {
            display: flex;
            margin-bottom: 6px;
            font-size: 11px;
        }
        .payment-label {
            width: 140px;
            color: #6b7280;
        }
        .payment-value {
            color: #111827;
            font-weight: bold;
        }
        .reference-highlight {
            background-color: #fef3c7;
            padding: 2px 6px;
            border-radius: 2px;
        }
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
        <div>
            <div class="company-name">{{ config('hmo.company_name') ?: config('app.name') }}</div>
            <div style="font-size:11px; color:#6b7280; margin-top:4px; line-height:1.6">
                @if(config('hmo.company_address')){{ config('hmo.company_address') }}<br>@endif
                @if(config('hmo.company_eik'))ЕИК: {{ config('hmo.company_eik') }}<br>@endif
                @if(config('hmo.company_vat'))ДДС №: {{ config('hmo.company_vat') }}@endif
            </div>
        </div>
        <div>
            <div class="document-title">ПРОФОРМА ФАКТУРА</div>
            <div class="document-title" style="font-size:13px">PROFORMA INVOICE</div>
            <div class="document-meta">Reference: {{ $paymentReference }}</div>
            <div class="document-meta">Date: {{ now()->format('d.m.Y') }}</div>
        </div>
    </div>

    {{-- Parties --}}
    <div class="parties">
        <div class="party">
            <div class="party-label">From (Supplier)</div>
            <div class="party-name">{{ config('hmo.company_name') ?: config('app.name') }}</div>
            <div class="party-detail">
                @if(config('hmo.company_address')){{ config('hmo.company_address') }}<br>@endif
                @if(config('hmo.company_eik'))ЕИК: {{ config('hmo.company_eik') }}<br>@endif
                @if(config('hmo.company_vat'))ДДС №: {{ config('hmo.company_vat') }}@endif
            </div>
        </div>
        <div class="party" style="text-align:right">
            <div class="party-label">To (Customer)</div>
            <div class="party-name">{{ $tenant->name }}</div>
            <div class="party-detail">
                @if($tenant->vat_number)ДДС №: {{ $tenant->vat_number }}<br>@endif
                @if($tenant->eik)ЕИК: {{ $tenant->eik }}<br>@endif
                @if($tenant->email){{ $tenant->email }}@endif
            </div>
        </div>
    </div>

    {{-- Line items --}}
    <table>
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
    <div class="totals">
        <div class="totals-row">
            <span>Subtotal (excl. VAT)</span>
            <span>€{{ number_format($plan->price, 2) }}</span>
        </div>
        <div class="totals-row">
            <span>VAT (0%)</span>
            <span>€0.00</span>
        </div>
        <div class="totals-total">
            <span>TOTAL DUE</span>
            <span>€{{ number_format($plan->price, 2) }}</span>
        </div>
    </div>

    {{-- Payment instructions --}}
    <div class="payment-section">
        <div class="payment-title">Payment Instructions / Платежни инструкции</div>
        <div class="payment-row">
            <span class="payment-label">Bank / Банка:</span>
            <span class="payment-value">{{ config('hmo.bank_name') ?: '—' }}</span>
        </div>
        <div class="payment-row">
            <span class="payment-label">IBAN:</span>
            <span class="payment-value">{{ config('hmo.bank_iban') ?: '—' }}</span>
        </div>
        <div class="payment-row">
            <span class="payment-label">BIC/SWIFT:</span>
            <span class="payment-value">{{ config('hmo.bank_bic') ?: '—' }}</span>
        </div>
        <div class="payment-row">
            <span class="payment-label">Amount / Сума:</span>
            <span class="payment-value">€{{ number_format($plan->price, 2) }}</span>
        </div>
        <div class="payment-row">
            <span class="payment-label">Reference / Основание:</span>
            <span class="payment-value">
                <span class="reference-highlight">{{ $paymentReference }}</span>
            </span>
        </div>
    </div>

    <div class="footer">
        This is a proforma invoice. It is not a VAT invoice. Subscription is activated upon confirmed payment receipt.<br>
        Това е проформа фактура. Не е данъчен документ по ЗДДС.
    </div>
</div>
</body>
</html>
