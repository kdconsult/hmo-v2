# Plan: Per-Country PDF Rewrite — Invoice + Credit Note + Debit Note

> **Task:** `tasks/vat-vies/pdf-rewrite.md`
> **Ships with:** `tasks/vat-vies/domestic-exempt-plan.md` — single branch / single PR. The shared `VatScenario::DomesticExempt` enum case and the `vat_scenario_sub_code` column are emitted here because the templates cannot render without them; scenario semantics (form toggle, service routing) live in the other plan.
> **Review:** `tasks/vat-vies/review.md` findings F-001, F-002, F-004, F-013, F-023, F-028, F-029
> **Status:** 📋 PLANNED

---

## Prerequisites

Before executing this plan:

- `hotfix.md` must be shipped (already ✅). Supplies `country_code` NOT NULL, invoice immutability guard, `reverse_charge_manual_override` column family.
- `legal-references.md` must be shipped (already ✅). Supplies `vat_legal_references` table, `VatLegalReference` model with `resolve()` / `listForScenario()`, and the 16-row Bulgarian seed covering exempt / domestic_exempt (art_39..art_49) / eu_b2b_reverse_charge (goods, services) / non_eu_export (goods, services).
- DomPDF + DejaVu Sans are already wired (`barryvdh/laravel-dompdf` installed, existing flat template uses it). No renderer change.
- Tenant row exposes `country_code` and `locale` (stancl/tenancy landlord tenant); both are used by `PdfTemplateResolver`.

---

## Step 1 — `supplied_at` migration + model cast (customer_invoices only)

**Migration:** `database/migrations/tenant/YYYY_MM_DD_HHMMSS_add_supplied_at_to_customer_invoices_table.php`

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_invoices', function (Blueprint $table): void {
            $table->date('supplied_at')->nullable()->after('issued_at');
        });
    }

    public function down(): void
    {
        Schema::table('customer_invoices', function (Blueprint $table): void {
            $table->dropColumn('supplied_at');
        });
    }
};
```

**Model edit:** `app/Models/CustomerInvoice.php`

- Add `'supplied_at'` to `$fillable`.
- Add `'supplied_at' => 'date'` in `$casts`.

**Why:** Art. 226(7) Directive 2006/112/EC requires "date of chargeable event / date of supply" on every invoice when it differs from the issue date. Column is on `customer_invoices` only — credit / debit notes inherit by reading `$note->customerInvoice->supplied_at` at render time (no duplication, source of truth is the parent invoice).

---

## Step 2 — `vat_scenario_sub_code` migration + backfill (all three tables)

**Migration:** `database/migrations/tenant/YYYY_MM_DD_HHMMSS_add_vat_scenario_sub_code_to_invoice_family.php`

One migration file, loops over the three tables. Backfill rules:

- `exempt` → `'default'` (one seeded row at sub_code=`default`)
- `eu_b2b_reverse_charge` → `'goods'` (two seeded rows: `goods`, `services`; goods is the `is_default = true` row)
- `non_eu_export` → `'goods'` (same reasoning)
- `domestic`, `eu_b2c_under_threshold`, `eu_b2c_over_threshold` → `null` (no legal reference row needed; no zero-rate)

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'customer_invoices',
        'customer_credit_notes',
        'customer_debit_notes',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->string('vat_scenario_sub_code')->nullable()->after('vat_scenario');
            });

            DB::table($table)->where('vat_scenario', 'exempt')
                ->update(['vat_scenario_sub_code' => 'default']);

            DB::table($table)->where('vat_scenario', 'eu_b2b_reverse_charge')
                ->update(['vat_scenario_sub_code' => 'goods']);

            DB::table($table)->where('vat_scenario', 'non_eu_export')
                ->update(['vat_scenario_sub_code' => 'goods']);
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->dropColumn('vat_scenario_sub_code');
            });
        }
    }
};
```

**Model edits:**

- `app/Models/CustomerInvoice.php` — add `'vat_scenario_sub_code'` to `$fillable`.
- `app/Models/CustomerCreditNote.php` — add `'vat_scenario_sub_code'` to `$fillable`.
- `app/Models/CustomerDebitNote.php` — add `'vat_scenario_sub_code'` to `$fillable`.

**Why:** Legal-reference lookup in `vat_legal_references` is keyed on `(country_code, vat_scenario, sub_code)`. The sub-code distinguishes goods vs services for reverse-charge / non-EU export and stores the specific чл. 39–49 article for DomesticExempt. Backfill is rules-based, not data-driven — existing rows get the `is_default = true` sub-code for their scenario. Create-time inheritance on credit / debit notes is owned by `invoice-credit-debit.md`; this push only adds the column + backfill so the template can render today.

---

## Step 3 — `VatScenario::DomesticExempt` enum case

**Edit:** `app/Enums/VatScenario.php`

- Add `case DomesticExempt = 'domestic_exempt';` between `Domestic` and `EuB2bReverseCharge`.
- Extend `description()`:

  ```php
  self::DomesticExempt => 'Domestic exemption — zero-rated under a specific ЗДДС article (39–49).',
  ```

- Extend `requiresVatRateChange()` to return `true` for `DomesticExempt`:

  ```php
  self::Exempt, self::DomesticExempt, self::EuB2bReverseCharge, self::EuB2cOverThreshold, self::NonEuExport => true,
  ```

