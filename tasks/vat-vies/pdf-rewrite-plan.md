# Plan: Invoice PDF Rewrite

> **Task:** `tasks/vat-vies/pdf-rewrite.md`
> **Review:** `review.md` (F-001, F-002, F-013, F-023, F-028, F-029)
> **Status:** Ready to implement after `hotfix.md` + `legal-references.md`

---

## Prerequisites

- [ ] `hotfix.md` merged (country_code NOT NULL; immutability guard)
- [ ] `legal-references.md` merged (`vat_legal_references` table seeded per tenant; `VatLegalReference::resolve()` available)
- [ ] Verify whether tenant + partner have structured legal address (street / postcode / city). If not, Step 2 below creates those fields.

---

## Step 1 — `supplied_at` migration + model cast

**File:** `database/migrations/tenant/{timestamp}_add_supplied_at_to_customer_invoices.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->date('supplied_at')->nullable()->after('issued_at');
        });
    }

    public function down(): void
    {
        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->dropColumn('supplied_at');
        });
    }
};
```

**Model:** `app/Models/CustomerInvoice.php` — add to `$casts`:
```php
'supplied_at' => 'date',
```

Add to `$fillable` (if mass assignment is allowed on this model — check existing pattern; follow).

---

## Step 2 — Address fields (verify first; create only if missing)

Before writing any migration, **read** `app/Models/Tenant.php`, `app/Models/Partner.php`, `app/Models/CompanySettings.php` and related factories to see the current address shape.

Possible existing patterns:
- Structured columns: `street`, `city`, `postcode`, `country_code`
- KV in `CompanySettings` group `company`: `address_line_1`, `postcode`, …
- Single `address` text field

**Decision tree:**
- **If structured columns exist** → use them in the PDF template. Skip Step 2.
- **If only a single text field exists** → keep using it (render verbatim) BUT add a `legal_address` json column on both tenant and partner for structured data. Backfill by copying the text.
- **If nothing exists** → add columns: `legal_address_line_1`, `legal_address_line_2` (nullable), `postcode`, `city` (`country_code` is already present and required per hotfix).

**Migration template if needed (partners):**

```php
Schema::table('partners', function (Blueprint $table) {
    $table->string('legal_address_line_1')->nullable()->after('country_code');
    $table->string('legal_address_line_2')->nullable()->after('legal_address_line_1');
    $table->string('postcode', 20)->nullable()->after('legal_address_line_2');
    $table->string('city')->nullable()->after('postcode');
});
```

Same pattern on `tenants` / extend `CompanySettings`. Backfill from any legacy address source if possible; otherwise leave null and the PDF falls back to the legacy field.

---

## Step 3 — Translation scaffolding

**Directory:** `lang/` (or `resources/lang/` — check Laravel 13 convention in this repo)

Create:
- `lang/bg/invoice-pdf.php`
- `lang/en/invoice-pdf.php`
- `lang/de/invoice-pdf.php`
- `lang/fr/invoice-pdf.php`

Each file returns:
```php
<?php

return [
    'heading' => 'Invoice',  // BG: 'ФАКТУРА', DE: 'Rechnung', FR: 'Facture'
    'reverse_charge' => 'Reverse charge',  // BG: 'Обратно начисляване'
    'vat_treatment_label' => 'VAT Treatment',
    'date_of_issue' => 'Date of issue',
    'date_of_supply' => 'Date of supply',
    'due_date' => 'Due date',
    'from_supplier' => 'From (Seller)',
    'to_customer' => 'To (Customer)',
    'vat_id' => 'VAT No',
    'eik' => 'EIK',
    'vies_consultation' => 'VIES consultation',
    'subtotal' => 'Subtotal',
    'net_at_rate' => 'Net @ :rate%',
    'vat_at_rate' => 'VAT @ :rate%',
    'discount' => 'Discount',
    'total' => 'Total',
    'amount_paid' => 'Paid',
    'amount_due' => 'Amount Due',
    'legal_basis' => 'Legal basis',
    'retention_notice' => 'This is a VAT invoice. Please retain for your records.',
    'payment_method' => 'Payment Method',
    'invoice_number' => 'Invoice Number',
];
```

