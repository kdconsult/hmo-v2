# Phase B — Non-VAT-Registered Tenant Blocks + DomesticExempt Scenario

## Context

Phase A builds the `vat_legal_references` lookup table and resolver. Phase B
is the **first consumer** of that data: it wires legal references into the
customer invoice UI, form, service, confirmation modal, and PDF.

Two user-facing behaviours land together:

1. **Area 4 blocks** — when `tenancy()->tenant->is_vat_registered = false`,
   the tenant legally cannot charge VAT. `pricing_mode` hides,
   `is_reverse_charge` hides, line-item VAT rate is forced to the 0%
   exempt rate, and confirmation short-circuits to `VatScenario::Exempt`
   (already partially implemented — this phase closes the UI leaks).
2. **`DomesticExempt` scenario** (new) — a VAT-registered tenant issuing
   an exempt supply under Bulgarian Art. 39–49 (health, education,
   financial, cultural, etc.). User-selected on the draft form (not
   auto-detected), with an article picker populated from Phase A's
   `VatLegalReference::listForScenario('BG', 'domestic_exempt')`.

Phase B also adds a single `vat_scenario_sub_code` column on
`customer_invoices` to carry all sub-code variants:
- `Exempt` → `'default'`
- `DomesticExempt` → `'art_39'`..`'art_49'`
- `EuB2bReverseCharge` → `'goods'` | `'services'`
- `NonEuExport` → `'goods'` | `'services'`

This one column is the bridge between the confirmed invoice and Phase A's
resolver. Credit/debit notes (Phase C) will inherit the same column.

Phase B does **not** touch credit notes, debit notes, partner form, or
tenant setup. It does **not** change the existing `VatScenario::determine()`
signature semantics — `DomesticExempt` is never auto-returned.

## Prerequisite

Phase A must land first. Phase B imports `VatLegalReference` and expects
16 BG rows seeded. If Phase A's `TenantTemplateManager` wiring is
incomplete (see Phase A plan), Phase B tests will fail with empty lookups.

## Scope

1. **Enum** — add `VatScenario::DomesticExempt`; neutralise Bulgarian-
   specific "Article 196" wording in `description()`.
2. **Migration + model** — `vat_scenario_sub_code` nullable string on
   `customer_invoices`; backfill existing `eu_b2b_reverse_charge` and
   `non_eu_export` invoices to `'goods'`.