- Add a PHPDoc comment **above** `public static function determine(...)` noting that `DomesticExempt` is **user-selected only** — the method is not modified; routing happens in `CustomerInvoiceService` (owned by `domestic-exempt-plan.md`):

  ```php
  /**
   * Note: VatScenario::DomesticExempt is intentionally NOT emitted by this method.
   * It is user-selected on the draft form via an explicit toggle and routed into
   * confirmWithScenario() separately. determine() treats domestic partners as
   * Domestic even when the article picker is showing.
   *
   * @throws ...
   */
  ```

**Why:** Shared dependency with `domestic-exempt-plan.md`. The BG Blade template must be able to render the чл. 39–49 legal-reference line, which requires the enum case to exist. Behaviour stays off in `determine()`; the other plan turns the toggle on.

---

## Step 4 — Translation files (bg, en)

### `lang/bg/invoice-pdf.php`

```php
<?php

return [
    'heading' => [
        'invoice'     => 'ФАКТУРА',
        'credit_note' => 'КРЕДИТНО ИЗВЕСТИЕ',
        'debit_note'  => 'ДЕБИТНО ИЗВЕСТИЕ',
    ],
    'no'                 => '№',
    'date_of_issue'      => 'Дата на издаване',
    'date_of_supply'     => 'Дата на данъчно събитие',
    'due_date'           => 'Падеж',
    'parent_invoice'     => 'Фактура',
    'from_supplier'      => 'Доставчик',
    'to_customer'        => 'Получател',
    'vat_id'             => 'ДДС №',
    'eik'                => 'ЕИК',
    'email'              => 'E-mail',
    'vat_treatment'      => 'Режим по ДДС',
    'reverse_charge'     => 'Обратно начисляване — ДДС се начислява от получателя',
    'legal_basis'        => 'Правно основание',
    'vies_consultation'  => 'Справка VIES',
    'exempt_note'        => 'Освободена доставка',
    'description'        => 'Наименование',
    'sku'                => 'Код',
    'qty'                => 'Количество',
    'unit'               => 'Мярка',
    'unit_price'         => 'Ед. цена',
    'discount_percent'   => 'Отстъпка %',
    'vat'                => 'ДДС',
    'total'              => 'Сума',
    'subtotal'           => 'Данъчна основа',
    'net_at_rate'        => 'Основа при :rate%',
    'vat_at_rate'        => 'ДДС при :rate%',
    'discount'           => 'Отстъпка',
    'grand_total'        => 'Общо',
    'amount_paid'        => 'Платено',
    'amount_due'         => 'За плащане',
    'retention_notice'   => 'Тази фактура е издадена по реда на ЗДДС. Моля, съхранявайте я за нуждите на данъчен контрол.',
    'page'               => 'Стр. :n от :total',
];
```

### `lang/en/invoice-pdf.php`

```php
<?php

return [
    'heading' => [
        'invoice'     => 'INVOICE',
        'credit_note' => 'CREDIT NOTE',
        'debit_note'  => 'DEBIT NOTE',
    ],
    'no'                 => 'No',
    'date_of_issue'      => 'Date of issue',
    'date_of_supply'     => 'Date of supply',
    'due_date'           => 'Due date',
    'parent_invoice'     => 'Invoice',
    'from_supplier'      => 'From (Supplier)',
    'to_customer'        => 'To (Customer)',
    'vat_id'             => 'VAT ID',
    'eik'                => 'Reg. No',
    'email'              => 'Email',
    'vat_treatment'      => 'VAT treatment',
    'reverse_charge'     => 'Reverse charge — VAT accounted for by the recipient',
    'legal_basis'        => 'Legal basis',
    'vies_consultation'  => 'VIES consultation',
    'exempt_note'        => 'Exempt supply',
    'description'        => 'Description',
    'sku'                => 'SKU',
    'qty'                => 'Qty',
    'unit'               => 'Unit',
    'unit_price'         => 'Unit price',
    'discount_percent'   => 'Discount %',
    'vat'                => 'VAT',
    'total'              => 'Total',
    'subtotal'           => 'Subtotal',
    'net_at_rate'        => 'Net at :rate%',
    'vat_at_rate'        => 'VAT at :rate%',
    'discount'           => 'Discount',
    'grand_total'        => 'Grand total',
    'amount_paid'        => 'Paid',
    'amount_due'         => 'Amount due',
    'retention_notice'   => 'This is a VAT invoice. Please retain for your records.',
    'page'               => 'Page :n of :total',
];
```

**Why:** Every string on every new template goes through `__()`. BG template forces `bg` locale (statutory НАП wording); default template renders whatever `tenants.locale` resolves to (English as the shipped fallback, more locales added per market).

---

## Step 5 — `App\Services\PdfTemplateResolver`

**File:** `app/Services/PdfTemplateResolver.php`

```php
<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\View;

class PdfTemplateResolver
{
    public function resolve(string $docType, ?string $countryCode = null): string
    {
        $country = strtolower((string) ($countryCode ?? tenancy()->tenant?->country_code ?? ''));
        $candidate = "pdf.{$docType}.{$country}";

        if ($country !== '' && View::exists($candidate)) {
            return $candidate;
        }

        return "pdf.{$docType}.default";
    }

    public function localeFor(string $docType, ?string $countryCode = null): string
    {
        $country = strtolower((string) ($countryCode ?? tenancy()->tenant?->country_code ?? ''));

        if ($country !== '' && View::exists("pdf.{$docType}.{$country}")) {
            return $country;
        }

        return tenancy()->tenant?->locale
            ?? (string) config('app.fallback_locale', 'en');
    }
}
```