**BG translations (key differences):**
```php
'heading' => 'ФАКТУРА',
'reverse_charge' => 'Обратно начисляване',
'vat_treatment_label' => 'Третиране по ДДС',
'date_of_issue' => 'Дата на издаване',
'date_of_supply' => 'Дата на данъчно събитие',
'due_date' => 'Падеж',
'from_supplier' => 'Изпращач',
'to_customer' => 'Получател',
'vat_id' => 'ДДС №',
'vies_consultation' => 'Справка VIES',
'subtotal' => 'Данъчна основа',
'total' => 'Общо',
'amount_due' => 'За плащане',
'legal_basis' => 'Правно основание',
```

**Locale selection:** in the render context, set app locale to the invoicing locale. Option A: read `tenant.invoicing_locale` (new column). Option B: derive from `tenant.country_code` (BG → bg, DE → de, else en). Option A is cleaner long-term; Option B is faster. **Ship B first** — one-line mapping in a helper, no schema change. Add a `invoicing_locale` column in a later polish pass.

---

## Step 4 — PDF template rewrite

**File:** `resources/views/pdf/customer-invoice.blade.php` (complete rewrite)

Structure (abbreviated — full Blade delivered when implementing):

```blade
@php
    use App\Models\VatLegalReference;

    $locale = $invoice->renderLocale();  // helper on the model, returns tenant invoicing locale
    app()->setLocale($locale);

    $tenant = tenancy()->tenant;
    $supplier = [
        'name' => $tenant->name ?? config('app.name'),
        'eik' => $tenant->eik,
        'vat_number' => $tenant->vat_number,
        'address' => $tenant->legal_address_formatted(),  // helper returns composed string
    ];

    $partner = $invoice->partner;

    // Per-rate grouping
    $itemsByRate = $invoice->items->groupBy(fn ($i) => (string) $i->vatRate?->rate ?? '0');

    // Legal reference for non-Domestic scenarios
    $legalRef = null;
    if ($invoice->vat_scenario !== \App\Enums\VatScenario::Domestic) {
        try {
            $legalRef = VatLegalReference::resolve(
                $tenant->country_code,
                $invoice->vat_scenario->value,
                $invoice->vat_scenario_sub_code ?? 'default'
            );
        } catch (\DomainException) {
            $legalRef = null;  // render nothing rather than crash
        }
    }
@endphp

<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="UTF-8">
    <style>/* unchanged visual style — keep the existing CSS */</style>
</head>
<body>
<div class="page">

    {{-- Header --}}
    <div class="header">
        <table>
            <tr>
                <td class="header-left">
                    <div class="company-name">{{ $supplier['name'] }}</div>
                    <div class="company-detail">
                        @if($supplier['eik']){{ __('invoice-pdf.eik') }}: {{ $supplier['eik'] }}<br>@endif
                        @if($supplier['vat_number']){{ __('invoice-pdf.vat_id') }}: {{ $supplier['vat_number'] }}<br>@endif
                        @if($supplier['address']){!! nl2br(e($supplier['address'])) !!}@endif
                    </div>
                </td>
                <td class="header-right">
                    <div class="document-title">{{ __('invoice-pdf.heading') }}</div>
                    <div class="document-meta">{{ __('invoice-pdf.invoice_number') }}: {{ $invoice->invoice_number }}</div>
                    <div class="document-meta">{{ __('invoice-pdf.date_of_issue') }}: {{ $invoice->issued_at?->format('d.m.Y') }}</div>
                    @if($invoice->supplied_at && !$invoice->supplied_at->equalTo($invoice->issued_at))
                        <div class="document-meta">{{ __('invoice-pdf.date_of_supply') }}: {{ $invoice->supplied_at->format('d.m.Y') }}</div>
                    @endif
                    @if($invoice->due_date)
                        <div class="document-meta">{{ __('invoice-pdf.due_date') }}: {{ $invoice->due_date->format('d.m.Y') }}</div>
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
                    <div class="party-label">{{ __('invoice-pdf.from_supplier') }}</div>
                    <div class="party-name">{{ $supplier['name'] }}</div>
                    <div class="party-detail">
                        @if($supplier['eik']){{ __('invoice-pdf.eik') }}: {{ $supplier['eik'] }}<br>@endif
                        @if($supplier['vat_number']){{ __('invoice-pdf.vat_id') }}: {{ $supplier['vat_number'] }}<br>@endif
                        @if($supplier['address']){!! nl2br(e($supplier['address'])) !!}@endif
                    </div>
                </td>
                <td class="party-cell-right">
                    <div class="party-label">{{ __('invoice-pdf.to_customer') }}</div>
                    <div class="party-name">{{ $partner->company_name ?: $partner->name }}</div>
                    <div class="party-detail">
                        @if($partner->vat_number){{ __('invoice-pdf.vat_id') }}: {{ $partner->vat_number }}<br>@endif
                        @if($partner->eik){{ __('invoice-pdf.eik') }}: {{ $partner->eik }}<br>@endif
                        @if($partner->legal_address_formatted()){!! nl2br(e($partner->legal_address_formatted())) !!}@endif
                    </div>
                </td>
            </tr>
        </table>
    </div>

    {{-- Meta box (VAT treatment + VIES audit) --}}
    @if($invoice->is_reverse_charge || $legalRef)
    <div class="meta-box">
        <table class="meta">
            @if($invoice->is_reverse_charge)
            <tr>
                <td class="meta-label">{{ __('invoice-pdf.vat_treatment_label') }}:</td>
                <td class="meta-value" colspan="3"><strong>{{ __('invoice-pdf.reverse_charge') }}</strong></td>
            </tr>
            @if($invoice->vies_request_id)
            <tr>
                <td class="meta-label">{{ __('invoice-pdf.vies_consultation') }}:</td>
                <td class="meta-value" colspan="3">{{ $invoice->vies_request_id }} ({{ $invoice->vies_checked_at?->format('d.m.Y H:i') }})</td>
            </tr>
            @endif
            @endif
            @if($legalRef)
            <tr>
                <td class="meta-label">{{ __('invoice-pdf.legal_basis') }}:</td>
                <td class="meta-value" colspan="3">{{ $legalRef->legal_reference }} — {{ $legalRef->getTranslation('description', $locale, false) }}</td>
            </tr>
            @endif
        </table>
    </div>
    @endif

    {{-- Line items --}}
    <table class="items">
        <thead>
            <tr>
                <th style="width:40%">{{ __('invoice-pdf.description') ?? 'Description' }}</th>
                <th class="text-right" style="width:10%">{{ __('invoice-pdf.sku') ?? 'SKU' }}</th>
                <th class="text-right" style="width:10%">{{ __('invoice-pdf.qty') ?? 'Qty' }}</th>
                <th class="text-right" style="width:12%">{{ __('invoice-pdf.unit_price') ?? 'Unit Price' }}</th>
                <th class="text-right" style="width:8%">%</th>
                <th class="text-right" style="width:10%">{{ __('invoice-pdf.vat_id') }}</th>
                <th class="text-right" style="width:10%">{{ __('invoice-pdf.total') }}</th>
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

    {{-- Totals (grouped by rate) --}}
    <div class="totals-wrapper">
        <table class="totals">
            <tr>
                <td class="totals-label">{{ __('invoice-pdf.subtotal') }}:</td>
                <td class="totals-value">{{ number_format((float) $invoice->subtotal, 2) }} {{ $invoice->currency_code }}</td>
            </tr>
            @if(bccomp((string) $invoice->discount_amount, '0', 2) > 0)
            <tr>
                <td class="totals-label">{{ __('invoice-pdf.discount') }}:</td>
                <td class="totals-value">- {{ number_format((float) $invoice->discount_amount, 2) }} {{ $invoice->currency_code }}</td>
            </tr>
            @endif

            {{-- Per-rate breakdown: one (net, VAT) pair per distinct rate --}}
            @foreach($itemsByRate as $rate => $items)
                @php
                    $netAtRate = $items->sum(fn ($i) => (float) $i->line_total);  // excl. VAT
                    $vatAtRate = $items->sum(fn ($i) => (float) $i->vat_amount);
                @endphp
                <tr>
                    <td class="totals-label">{{ __('invoice-pdf.net_at_rate', ['rate' => $rate]) }}:</td>
                    <td class="totals-value">{{ number_format($netAtRate, 2) }} {{ $invoice->currency_code }}</td>
                </tr>
                <tr>
                    <td class="totals-label">{{ __('invoice-pdf.vat_at_rate', ['rate' => $rate]) }}:</td>
                    <td class="totals-value">{{ number_format($vatAtRate, 2) }} {{ $invoice->currency_code }}</td>
                </tr>
            @endforeach

            <tr class="totals-grand">
                <td class="totals-label">{{ __('invoice-pdf.total') }}:</td>
                <td class="totals-value">{{ number_format((float) $invoice->total, 2) }} {{ $invoice->currency_code }}</td>
            </tr>
            @if(bccomp((string) $invoice->amount_paid, '0', 2) > 0)
            <tr>
                <td class="totals-label">{{ __('invoice-pdf.amount_paid') }}:</td>
                <td class="totals-value">{{ number_format((float) $invoice->amount_paid, 2) }} {{ $invoice->currency_code }}</td>
            </tr>
            @endif
            <tr class="totals-due">
                <td class="totals-label">{{ __('invoice-pdf.amount_due') }}:</td>
                <td class="totals-value">{{ number_format((float) $invoice->amount_due, 2) }} {{ $invoice->currency_code }}</td>
            </tr>
        </table>
    </div>

    <div class="footer">
        {{ __('invoice-pdf.retention_notice') }}
    </div>

</div>
</body>
</html>
```