3. **Service** — `CustomerInvoiceService::determineVatType()` and
   `confirmWithScenario()` accept `?string $subCodeHint`; resolve sub_code
   via `inferSubCodeFromItems()` with `DomainException` on mixed items;
   DomesticExempt detection from draft form state; OSS skip list extended
   for DomesticExempt; **`EuOssService` year-sourcing fix** — change
   `now()->year` at `EuOssService.php:72` and `:108` to
   `$invoice->issued_at->year` so accumulate/reverse book under the
   invoice's fiscal year, not the caller's wall-clock year (prerequisite
   for Phase C's `adjust()` method).
4. **Form** — hide `pricing_mode` and `is_reverse_charge` when tenant
   non-registered; add DomesticExempt Toggle + article Select on draft;
   clear DomesticExempt state on partner country change.
5. **Items relation manager** — restrict line-item `vat_rate_id` to the
   single 0% rate when tenant non-registered OR when the invoice has
   `vat_scenario_sub_code` starting with `'art_'`.
6. **Confirmation modal — extend the existing schema-based confirm action.**
   The confirm action at `ViewCustomerInvoice.php:57` is **already**
   schema-based (`->schema(fn (CustomerInvoice $record): array =>
   $this->buildConfirmationSchema($record))->mountUsing(...)`); the
   closure at line 118 is the bare `->action(function (CustomerInvoice
   $record))`. The narrow change is: (a) add `array $data` as the first
   parameter of that closure, (b) forward `$data['goods_services_pick']
   ?? null` as the new `$subCodeHint` argument into
   `$invoiceService->confirmWithScenario($record, viesData: …,
   subCodeHint: …)`, (c) extend `buildConfirmationSchema()` with a
   `Radio::make('goods_services_pick')` that is only rendered when the
   target scenario is `EuB2bReverseCharge`/`NonEuExport` AND
   `classifyItems($record) === 'mixed'`, (d) extend the zero-rated
   scenario list and scenario colour map to include `DomesticExempt`,
   (e) when `$record->vat_scenario_sub_code` starts with `'art_'`
   render the resolved article label via
   `VatLegalReference::resolve(...)`.
7. **PDF** — replace the reverse-charge-specific row in
   `pdf/customer-invoice.blade.php` with a general legal-notice block
   keyed on a resolved `$legalReference` variable; gate VAT totals row on
   `!$isZeroRated`.
8. **Tests** — cover every new path (see Tests section).

Out of scope for Phase B: credit notes, debit notes, partner form
changes, tenant onboarding changes, VIES flow changes.

## Critical Files

### Modify

| File | Reason |
|------|--------|
| `app/Enums/VatScenario.php` | Add `DomesticExempt`; update `requiresVatRateChange()`; neutralise "Article 196" in `description()` (line 25 comment + line 70 body) |
| `database/migrations/tenant/` (NEW file) | `vat_scenario_sub_code` column + backfill |
| `app/Models/CustomerInvoice.php` | Add to `$fillable` (no cast — plain string) |
| `app/Services/CustomerInvoiceService.php` | New `$subCodeHint` param; sub_code resolution; DomesticExempt detection; OSS skip list |
| `app/Services/EuOssService.php` | Year-sourcing fix — `(int) now()->year` → `(int) $invoice->issued_at->year` at lines 72 and 108 (accumulate + reverseAccumulation). `shouldApplyOss()` at line 37 and `VatScenario.php:58` keep `now()->year` (those are current-year threshold reads, not booking operations) |
| `app/Filament/Resources/CustomerInvoices/Schemas/CustomerInvoiceForm.php` | Hide `pricing_mode` & `is_reverse_charge` for non-registered; DomesticExempt Toggle + Select; partner-change reset |
| `app/Filament/Resources/CustomerInvoices/RelationManagers/CustomerInvoiceItemsRelationManager.php` | Dynamic `vat_rate_id` options (0%-only in art_/non-registered cases) |
| `app/Filament/Resources/CustomerInvoices/Pages/ViewCustomerInvoice.php` | **Extend** the already-schema-based `confirm` action: add `array $data` to the closure, forward `subCodeHint` into the service, extend `buildConfirmationSchema()` for DomesticExempt + mixed-items radio; resolve `$legalReference` in the Print action and pass to the blade view |
| `resources/views/pdf/customer-invoice.blade.php` | General legal-notice block; zero-rated totals handling |
| `tasks/vat-vies/spec.md` | Add `DomesticExempt` to scenario table; clarify non-EU services "out of scope" wording |
| `tasks/vat-vies/phase-a-plan.md` | Correct one test bullet (see "Phase A test correction" section) |

### Reuse (do not re-implement)

- `VatLegalReference::resolve($country, $scenario, $subCode)` — Phase A
- `VatLegalReference::listForScenario($country, $scenario)` — Phase A
- `CustomerInvoiceService::resolveZeroVatRate(string $countryCode)` —
  **already accepts a country parameter** (line 388). Keep as is.
- **Pure refactor (Phase B)** — extract a new private
  `applyZeroRateToItems(CustomerInvoice $invoice, VatRate $rate): void`
  out of the inline block currently in
  `CustomerInvoiceService::determineVatType()` at lines ~371–381
  (loadMissing('items'), the `foreach` that sets `vat_rate_id`, sets
  relations, calls `recalculateItemTotals`, then
  `recalculateDocumentTotals`). No behavioural change. Phase C services
  will duplicate the same pattern per model — **do not** extract a
  shared helper across CustomerInvoice/CreditNote/DebitNote (item
  relations differ; YAGNI).
- `EuCountries` for country membership checks.
- `ProductType` enum (`Stock`, `Service`, `Bundle`) on
  `product_variant->product->type` — drives `inferSubCodeFromItems()`.
- `tenancy()->tenant->is_vat_registered` boolean — cast already on
  Tenant model.

## Enum Changes

```php
// app/Enums/VatScenario.php
case Exempt = 'exempt';
case DomesticExempt = 'domestic_exempt'; // NEW
case Domestic = 'domestic';
case EuB2bReverseCharge = 'eu_b2b_reverse_charge';
// ... rest unchanged

public function description(): string
{
    return match ($this) {
        self::Exempt => 'Exempt — tenant is not VAT registered.',
        self::DomesticExempt => 'Exempt supply (article chosen per invoice).',
        self::Domestic => 'Domestic sale — standard VAT applies.',
        // Neutralised: "Article 196" is Bulgaria-specific legal phrasing that
        // belongs in VatLegalReference rows, not in a code enum.
        self::EuB2bReverseCharge => 'Reverse charge — VAT accounted for by the recipient.',
        self::EuB2cUnderThreshold => 'EU B2C — below OSS threshold, domestic VAT rate applies.',
        self::EuB2cOverThreshold => 'EU B2C — OSS threshold exceeded, destination country VAT rate applies.',
        self::NonEuExport => 'Non-EU export — zero-rated (0% VAT).',
    };
}

public function requiresVatRateChange(): bool
{
    return match ($this) {
        self::Domestic, self::EuB2cUnderThreshold => false,
        self::Exempt, self::DomesticExempt, self::EuB2bReverseCharge,
        self::EuB2cOverThreshold, self::NonEuExport => true,
    };
}
```

`VatScenario::determine()` signature is **unchanged** — `DomesticExempt`
is never auto-returned. It only appears when the user toggles it on the
draft form and the service detects the resulting sub_code.

Code-wide "Article 196" grep was run: no test asserts the string; the
only hits are the enum comment (line 25) and description (line 70), both
of which are neutralised above.

## Migration

`database/migrations/tenant/2026_04_17_210000_add_vat_scenario_sub_code_to_customer_invoices.php`

```php
public function up(): void
{
    Schema::table('customer_invoices', function (Blueprint $table) {
        $table->string('vat_scenario_sub_code')
            ->nullable()
            ->after('vat_scenario');
    });

    // Backfill so existing confirmed invoices can still render PDFs
    // after Phase B ships. Default 'goods' — safer assumption for most
    // Bulgarian SME activity; users can re-issue if wrong.
    DB::table('customer_invoices')
        ->whereIn('vat_scenario', ['eu_b2b_reverse_charge', 'non_eu_export'])
        ->whereNull('vat_scenario_sub_code')
        ->update(['vat_scenario_sub_code' => 'goods']);
}

public function down(): void
{
    Schema::table('customer_invoices', function (Blueprint $table) {
        $table->dropColumn('vat_scenario_sub_code');
    });
}
```

`CustomerInvoice` model — add `'vat_scenario_sub_code'` to `$fillable`.
No cast (plain nullable string).

## Service

### `CustomerInvoiceService::confirmWithScenario()`

Add `?string $subCodeHint = null` to the signature. Pass it through to
`determineVatType()`. Existing call sites (confirmWithVat,
confirmWithReverseCharge) pass `null` unless the user picked from the
mixed-items radio.

### `CustomerInvoiceService::determineVatType()`

Accepts `?string $subCodeHint`. After the existing scenario determination:

```php
// Detect user-picked DomesticExempt on the draft form:
// the form persists vat_scenario_sub_code='art_XX' when Toggle is on.
if ($scenario === VatScenario::Domestic
    && is_string($invoice->vat_scenario_sub_code)
    && str_starts_with($invoice->vat_scenario_sub_code, 'art_')) {
    $scenario = VatScenario::DomesticExempt;
}

// Resolve final sub_code by scenario.
$invoice->vat_scenario_sub_code = match ($scenario) {
    VatScenario::Exempt => 'default',
    VatScenario::DomesticExempt => $invoice->vat_scenario_sub_code, // already 'art_XX'
    VatScenario::EuB2bReverseCharge, VatScenario::NonEuExport
        => $subCodeHint ?? $this->inferSubCodeFromItems($invoice),
    default => null, // Domestic, EuB2c* — no sub_code
};
```

### New private method

```php
private function inferSubCodeFromItems(CustomerInvoice $invoice): string
{
    $invoice->loadMissing('items.productVariant.product');

    $hasGoods = false;
    $hasServices = false;

    foreach ($invoice->items as $item) {
        $type = $item->productVariant?->product?->type;
        if ($type === ProductType::Service) {
            $hasServices = true;
        } elseif ($type === ProductType::Stock || $type === ProductType::Bundle) {
            // Design decision: Bundle treated as goods unless the user
            // picks 'services' via the mixed-items radio.
            $hasGoods = true;
        }
    }

    return match (true) {
        $hasGoods && $hasServices => throw new DomainException(
            'Mixed goods/services on invoice; user must pick goods or services before confirming.'
        ),
        $hasServices => 'services',
        default => 'goods',
    };
}
```

### OSS paths

Extend the existing OSS skip list (around line 307) to include
`VatScenario::DomesticExempt` — no OSS accumulation on exempt supplies.
Same for the cancel-OSS-reversal path (around line 438).

**Year-sourcing fix (prerequisite for Phase C's `adjust()`):** change
`EuOssService.php:72` and `EuOssService.php:108` from
`(int) now()->year` to `(int) $invoice->issued_at->year`.
`CustomerInvoice::$issued_at` is cast as `'date'` (Carbon) — confirmed
at `app/Models/CustomerInvoice.php:78` — so the `->year` accessor is
safe. This is a pure bug fix: today a Dec-dated invoice cancelled in
January would reverse against the wrong year bucket; with the fix both
accumulate and reverse use the invoice's fiscal year. `shouldApplyOss()`
at `EuOssService.php:37` and `VatScenario.php:58` keep `now()->year` —
those are "has this tenant crossed the threshold **this year**?" reads,
not booking operations.

Target-rate match in the `requiresVatRateChange()` flow (today at
`CustomerInvoiceService.php:362–381`): add `VatScenario::DomesticExempt`
to the `match` arm that already handles `Exempt`, `EuB2bReverseCharge`,
`NonEuExport` via `$this->resolveZeroVatRate($tenantCountry)` (i.e.
same zero-rate target as Exempt). The extracted helper
`applyZeroRateToItems($invoice, $targetVatRate)` (see Reuse section)
then replaces the inline `loadMissing('items')` + `foreach` +
`recalculateItemTotals` + `recalculateDocumentTotals` block, so
DomesticExempt and the three existing scenarios share one call site.
There is **no** existing `applyExemptScenario()` method to reuse — the
Phase B refactor creates the only helper in this area.

## Form

`CustomerInvoiceForm::configure()` — at top, read tenant state once:

```php
$tenant = tenancy()->tenant;
$tenantIsVatRegistered = (bool) $tenant?->is_vat_registered;
$tenantCountry = strtoupper((string) ($tenant?->country_code ?? 'BG'));
```

### `pricing_mode` (existing field)

Add:
```php
->hidden(fn (): bool => ! $tenantIsVatRegistered)
```

### `is_reverse_charge` (existing toggle)

Add:
```php
->visible(fn (): bool => $tenantIsVatRegistered)
```

### Partner `afterStateUpdated` (existing)

When the new partner's `country_code !== $tenantCountry`, clear the
DomesticExempt draft state so a stale `art_XX` cannot ride into a
foreign-partner confirmation:

```php
$set('domestic_exempt', false);
$set('vat_scenario_sub_code', null);
```

### Partner select helperText

Before calling `VatScenario::determine()`, short-circuit:
```php
if (! $tenantIsVatRegistered) {
    return 'Exempt — tenant not VAT registered';
}
```

### New DomesticExempt block

Placed after the partner select, before items:

```php
Toggle::make('domestic_exempt')
    ->label('Domestic exempt supply (Art. 39–49)')
    ->dehydrated(false) // ephemeral — NOT a DB column
    ->live()
    ->visible(fn (Get $get): bool
        => $tenantIsVatRegistered && self::partnerIsDomestic($get, $tenantCountry))
    ->afterStateHydrated(function (Set $set, Get $get): void {
        // Restore toggle from stored sub_code on edit.
        $sub = $get('vat_scenario_sub_code');
        $set('domestic_exempt', is_string($sub) && str_starts_with($sub, 'art_'));
    })
    ->afterStateUpdated(function (?bool $state, Set $set): void {
        if (! $state) {
            $set('vat_scenario_sub_code', null);
        }
    }),

Select::make('vat_scenario_sub_code')
    ->label('Legal basis')
    ->options(fn () => VatLegalReference::listForScenario($tenantCountry, 'domestic_exempt')
        ->pluck('legal_reference', 'sub_code')
        ->all())
    ->default(fn () => optional(
        VatLegalReference::listForScenario($tenantCountry, 'domestic_exempt')
            ->firstWhere('is_default', true)
    )?->sub_code)
    ->visible(fn (Get $get): bool => (bool) $get('domestic_exempt'))
    ->required(fn (Get $get): bool => (bool) $get('domestic_exempt'))
    ->dehydrated(), // persisted
```

`CustomerInvoiceForm::configure()` is a `public static` method, so
`$this` is not available. Add a **private static** helper
`private static function partnerIsDomestic(Get $get, string $tenantCountry): bool`
on the same class that reads `partner_id`, fetches the partner's
`country_code`, and compares to `$tenantCountry`. Call it as
`self::partnerIsDomestic($get, $tenantCountry)`. Runs during form
rendering only, not in hot loops.

## Items Relation Manager

`CustomerInvoiceItemsRelationManager`, line ~140–144 (`vat_rate_id`
Select). Replace static options with a closure that restricts to the
single 0% rate when either:
- `! tenancy()->tenant?->is_vat_registered`, or
- The owner record's `vat_scenario_sub_code` starts with `'art_'` (the
  user marked the draft DomesticExempt).

```php
->options(function (RelationManager $livewire): array {
    $tenant = tenancy()->tenant;
    $invoice = $livewire->getOwnerRecord();
    $country = strtoupper((string) ($tenant?->country_code ?? 'BG'));

    $exemptOnly = ! (bool) $tenant?->is_vat_registered
        || (is_string($invoice->vat_scenario_sub_code)
            && str_starts_with($invoice->vat_scenario_sub_code, 'art_'));

    if ($exemptOnly) {
        return VatRate::forCountry($country)
            ->ofType('zero')
            ->pluck('name', 'id')
            ->all();
    }

    return VatRate::active()->orderBy('rate')->pluck('name', 'id')->all();
}),
```

**Reactivity caveat** (must be noted in test comments): the RM reads
`getOwnerRecord()` at build time. When the user toggles DomesticExempt
on the draft but hasn't saved, the RM still sees the old record. The 0%
restriction kicks in **after** the first save that persists
`vat_scenario_sub_code`. Confirmation-time logic in the service is the
authoritative guard; this restriction is a UX convenience, not a
correctness boundary.

## Confirm Action + Confirmation Modal

**The confirm action is already schema-based.**
`ViewCustomerInvoice.php:57` today:
```php
Action::make('confirm')
    ->schema(fn (CustomerInvoice $record): array => $this->buildConfirmationSchema($record))
    ->mountUsing(function (CustomerInvoice $record): void { ... })
    // ...
    ->action(function (CustomerInvoice $record): void {           // line ~118
        // calls $invoiceService->confirmWithScenario($record, viesData: $storedViesData)
    })
```

The action already has `->schema(...)`; only the `->action(...)`
closure is missing `array $data`. This plan **extends** (does not
convert) the action:

```php
// Only these lines change in the existing action:
->action(function (array $data, CustomerInvoice $record): void {
    $subCodeHint = $data['goods_services_pick'] ?? null;
    app(CustomerInvoiceService::class)->confirmWithScenario(
        $record,
        viesData: $storedViesData,   // existing
        subCodeHint: $subCodeHint,   // NEW
    );
})
```

Notes:
- The page has no `$this->invoiceService` property; use
  `app(CustomerInvoiceService::class)` as the existing closure already
  does.
- `confirmWithScenario()`'s second positional parameter is
  `?array $viesData`, not a hint string. The new `$subCodeHint` must be
  passed **by name** and added as a new last parameter on the service
  signature. (See the Service section above.)
- Do **not** rename the action or touch `->mountUsing(...)`. The only
  closure-level change is adding `array $data` and the one forwarded
  argument.

`buildConfirmationSchema(CustomerInvoice $record): array` **already
exists** on the page (it is the target of the `->schema(...)` callback
at line 57). Extensions:

1. **Scenario colour map**: add `DomesticExempt => 'success'` (green,
   same tone as `Exempt`).
2. **Zero-rated set**: add `DomesticExempt` to `$zeroRatedScenarios` so
   the preview rows suppress the VAT column.
3. **Article label**: when `$record->vat_scenario_sub_code` starts with
   `'art_'`, resolve via
   `VatLegalReference::resolve($tenantCountry, 'domestic_exempt', $sub)`
   and render its `legal_reference` + `description` as read-only text.
4. **Mixed-items radio**: add a `Radio::make('goods_services_pick')`
   with options `['goods' => 'Goods', 'services' => 'Services']` when
   `classifyItems($record) === 'mixed'` AND the target scenario is in
   `[EuB2bReverseCharge, NonEuExport]`. Required in that case.
5. For `Exempt`, `DomesticExempt`, reverse charge, and export paths,
   the modal's scenario description pulls from
   `VatScenario::description()`.

New private helper on the page:

```php
private function classifyItems(CustomerInvoice $record): string
{
    $record->loadMissing('items.productVariant.product');
    $hasGoods = false;
    $hasServices = false;

    foreach ($record->items as $item) {
        $type = $item->productVariant?->product?->type;
        if ($type === ProductType::Service) $hasServices = true;
        elseif ($type === ProductType::Stock || $type === ProductType::Bundle) $hasGoods = true;
    }

    return match (true) {
        $hasGoods && $hasServices => 'mixed',
        $hasServices => 'services',
        default => 'goods',
    };
}
```

`confirmWithReverseCharge` (line 244) already has a `->schema([...])` +
`function (array $data, ...)` structure. Add the same
`goods_services_pick` radio gated on `classifyItems()` returning
`'mixed'`. Pass the chosen value as `$subCodeHint` to the service.

`confirmWithVat` (line 207): currently bare `->requiresConfirmation()`.
This path runs **after** VIES has invalidated the partner — scenario is
no longer reverse charge, so no sub_code is required (domestic/B2C
paths). No schema conversion needed here.

### Print action

Existing `Print` action (lines 284–299) renders the PDF. Extend it to
resolve the legal reference up front and pass as a blade variable:

```php
$legalReference = null;
$scenario = $record->vat_scenario;
$sub = $record->vat_scenario_sub_code;

if ($scenario !== null && $sub !== null && in_array($scenario, [
    VatScenario::Exempt,
    VatScenario::DomesticExempt,
    VatScenario::EuB2bReverseCharge,
    VatScenario::NonEuExport,
], true)) {
    $tenantCountry = strtoupper((string) (tenancy()->tenant?->country_code ?? 'BG'));
    try {
        $legalReference = VatLegalReference::resolve(
            $tenantCountry, $scenario->value, $sub
        );
    } catch (DomainException $e) {
        // Legacy invoice missed backfill; render PDF without notice.
        report($e);
    }
}

return Pdf::view('pdf.customer-invoice', [
    'invoice' => $record,
    'legalReference' => $legalReference,
    // ... existing bindings
])->download(...);
```

## PDF

`resources/views/pdf/customer-invoice.blade.php`:

1. **Replace lines 157–162** (current reverse-charge-specific row) with:

```blade
@isset($legalReference)
    <tr>
        <td colspan="2" class="legal-notice">
            <strong>{{ $legalReference->legal_reference }}</strong>
            @if ($legalReference->getTranslation('description', app()->getLocale(), false))
                — {{ $legalReference->getTranslation('description', app()->getLocale()) }}
            @endif
        </td>
    </tr>
@endisset
```

2. **Gate VAT totals row** (around lines 201–231):

```blade
@php
    $isZeroRated = in_array($invoice->vat_scenario, [
        \App\Enums\VatScenario::Exempt,
        \App\Enums\VatScenario::DomesticExempt,
        \App\Enums\VatScenario::EuB2bReverseCharge,
        \App\Enums\VatScenario::NonEuExport,
    ], true);
    $grandTotal = $isZeroRated ? $invoice->subtotal : $invoice->total;
@endphp

<tr><td>Subtotal</td><td>{{ money($invoice->subtotal) }}</td></tr>

@unless ($isZeroRated)
    <tr><td>VAT</td><td>{{ money($invoice->vat_amount) }}</td></tr>
@endunless

<tr><td><strong>Total</strong></td><td><strong>{{ money($grandTotal) }}</strong></td></tr>
```

No separate "reverse charge" hardcoded row remains — the legal-notice
block handles all four zero-rated scenarios uniformly.

## Phase A Test Correction

`tasks/vat-vies/phase-a-plan.md` currently lists:
> `resolve('BG', 'domestic_exempt', 'art_999')` falls back to the default
> (Art. 39) row.

This is **wrong**. No `default` sub_code row exists for `domestic_exempt`
(the seeder never creates one). The resolver's step-2 fallback misses
and it throws `DomainException`. Replace the bullet with:

> `resolve('BG', 'domestic_exempt', 'art_999')` throws `DomainException`
> (neither the exact `art_999` row nor a `default` fallback exists for
> this scenario).

Keep the existing "`resolve('BG', 'eu_b2b_reverse_charge')` throws" and
"`resolve('XX', 'exempt')` throws" bullets — they are correct.

## Tests

All new feature tests require tenant context (onboarding + `$tenant->run`)
as per `AuthorizedAccessToVatRateTest`.

### Unit

`tests/Unit/Services/CustomerInvoiceServiceDomesticExemptTest.php`:
- `determineVatType()` routes to `DomesticExempt` when
  `vat_scenario_sub_code='art_42'` and partner is domestic.
- `confirmWithScenario()` applies 0% rate + recalculates totals when
  scenario becomes `DomesticExempt`.
- OSS accumulation is NOT called for `DomesticExempt`.
- `inferSubCodeFromItems()` returns `'goods'` when all items are Stock.
- `inferSubCodeFromItems()` returns `'services'` when all items are Service.
- `inferSubCodeFromItems()` throws `DomainException` on mixed items.
- `$subCodeHint` overrides inference when provided.

### Feature (Filament)

`tests/Feature/Filament/CustomerInvoiceDomesticExemptFormTest.php`:
- Toggle visible only when tenant is VAT-registered AND partner is domestic.
- Article Select appears only when Toggle is on; Select is required.
- Saving with Toggle on persists `vat_scenario_sub_code='art_XX'`.
- Changing partner to a foreign country clears both toggle and sub_code
  via `afterStateUpdated`.
- Editing an invoice with persisted `art_XX` hydrates the Toggle back to on.

`tests/Feature/Filament/CustomerInvoiceBlocksTest.php`:
- `pricing_mode` hidden when tenant `is_vat_registered=false`.
- `is_reverse_charge` hidden when tenant `is_vat_registered=false`.
- Items RM `vat_rate_id` options = 0% only when tenant non-registered.
- Items RM `vat_rate_id` options = 0% only when invoice has
  `vat_scenario_sub_code='art_42'` **after save** (document the
  build-time caveat in a code comment on the test).

`tests/Feature/Filament/ConfirmInvoiceActionTest.php`:
- Confirm modal scenario badge shows "Exempt" for non-registered tenants.
- Confirm modal scenario badge shows "Domestic exempt" with resolved
  article label when `sub_code='art_42'`.
- Confirm modal on reverse-charge path with mixed items shows the
  goods/services radio; confirms with chosen value; service receives
  correct `$subCodeHint`.
- Confirm modal on reverse-charge path with only-goods items hides the
  radio and defaults to `'goods'`.

`tests/Feature/Pdf/CustomerInvoicePdfTest.php`:
- PDF for `Exempt` scenario: no VAT row; legal notice contains
  `'чл. 113, ал. 9 ЗДДС'`.
- PDF for `DomesticExempt` + `art_39`: no VAT row; legal notice contains
  `'чл. 39 ЗДДС'`.
- PDF for `EuB2bReverseCharge` + `goods`: no VAT row; legal notice
  contains `'Art. 138 Directive 2006/112/EC'`.
- PDF for `NonEuExport` + `services`: no VAT row; legal notice contains
  `'Art. 44 Directive 2006/112/EC'`.
- PDF for `Domestic` (standard): VAT row present; no legal notice block.

### Enum

`tests/Unit/Enums/VatScenarioTest.php` extensions:
- `requiresVatRateChange()` returns true for `DomesticExempt`.
- `description()` for `EuB2bReverseCharge` no longer contains
  "Article 196" (regression lock against re-introducing country-specific
  wording).
- `determine()` never returns `DomesticExempt` regardless of arguments
  (regression lock — only the service detects it from sub_code).

### EuOssService year-sourcing

`tests/Feature/EuOssServiceYearTest.php` (new):
- `accumulate(CustomerInvoice $invoice)` books under the invoice's
  `issued_at->year`, not `now()->year`. Create an invoice dated
  `2025-12-15`, travel clock to `2026-01-10`, call `accumulate`, assert
  the row lands in year `2025`.
- `reverseAccumulation(CustomerInvoice $invoice)` mirrors the same year
  sourcing — sign-flipped amount booked in `2025` when clock is `2026`.
- **Regression lock on cancel path**: `CustomerInvoiceService::cancel()`
  on an OSS-accumulated invoice dated in a prior year produces a
  balanced pair (accumulate in year N + reverse in year N), leaving the
  ledger at zero for that country+year bucket. Today (pre-fix) this
  test would fail because the reverse lands in year N+1.

## Verification

1. `./vendor/bin/sail artisan test --parallel --compact --filter="DomesticExempt|CustomerInvoiceBlocks|ConfirmInvoiceAction|CustomerInvoicePdf|VatScenario"`
   — all new tests green.
2. `./vendor/bin/sail artisan test --parallel --compact` — full suite
   still green (Phase A baseline + Phase B additions).
3. Browser smoke:
   - Create domestic invoice → toggle "Domestic exempt supply" →
     select Art. 42 → save → line items auto-restricted to 0% after
     reload → confirm → PDF renders Art. 42 legal notice, no VAT row.
   - Invoice to EU B2B partner with one Service + one Stock item →
     confirm → modal shows goods/services radio → pick "services" →
     PDF renders Art. 44 legal notice.
   - Toggle tenant `is_vat_registered` to false → invoice form hides
     `pricing_mode` and `is_reverse_charge`; items RM shows only 0% rate.
4. `vendor/bin/pint --dirty --format agent` — clean.