### Unit test: `tests/Unit/PdfTemplateResolverTest.php`

Skeleton (use dataset, stub `tenancy()->tenant`):

```php
<?php

declare(strict_types=1);

use App\Services\PdfTemplateResolver;

it('returns bg template for bg tenant', function (): void {
    tenancy()->tenant->update(['country_code' => 'BG', 'locale' => 'bg']);

    expect(app(PdfTemplateResolver::class)->resolve('customer-invoice'))
        ->toBe('pdf.customer-invoice.bg');
});

it('falls back to default when no country template exists', function (): void {
    tenancy()->tenant->update(['country_code' => 'DE', 'locale' => 'en']);

    expect(app(PdfTemplateResolver::class)->resolve('customer-invoice'))
        ->toBe('pdf.customer-invoice.default');
});

it('forces bg locale when bg template is selected', function (): void {
    tenancy()->tenant->update(['country_code' => 'BG', 'locale' => 'en']);

    expect(app(PdfTemplateResolver::class)->localeFor('customer-invoice'))->toBe('bg');
});

it('uses tenant locale on default template', function (): void {
    tenancy()->tenant->update(['country_code' => 'DE', 'locale' => 'de']);

    expect(app(PdfTemplateResolver::class)->localeFor('customer-invoice'))->toBe('de');
});
```

**Why:** Single choke-point for "which view, which locale". Every print action calls it. Fallback chain is (1) country template exists → that template + that locale; (2) otherwise → `default` template + `tenants.locale` or configured fallback. Never throws — `default` template is always shipped.

---

## Step 6 — Shared Blade components

Seven files under `resources/views/pdf/components/`. Each doc-type × country template `@include`s the same components; differences (heading key, parent-invoice line) are passed in as `@include` data.

### `_styles.blade.php`

Carry the existing flat template's `<style>` block verbatim (DejaVu Sans, `.page` padding, `.header`, `.parties`, `.meta-box`, `table.items`, `table.totals`, `.footer`). Single source of CSS truth.

```blade
<style>
    * { box-sizing: border-box; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1a1a1a; margin: 0; padding: 0; }
    .page { padding: 40px 48px; }
    /* ...carry existing header / parties / meta-box / items / totals / footer CSS verbatim... */
    .legal-basis { margin-top: 12px; padding: 10px 14px; background: #f3f4f6; border-left: 3px solid #6b7280; font-size: 11px; }
    .legal-basis strong { color: #111827; }
</style>
```

### `_header.blade.php`

Props consumed: `$heading` (translation key suffix, e.g. `'invoice'`), `$number`, `$issuedAt`, `$suppliedAt`, `$dueDate`, `$parentInvoice` (nullable — credit/debit only).

```blade
<div class="header">
    <table>
        <tr>
            <td class="header-left">
                <div class="company-name">{{ tenant('name') ?: config('app.name') }}</div>
                <div class="company-detail">
                    @if(tenant('eik'))
                        {{ __('invoice-pdf.eik') }}: {{ tenant('eik') }}<br>
                    @endif
                    @if(tenant('vat_number'))
                        {{ __('invoice-pdf.vat_id') }}: {{ tenant('vat_number') }}<br>
                    @endif
                    @if(tenant('email')){{ tenant('email') }}@endif
                </div>
            </td>
            <td class="header-right">
                <div class="document-title">{{ __('invoice-pdf.heading.' . $heading) }}</div>
                <div class="document-meta">{{ __('invoice-pdf.no') }}: {{ $number }}</div>
                <div class="document-meta">{{ __('invoice-pdf.date_of_issue') }}: {{ $issuedAt?->format('d.m.Y') }}</div>
                @if($suppliedAt && $issuedAt && ! $suppliedAt->equalTo($issuedAt))
                    <div class="document-meta">{{ __('invoice-pdf.date_of_supply') }}: {{ $suppliedAt->format('d.m.Y') }}</div>
                @endif
                @if($dueDate)
                    <div class="document-meta">{{ __('invoice-pdf.due_date') }}: {{ $dueDate->format('d.m.Y') }}</div>
                @endif
                @if($parentInvoice)
                    <div class="document-meta">{{ __('invoice-pdf.parent_invoice') }}: {{ $parentInvoice }}</div>
                @endif
            </td>
        </tr>
    </table>
</div>
```

### `_parties.blade.php`

Props: `$partner`. Supplier renders from `tenant(...)`; customer renders from partner + its billing / default address (fallback rule below).

```blade
@php
    $billingAddress = $partner->addresses->firstWhere('is_billing', true)
        ?? $partner->addresses->firstWhere('is_default', true)
        ?? $partner->addresses->first();
@endphp
<div class="parties">
    <table>
        <tr>
            <td class="party-cell">
                <div class="party-label">{{ __('invoice-pdf.from_supplier') }}</div>
                <div class="party-name">{{ tenant('name') ?: config('app.name') }}</div>
                <div class="party-detail">
                    @if(tenant('address_line_1')){{ tenant('address_line_1') }}<br>@endif
                    @if(tenant('postal_code') || tenant('city')){{ tenant('postal_code') }} {{ tenant('city') }}<br>@endif
                    @if(tenant('country_code')){{ tenant('country_code') }}<br>@endif
                    @if(tenant('eik')){{ __('invoice-pdf.eik') }}: {{ tenant('eik') }}<br>@endif
                    @if(tenant('vat_number')){{ __('invoice-pdf.vat_id') }}: {{ tenant('vat_number') }}<br>@endif
                    @if(tenant('email')){{ tenant('email') }}@endif
                </div>
            </td>
            <td class="party-cell-right">
                <div class="party-label">{{ __('invoice-pdf.to_customer') }}</div>
                <div class="party-name">{{ $partner->company_name ?: $partner->name }}</div>
                <div class="party-detail">
                    @if($billingAddress)
                        {{ $billingAddress->address_line_1 }}<br>
                        @if($billingAddress->address_line_2){{ $billingAddress->address_line_2 }}<br>@endif
                        {{ $billingAddress->postal_code }} {{ $billingAddress->city }}<br>
                        {{ $billingAddress->country_code }}<br>
                    @endif
                    @if($partner->vat_number){{ __('invoice-pdf.vat_id') }}: {{ $partner->vat_number }}<br>@endif
                    @if($partner->eik){{ __('invoice-pdf.eik') }}: {{ $partner->eik }}<br>@endif
                    @if($partner->email){{ $partner->email }}@endif
                </div>
            </td>
        </tr>
    </table>
</div>
```