**Partial extraction:** once the invoice PDF is solid, extract these components into `resources/views/pdf/partials/`:
- `_header.blade.php` — locale-aware title + supplier block
- `_parties.blade.php` — supplier + customer party table
- `_vat-treatment.blade.php` — VAT meta box with reverse-charge wording + VIES ref + legal basis
- `_totals-by-rate.blade.php` — per-rate breakdown logic
- `_footer.blade.php` — retention notice

Credit / debit notes (in `invoice-credit-debit.md`) reuse these partials.

---

## Step 5 — Model helpers

**File:** `app/Models/CustomerInvoice.php`

```php
public function renderLocale(): string
{
    $tenantCountry = tenancy()->tenant?->country_code;

    return match (strtoupper((string) $tenantCountry)) {
        'BG' => 'bg',
        'DE', 'AT' => 'de',
        'FR', 'BE', 'LU' => 'fr',
        default => 'en',
    };
}
```

**File:** `app/Models/Tenant.php` (or similar)

```php
public function legal_address_formatted(): ?string
{
    $parts = array_filter([
        $this->legal_address_line_1,
        $this->legal_address_line_2,
        trim(($this->postcode ?? '') . ' ' . ($this->city ?? '')),
    ]);

    return $parts === [] ? null : implode("\n", $parts);
}
```