### `_vat-treatment.blade.php`  ← the heart of the rewrite

Props: `$vatScenario` (VatScenario enum or string), `$subCode`, `$isReverseCharge`, `$viesRequestId`, `$viesCheckedAt`, `$tenantCountry`.

```blade
@php
    use App\Models\VatLegalReference;

    $scenarioValue = is_object($vatScenario) ? $vatScenario->value : $vatScenario;
    $legalRef = null;

    try {
        $legalRef = VatLegalReference::resolve($tenantCountry, $scenarioValue, $subCode ?? 'default');
    } catch (\DomainException) {
        // No legal-reference row — domestic / eu_b2c_* scenarios; render nothing.
    }
@endphp

@if($isReverseCharge)
    <div class="legal-basis">
        <strong>{{ __('invoice-pdf.vat_treatment') }}:</strong>
        {{ __('invoice-pdf.reverse_charge') }}
        @if($legalRef)
            <br><strong>{{ __('invoice-pdf.legal_basis') }}:</strong> {{ $legalRef->legal_reference }}
            @if($legalRef->description){{ ' — ' }}{{ $legalRef->description }}@endif
        @endif
        @if($viesRequestId)
            <br><strong>{{ __('invoice-pdf.vies_consultation') }}:</strong>
            {{ $viesRequestId }}@if($viesCheckedAt){{ ' — ' }}{{ $viesCheckedAt->format('d.m.Y H:i') }} UTC @endif
        @endif
    </div>
@elseif($legalRef)
    <div class="legal-basis">
        <strong>{{ __('invoice-pdf.legal_basis') }}:</strong> {{ $legalRef->legal_reference }}
        @if($legalRef->description){{ ' — ' }}{{ $legalRef->description }}@endif
    </div>
@endif
```

### `_items-table.blade.php`

Props: `$items`. Straight carry-over from the flat template's `<table class="items">` block, with labels routed through `__()`.

```blade
<table class="items">
    <thead>
        <tr>
            <th style="width:36%">{{ __('invoice-pdf.description') }}</th>
            <th class="text-right" style="width:10%">{{ __('invoice-pdf.sku') }}</th>
            <th class="text-right" style="width:10%">{{ __('invoice-pdf.qty') }}</th>
            <th class="text-right" style="width:12%">{{ __('invoice-pdf.unit_price') }}</th>
            <th class="text-right" style="width:8%">{{ __('invoice-pdf.discount_percent') }}</th>
            <th class="text-right" style="width:10%">{{ __('invoice-pdf.vat') }}</th>
            <th class="text-right" style="width:14%">{{ __('invoice-pdf.total') }}</th>
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
```

### `_totals.blade.php`  ← F-002 per-rate breakdown

Props: `$document` (has `subtotal`, `tax_amount`, `total`, `amount_paid`, `amount_due`, `discount_amount`, `currency_code`, `items`).

Groups items by VAT rate (rounded to 2dp so 20.00 and 20 don't split). One net-at-rate row + one VAT-at-rate row per distinct rate.

```blade
@php
    $itemsByRate = $document->items->groupBy(fn ($i) => number_format((float) ($i->vatRate?->rate ?? 0), 2, '.', ''));
@endphp
<div class="totals-wrapper">
    <table class="totals">
        <tr>
            <td class="totals-label">{{ __('invoice-pdf.subtotal') }}:</td>
            <td class="totals-value">{{ number_format((float) $document->subtotal, 2) }} {{ $document->currency_code }}</td>
        </tr>

        @if(bccomp((string) $document->discount_amount, '0', 2) > 0)
            <tr>
                <td class="totals-label">{{ __('invoice-pdf.discount') }}:</td>
                <td class="totals-value">- {{ number_format((float) $document->discount_amount, 2) }} {{ $document->currency_code }}</td>
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
                <td class="totals-value">{{ number_format($netAtRate, 2) }} {{ $document->currency_code }}</td>
            </tr>
            <tr>
                <td class="totals-label">{{ __('invoice-pdf.vat_at_rate', ['rate' => $rateLabel]) }}:</td>
                <td class="totals-value">{{ number_format($vatAtRate, 2) }} {{ $document->currency_code }}</td>
            </tr>
        @endforeach

        <tr class="totals-grand">
            <td class="totals-label">{{ __('invoice-pdf.grand_total') }}:</td>
            <td class="totals-value">{{ number_format((float) $document->total, 2) }} {{ $document->currency_code }}</td>
        </tr>

        @if(bccomp((string) ($document->amount_paid ?? '0'), '0', 2) > 0)
            <tr>
                <td class="totals-label">{{ __('invoice-pdf.amount_paid') }}:</td>
                <td class="totals-value">{{ number_format((float) $document->amount_paid, 2) }} {{ $document->currency_code }}</td>
            </tr>
            <tr class="totals-due">
                <td class="totals-label">{{ __('invoice-pdf.amount_due') }}:</td>
                <td class="totals-value">{{ number_format((float) $document->amount_due, 2) }} {{ $document->currency_code }}</td>
            </tr>
        @endif
    </table>
</div>
```

### `_footer.blade.php`

```blade
<div class="footer">{{ __('invoice-pdf.retention_notice') }}</div>
```

---

## Step 7 — Invoice templates (default + bg)

### `resources/views/pdf/customer-invoice/default.blade.php`

```blade
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    @include('pdf.components._styles')
</head>
<body>
<div class="page">
    @include('pdf.components._header', [
        'heading'       => 'invoice',
        'number'        => $invoice->invoice_number,
        'issuedAt'      => $invoice->issued_at,
        'suppliedAt'    => $invoice->supplied_at,
        'dueDate'       => $invoice->due_date,
        'parentInvoice' => null,
    ])
    @include('pdf.components._parties', ['partner' => $invoice->partner])
    @include('pdf.components._vat-treatment', [
        'vatScenario'     => $invoice->vat_scenario,
        'subCode'         => $invoice->vat_scenario_sub_code,
        'isReverseCharge' => $invoice->is_reverse_charge,
        'viesRequestId'   => $invoice->vies_request_id,
        'viesCheckedAt'   => $invoice->vies_checked_at,
        'tenantCountry'   => tenancy()->tenant?->country_code,
    ])
    @include('pdf.components._items-table', ['items' => $invoice->items])
    @include('pdf.components._totals', ['document' => $invoice])
    @include('pdf.components._footer')
</div>
</body>
</html>
```

### `resources/views/pdf/customer-invoice/bg.blade.php`

v1 structurally identical to `default.blade.php`. Differentiation happens at the `localeFor()` layer (forces `bg`) and via components that render localized strings. Both files are kept so future per-country divergence (e.g. НАП-specific QR code, fiscal signature block) has a clean home.

```blade
<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="UTF-8">
    @include('pdf.components._styles')
</head>
<body>
<div class="page">
    @include('pdf.components._header', [
        'heading'       => 'invoice',
        'number'        => $invoice->invoice_number,
        'issuedAt'      => $invoice->issued_at,
        'suppliedAt'    => $invoice->supplied_at,
        'dueDate'       => $invoice->due_date,
        'parentInvoice' => null,
    ])
    @include('pdf.components._parties', ['partner' => $invoice->partner])
    @include('pdf.components._vat-treatment', [
        'vatScenario'     => $invoice->vat_scenario,
        'subCode'         => $invoice->vat_scenario_sub_code,
        'isReverseCharge' => $invoice->is_reverse_charge,
        'viesRequestId'   => $invoice->vies_request_id,
        'viesCheckedAt'   => $invoice->vies_checked_at,
        'tenantCountry'   => tenancy()->tenant?->country_code,
    ])
    @include('pdf.components._items-table', ['items' => $invoice->items])
    @include('pdf.components._totals', ['document' => $invoice])
    @include('pdf.components._footer')
</div>
</body>
</html>
```

---

## Step 8 — Credit-note templates

Same component-include pattern; heading key `credit_note`; passes parent invoice number in the header meta; reads the parent's `supplied_at` + scenario subcode.

### `resources/views/pdf/customer-credit-note/default.blade.php`

```blade
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    @include('pdf.components._styles')
</head>
<body>
<div class="page">
    @include('pdf.components._header', [
        'heading'       => 'credit_note',
        'number'        => $note->note_number,
        'issuedAt'      => $note->issued_at,
        'suppliedAt'    => $note->customerInvoice?->supplied_at,
        'dueDate'       => null,
        'parentInvoice' => $note->customerInvoice?->invoice_number,
    ])
    @include('pdf.components._parties', ['partner' => $note->partner])
    @include('pdf.components._vat-treatment', [
        'vatScenario'     => $note->vat_scenario,
        'subCode'         => $note->vat_scenario_sub_code,
        'isReverseCharge' => $note->is_reverse_charge,
        'viesRequestId'   => $note->customerInvoice?->vies_request_id,
        'viesCheckedAt'   => $note->customerInvoice?->vies_checked_at,
        'tenantCountry'   => tenancy()->tenant?->country_code,
    ])
    @include('pdf.components._items-table', ['items' => $note->items])
    @include('pdf.components._totals', ['document' => $note])
    @include('pdf.components._footer')
</div>
</body>
</html>
```

### `resources/views/pdf/customer-credit-note/bg.blade.php`

Same structure, `<html lang="bg">`. Headers / VIES / totals all go through components.

**Amount convention:** credit-note amounts are stored and rendered **positive**. НАП / tax-authority convention: the sign is implied by the heading ("КРЕДИТНО ИЗВЕСТИЕ" = deduction). Do not prepend minus signs to the totals.

---

## Step 9 — Debit-note templates

Mirror the credit-note files exactly, swap heading key to `debit_note`. Both `default.blade.php` and `bg.blade.php` under `resources/views/pdf/customer-debit-note/`. Parent-invoice number rendered the same way. No sign flip (debit adds, heading says so).

---

## Step 10 — Service guards (F-023, F-028)

**Edit:** `app/Services/CustomerInvoiceService.php::confirmWithScenario()`. Do **NOT** touch the signature — `domestic-exempt-plan.md` will extend it separately. Add the two guards inline, after the Draft-status check, before the `DB::transaction` block.

Add these imports at the top of the file if missing:

```php
use App\Enums\VatScenario;
use App\Services\CompanySettings;
use DomainException;
use Filament\Notifications\Notification;
```

### Inline guards

```php
// Inside confirmWithScenario(), after the Draft-status guard, before DB::transaction:

// F-023: reverse-charge requires tenant VAT number.
if ($this->wouldBecomeReverseCharge($invoice, $treatAsB2c) && empty(tenancy()->tenant?->vat_number)) {
    throw new DomainException(
        'Cannot issue a reverse-charge invoice: tenant VAT number is not configured. '.
        'Set it in Company Settings before confirming.'
    );
}

// F-028: 5-day issuance rule warning (non-blocking).
$supplied = $invoice->supplied_at ?? $invoice->issued_at;
if ($invoice->issued_at && $supplied && $invoice->issued_at->diffInDays($supplied) > 5) {
    Notification::make()
        ->title(__('invoice-form.late_issuance_title'))
        ->body(__('invoice-form.late_issuance_body'))
        ->warning()
        ->send();
}
```

### Helper

```php
private function wouldBecomeReverseCharge(CustomerInvoice $invoice, bool $treatAsB2c): bool
{
    if ($treatAsB2c) {
        return false;
    }

    $tenantCountry = CompanySettings::get('company', 'country_code');
    if (empty($tenantCountry)) {
        return false;
    }

    $invoice->loadMissing('partner');

    try {
        $scenario = VatScenario::determine(
            $invoice->partner,
            $tenantCountry,
            ignorePartnerVat: false,
            tenantIsVatRegistered: (bool) tenancy()->tenant?->is_vat_registered,
        );
    } catch (\DomainException) {
        return false;
    }

    return $scenario === VatScenario::EuB2bReverseCharge;
}
```

**Why:** F-023 blocks the VIES audit-trail gap — a tenant without its own VAT number that reaches `EuB2bReverseCharge` produces a null `vies_request_id` and legally invalid paperwork. F-028 surfaces чл. 113, ал. 4 ЗДДС (5-day rule) without blocking — users occasionally backdate legitimately (monthly recurring, missed billing), but they must be reminded. Notification is dispatched from the service so any call site (Filament action, API, import) sees it; in Filament context it surfaces automatically, other contexts are no-ops.

---

## Step 11 — Form updates (`supplied_at` DatePicker)

**Edit:** `app/Filament/Resources/CustomerInvoices/Schemas/CustomerInvoiceForm.php`

- Add a `Filament\Forms\Components\DatePicker::make('supplied_at')` directly after the `issued_at` field.
- Default via `->default(fn (Get $get) => $get('issued_at') ?? now())`.
- Label + helperText from `lang/{bg,en}/invoice-form.php`.
- Nullable — leaving blank stores `null`; PDF resolver falls back to `issued_at` at render time.

Schema snippet:

```php
DatePicker::make('supplied_at')
    ->label(__('invoice-form.date_of_supply'))
    ->helperText(__('invoice-form.date_of_supply_hint'))
    ->default(fn (Get $get) => $get('issued_at') ?? now())
    ->nullable(),
```

**Add these translation keys** to both `lang/bg/invoice-form.php` and `lang/en/invoice-form.php`:

```php
'date_of_supply'      => '...',   // BG: Дата на данъчно събитие | EN: Date of supply
'date_of_supply_hint' => '...',   // BG: Дата на възникване на данъчното събитие (чл. 25 ЗДДС). По подразбиране съвпада с датата на издаване.
                                  // EN: Date on which the chargeable event occurs (Art. 63 Directive). Defaults to the issue date.
'late_issuance_title' => '...',   // BG: Късно издаване | EN: Late issuance
'late_issuance_body'  => '...',   // BG: Фактурата е издадена повече от 5 дни след данъчното събитие. Проверете съответствието с чл. 113, ал. 4 ЗДДС.
                                  // EN: Invoice issued more than 5 days after the supply date. Review against Art. 113(4) VAT Act.
```

**Not in scope:** the DomesticExempt toggle + Art. 39–49 picker are owned by `domestic-exempt-plan.md`. This push only ships `supplied_at` on the invoice form.

---

## Step 12 — Print-action rewiring

### `ViewCustomerInvoice::print_invoice`

**Edit:** `app/Filament/Resources/CustomerInvoices/Pages/ViewCustomerInvoice.php`

Required imports:

```php
use App\Services\PdfTemplateResolver;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
```

Replace the existing `print_invoice` action body:

```php
Action::make('print_invoice')
    ->label(__('invoice-form.print'))
    ->icon(Heroicon::OutlinedPrinter)
    ->color('gray')
    ->visible(fn (CustomerInvoice $record): bool => $record->status === DocumentStatus::Confirmed)
    ->action(function (CustomerInvoice $record) {
        $record->loadMissing(['partner.addresses', 'items.productVariant', 'items.vatRate']);

        $resolver = app(PdfTemplateResolver::class);
        $view = $resolver->resolve('customer-invoice');
        $locale = $resolver->localeFor('customer-invoice');

        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            return response()->streamDownload(
                function () use ($view, $record) {
                    $pdf = Pdf::loadView($view, ['invoice' => $record]);
                    echo $pdf->output();
                },
                "invoice-{$record->invoice_number}.pdf"
            );
        } finally {
            app()->setLocale($previous);
        }
    }),
```

### `ViewCustomerCreditNote::print_credit_note` (NEW)

**Edit:** `app/Filament/Resources/CustomerCreditNotes/Pages/ViewCustomerCreditNote.php`

Same pattern, `resolve('customer-credit-note')`, eager-load `['partner.addresses', 'items.productVariant', 'items.vatRate', 'customerInvoice']`, view data key `'note' => $record`, filename `credit-note-{$record->note_number}.pdf`.

### `ViewCustomerDebitNote::print_debit_note` (NEW)

**Edit:** `app/Filament/Resources/CustomerDebitNotes/Pages/ViewCustomerDebitNote.php`

Same as credit note with `resolve('customer-debit-note')` and filename `debit-note-{$record->note_number}.pdf`.

### Delete the legacy template

Remove `resources/views/pdf/customer-invoice.blade.php` (the old flat template). After all three call sites route through the resolver, no code references it.

**Why:** `try/finally` around `setLocale` is mandatory — if the download throws after locale flip, subsequent requests in the same worker (Octane / queue) would inherit the wrong locale. The `finally` guarantees restoration.

---

## Step 13 — Tests

### `tests/Unit/PdfTemplateResolverTest.php`

See Step 5 skeleton. Four cases: bg-returns-bg, de-falls-back-to-default, bg-locale-forced, de-uses-tenant-locale.

### `tests/Feature/Pdf/InvoiceTemplateRenderTest.php`

Helper in the test file:

```php
use App\Services\PdfTemplateResolver;
use Illuminate\Support\Facades\View;

function renderInvoiceHtml($invoice): string
{
    $invoice->loadMissing(['partner.addresses', 'items.productVariant', 'items.vatRate']);

    $resolver = app(PdfTemplateResolver::class);
    $view = $resolver->resolve('customer-invoice');
    $locale = $resolver->localeFor('customer-invoice');

    $previous = app()->getLocale();
    app()->setLocale($locale);

    try {
        return View::make($view, ['invoice' => $invoice])->render();
    } finally {
        app()->setLocale($previous);
    }
}
```

Cases (one `it()` each):

- **BG tenant → heading ФАКТУРА:** create BG tenant, confirmed domestic invoice, assert `Str::contains($html, 'ФАКТУРА')`.
- **Non-BG (simulated DE) tenant → NOT ФАКТУРА:** simulate DE by setting `country_code = 'DE'`; assert `INVOICE` present and `ФАКТУРА` absent.
- **Reverse-charge goods renders "Обратно начисляване" + Art. 138:** BG tenant, EU B2B partner with confirmed VIES VAT, `sub_code = 'goods'`; assert BG translation of reverse-charge + `Art. 138 Directive 2006/112/EC`.
- **Exempt renders чл. 113, ал. 9:** tenant `is_vat_registered = false`, confirmed invoice; assert `чл. 113, ал. 9 ЗДДС` present.
- **DomesticExempt with sub_code=art_45 renders чл. 45:** set `vat_scenario = domestic_exempt`, `vat_scenario_sub_code = 'art_45'`; assert `чл. 45 ЗДДС`.
- **Non-EU goods → Art. 146:** partner country `'US'`, `sub_code = 'goods'`; assert `Art. 146 Directive 2006/112/EC`.
- **Non-EU services → Art. 44 outside-scope:** partner country `'US'`, `sub_code = 'services'`; assert `Art. 44 Directive 2006/112/EC (outside scope of EU VAT)`.
- **Per-rate breakdown on mixed rates:** two line items (one 20%, one 9%); assert two distinct net-at-rate rows + two distinct VAT-at-rate rows; assert sum of per-rate VAT = stored `tax_amount`.
- **`supplied_at` distinct → both dates render:** `issued_at = 2026-04-17`, `supplied_at = 2026-04-10`; assert both formatted dates appear.
- **`supplied_at` null → only issue date:** `supplied_at = null`; assert only issue-date meta row present; `Date of supply` label absent.
- **VIES request_id rendered on reverse-charge:** set `vies_request_id = 'WAPIAAAAX000000'`; assert that string appears inside the reverse-charge block.

### `tests/Feature/Pdf/CreditNoteTemplateRenderTest.php`

- **КРЕДИТНО ИЗВЕСТИЕ heading** on BG tenant.
- **Parent-invoice legal reference inherited:** parent invoice was reverse-charge `eu_b2b_reverse_charge` + `sub_code = 'goods'`; note carries the same `vat_scenario` + `sub_code`; assert `Art. 138 Directive 2006/112/EC` appears on the note PDF, and parent invoice number renders in the header meta block.

### `tests/Feature/Pdf/DebitNoteTemplateRenderTest.php`

Mirror of credit-note: ДЕБИТНО ИЗВЕСТИЕ heading + parent-invoice inheritance.

### `tests/Feature/Invoice/InvoiceConfirmationGuardsTest.php`

- **Refuses reverse-charge confirmation when tenant `vat_number` is null (F-023):** BG tenant with `vat_number = null` + `is_vat_registered = true`; partner in DE with confirmed VIES VAT; call `confirmWithScenario()`; expect `DomainException`; assert invoice still `Draft`.
- **5-day warning fires but does NOT block (F-028):** `issued_at = 2026-04-17`, `supplied_at = 2026-04-01` (16 days); assert confirmation succeeds (status → `Confirmed`) and `Notification::assertNotified()` warning with title matching `late_issuance_title`.
- **Regression lock for F-023:** successful reverse-charge confirmation stores a non-null `vies_request_id`.

### `tests/Feature/Pdf/CyrillicDomPdfSmokeTest.php`

- Render BG invoice through DomPDF (actual binary output, `Pdf::loadView(...)->output()`); assert byte length `> 1000` and binary starts with `%PDF-`.

---

## Gotchas / Load-Bearing Details

- **DomPDF + Cyrillic safe via DejaVu Sans.** The existing flat template already declares `font-family: DejaVu Sans`. DejaVu covers the full Bulgarian Cyrillic block including letters used in чл. wording (ЗДДС, Обратно начисляване). Do not swap the font family.
- **`setLocale` MUST be wrapped in `try/finally`.** Octane and queued render jobs reuse PHP workers; an unhandled exception between `setLocale('bg')` and the implicit return leaks the locale into the next request. Every print action, every render helper, every queue job follows the pattern.
- **`VatLegalReference::resolve()` throws on unseeded data.** `_vat-treatment.blade.php` catches `DomainException` silently — missing row means no legal-basis line, never a crash. Ship the seeder with Bulgarian rows (done in `legal-references.md`); other countries add rows as they onboard.
- **`itemsByRate` groups by rate rounded to 2dp.** `20` and `20.00` must hash to the same bucket; `number_format($rate, 2, '.', '')` guarantees this. Rate labels are stripped of trailing zeros for display only (`rtrim(rtrim(..., '0'), '.')`).
- **Per-rate sums must reconcile with stored `tax_amount`.** Test skeleton asserts this explicitly; if the assertion fires, the bug is in line-item save order (recalc must run before render), not the template.
- **Credit-note amounts stay positive.** Heading implies the sign; prepending `-` doubles the negation in accounting software that already interprets credit notes as deductions. This matches SAP / Odoo / Dynamics behaviour and НАП expectation.
- **`partner_addresses` fallback chain: `is_billing` → `is_default` → `first`.** Enforced in `_parties.blade.php`. A partner with zero addresses renders only VAT ID + email (no address lines) — that's fine at PDF time; address enforcement belongs to partner form validation, not here.
- **Legacy flat `customer-invoice.blade.php` is deleted.** Leaving it in the tree means a new call site might `Pdf::loadView('pdf.customer-invoice', ...)` and skip the resolver. Delete the file as the last step before Pint / tests.
- **Credit/Debit note create-time inheritance is owned by `invoice-credit-debit.md`.** This push adds the `vat_scenario_sub_code` column on both note tables and backfills it, but the services that COPY the parent invoice's scenario / sub-code onto a new note at creation time live in the other task. In v1 shipping of this task, sub_code on new notes is whatever the backfill left (or null) — the templates handle both cases gracefully (`_vat-treatment.blade.php` catches the DomainException when `resolve()` can't find a row).
- **Signature of `confirmWithScenario()` is unchanged.** `domestic-exempt-plan.md` extends it with a `$forcedSubCode` argument later. This push only adds the two guard statements + the private helper; any existing caller keeps working.
- **`CompanySettings::get('company', 'country_code')`** is the canonical way to read tenant country from the service layer; don't call `tenancy()->tenant->country_code` in `wouldBecomeReverseCharge()` — matches the existing `determineVatType()` pattern in the same file.
- **DB host is `hmo-postgres`.** All migrations run via `./vendor/bin/sail artisan migrate`. Tests run parallel.