Same helper on `Partner` model.

---

## Step 6 — Service guards

**File:** `app/Services/CustomerInvoiceService.php`

**Guard 1 — reverse-charge requires tenant VAT:**

```php
public function confirmWithScenario(CustomerInvoice $invoice, ...): void
{
    // ... existing preamble

    $scenario = $this->determineVatType($invoice, ...);

    if ($scenario === VatScenario::EuB2bReverseCharge && empty(tenancy()->tenant?->vat_number)) {
        throw new \DomainException(
            'Cannot issue a reverse-charge invoice: tenant VAT number is not configured. ' .
            'Configure it in Company Settings before proceeding.'
        );
    }

    // ... continue
}
```

Surface this as a Filament notification in the ViewCustomerInvoice page rather than letting the exception bubble.

**Guard 2 — 5-day rule warning (non-blocking):**

```php
$supplied = $invoice->supplied_at ?? $invoice->issued_at;

if ($supplied && $invoice->issued_at->diffInDays($supplied) > 5) {
    Notification::make()
        ->title('Late invoice issuance')
        ->body('This invoice is issued more than 5 days after the chargeable event (чл. 113, ал. 4 ЗДДС). Review the dates before confirming.')
        ->warning()
        ->send();
}
```

Non-blocking — user can still confirm. Tenant country variance (DE = end of month, etc.) tracked as future-work.