---

## Exit Criteria

- [ ] All checklist items in `tasks/vat-vies/pdf-rewrite.md` ticked.
- [ ] `supplied_at` migrated on `customer_invoices`; `vat_scenario_sub_code` migrated on all three tables; backfill verified via `database-query` (no NULLs where a rule applies).
- [ ] `VatScenario::DomesticExempt` case exists; `requiresVatRateChange()` returns `true` for it; `determine()` unchanged + PHPDoc note added.
- [ ] `lang/bg/invoice-pdf.php` + `lang/en/invoice-pdf.php` present with every key in Step 4; `lang/{bg,en}/invoice-form.php` extended with the four new keys.
- [ ] `App\Services\PdfTemplateResolver` present with 4 passing unit tests.
- [ ] Seven shared components under `resources/views/pdf/components/` present; `_vat-treatment.blade.php` renders reverse-charge block + VIES ref + legal-basis line correctly.
- [ ] Six per-country template files under `resources/views/pdf/{customer-invoice,customer-credit-note,customer-debit-note}/{default,bg}.blade.php`.
- [ ] Legacy `resources/views/pdf/customer-invoice.blade.php` deleted.
- [ ] `CustomerInvoiceService::confirmWithScenario()` carries both guards; signature unchanged.
- [ ] `CustomerInvoiceForm` has `supplied_at` DatePicker defaulting to `issued_at`.
- [ ] Three print actions route through `PdfTemplateResolver` (invoice existing, credit/debit new), each wrapped in `try/finally` around `setLocale`.
- [ ] All feature + unit tests in Step 13 green under `./vendor/bin/sail artisan test --parallel --compact`.
- [ ] `vendor/bin/pint --dirty --format agent` clean.
- [ ] Browser-verified: BG tenant → each doc-type × each scenario → correct heading + legal basis + VIES ref (when applicable).
- [ ] Browser-verified: simulated DE tenant (country_code = 'DE') → each doc-type falls back to `default` template in tenant locale.