---

## Step 7 — Form field for `supplied_at`

**File:** `app/Filament/Resources/CustomerInvoices/Schemas/CustomerInvoiceForm.php`

Add after `issued_at`:

```php
DatePicker::make('supplied_at')
    ->label('Date of supply')
    ->helperText('Date of the chargeable event. Leave blank if same as date of issue.')
    ->nullable()
    ->default(fn (Get $get) => $get('issued_at')),
```

---

## Tests

**File:** `tests/Feature/InvoicePdfRenderTest.php`

Each test renders the PDF Blade to HTML (don't actually open DomPDF — test the rendered HTML string) and asserts content:

```php
use App\Models\CustomerInvoice;
use App\Enums\VatScenario;

function renderPdf(CustomerInvoice $invoice): string
{
    return view('pdf.customer-invoice', compact('invoice'))->render();
}

it('BG tenant renders ФАКТУРА heading', function () {
    $invoice = CustomerInvoice::factory()->confirmed()->bgTenant()->domestic()->create();
    expect(renderPdf($invoice))->toContain('ФАКТУРА');
});

it('renders обратно начисляване for reverse-charge invoice with BG tenant', function () {
    $invoice = CustomerInvoice::factory()->confirmed()->bgTenant()->euB2bReverseCharge()->create();
    expect(renderPdf($invoice))->toContain('Обратно начисляване');
});

it('renders Art. 138 for EU B2B reverse-charge (goods)', function () {
    $invoice = CustomerInvoice::factory()->confirmed()->euB2bReverseCharge(subCode: 'goods')->create();
    expect(renderPdf($invoice))->toContain('Art. 138 Directive 2006/112/EC');
});

it('renders Art. 146 for Non-EU export (goods)', function () {
    $invoice = CustomerInvoice::factory()->confirmed()->nonEuExport(subCode: 'goods')->create();
    expect(renderPdf($invoice))->toContain('Art. 146 Directive 2006/112/EC');
});

it('renders outside-scope wording for Non-EU services', function () {
    $invoice = CustomerInvoice::factory()->confirmed()->nonEuExport(subCode: 'services')->create();
    expect(renderPdf($invoice))->toContain('outside the scope of EU VAT');  // en locale
});

it('renders per-rate VAT breakdown for mixed-rate invoice', function () {
    $invoice = CustomerInvoice::factory()->confirmed()->mixedRates([0.20, 0.09])->create();
    $html = renderPdf($invoice);
    expect($html)->toContain('20%')->and($html)->toContain('9%');
});

it('renders date of supply when distinct from issue date', function () {
    $invoice = CustomerInvoice::factory()->confirmed()->create([
        'issued_at' => '2026-04-15',
        'supplied_at' => '2026-04-10',
    ]);
    $html = renderPdf($invoice);
    expect($html)
        ->toContain('10.04.2026')  // date of supply
        ->and($html)->toContain('15.04.2026');  // date of issue
});

it('omits supplied_at row when same as issued_at', function () {
    $invoice = CustomerInvoice::factory()->confirmed()->create([
        'issued_at' => '2026-04-15',
        'supplied_at' => '2026-04-15',
    ]);
    $html = renderPdf($invoice);
    // Only one occurrence of 15.04.2026
    expect(substr_count($html, '15.04.2026'))->toBe(1);
});

it('renders VIES consultation number for reverse-charge', function () {
    $invoice = CustomerInvoice::factory()->confirmed()->euB2bReverseCharge()->create([
        'vies_request_id' => 'WAPIAAAAXZi9QJvU',
    ]);
    expect(renderPdf($invoice))->toContain('WAPIAAAAXZi9QJvU');
});

it('renders supplier legal address in From block', function () {
    $invoice = CustomerInvoice::factory()->confirmed()->create();
    $html = renderPdf($invoice);
    expect($html)->toContain(tenancy()->tenant->legal_address_line_1);
});
```

**File:** `tests/Feature/InvoiceConfirmationGuardsTest.php`

```php
it('refuses reverse-charge confirmation when tenant VAT is null', function () {
    tenant()->update(['vat_number' => null, 'is_vat_registered' => false]);

    $invoice = CustomerInvoice::factory()->draft()->euEligible()->create();

    expect(fn () => app(CustomerInvoiceService::class)->confirmWithScenario($invoice))
        ->toThrow(\DomainException::class, 'tenant VAT number is not configured');
});

it('warns but does not block when issued_at is 10 days after supplied_at', function () {
    $invoice = CustomerInvoice::factory()->draft()->create([
        'issued_at' => now(),
        'supplied_at' => now()->subDays(10),
    ]);

    app(CustomerInvoiceService::class)->confirmWithScenario($invoice);

    Notification::assertNotified(fn ($n) => str_contains($n->body, 'чл. 113, ал. 4'));
});
```

---

## Gotchas / load-bearing details

1. **DomPDF font support.** "ФАКТУРА" requires a Cyrillic-capable font. The existing template uses `DejaVu Sans` — confirm that font is bundled / available to DomPDF. If not, bundle a font or switch to one with Cyrillic coverage. Test `bg` render early.
2. **Per-rate grouping vs stored `tax_amount`.** The invoice model stores a single `tax_amount` total. The per-rate breakdown on the PDF is computed from `items`. Make sure they reconcile (sum of per-rate VAT = `tax_amount`).
3. **`legalRef->getTranslation('description', $locale, false)`** — the third argument `false` makes the fallback non-strict; returns null if no translation exists. Blade template handles null by rendering only the citation.
4. **`VatScenario::Domestic` has no row in `vat_legal_references`.** Resolve call is skipped for domestic invoices — no legal basis line rendered. Confirm this is desired (BG invoices with standard 20% VAT don't carry a "basis" line because VAT is actually charged).
5. **Translation loading.** Laravel loads `lang/*/invoice-pdf.php` automatically. If the app uses a package-namespaced translation bundle, adjust keys accordingly.
6. **Existing tenants' addresses.** If you add legal-address columns, backfill from whatever existing address source the tenant was using. Don't ship a migration that leaves addresses blank — users will see empty "From" blocks.
7. **Mixed-currency totals.** `$invoice->currency_code` is rendered alongside every amount. If all amounts are in the same currency this is fine; if any tenant ever issues a multi-currency invoice (unlikely, but), revisit.
8. **`amount_due` when fully paid.** Current template always renders the "Amount Due" row. A fully-paid invoice shows `0.00` in red. Consider conditionally hiding the red row when `amount_due = 0`.

---

## Exit Criteria

- [ ] All PDF-render tests green
- [ ] All confirmation-guard tests green
- [ ] Full test suite green
- [ ] Manual: rendered BG invoice side-by-side with an НАП specimen — visually matches Art. 114 requirements
- [ ] Manual: rendered EN invoice for a DE customer — readable, correct, localized where appropriate
- [ ] Manual: Cyrillic renders correctly (no `?` or missing glyphs)
- [ ] Pint clean
- [ ] Checklist in `pdf-rewrite.md` ticked
