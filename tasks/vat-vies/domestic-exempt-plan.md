# Plan: DomesticExempt VAT Scenario

> **Task:** `tasks/vat-vies/domestic-exempt.md`
> **Ships with:** `tasks/vat-vies/pdf-rewrite-plan.md` — combined push in one branch / PR. Migration, enum case, and PDF rendering are owned by that plan and NOT duplicated here.
> **Review note:** No direct review finding; derived from `spec.md` Area 3 Phase B (BG tenant must be able to issue an exempt-under-ЗДДС invoice with correct legal-basis citation).
> **Status:** Ready to implement (after `legal-references.md`, `hotfix.md`, and `pdf-rewrite-plan.md` Steps 1–3 land in the same PR)

---

## Division of labour

| Concern | Owner |
|---------|-------|
| `vat_scenario_sub_code` migration + backfill (invoice + credit-note + debit-note) | `pdf-rewrite-plan.md` Step 2 |
| `VatScenario::DomesticExempt` enum case | `pdf-rewrite-plan.md` Step 3 |
| PDF rendering of the legal-basis line | `pdf-rewrite-plan.md` Step 6 (`_vat-treatment.blade.php` component) |
| Form toggle + sub-code Select | **this plan — Step A** |
| Items RM rate restriction | **this plan — Step B** |
| Service routing + signature extension + view-page wiring | **this plan — Step C** |
| Feature tests for scenario semantics | **this plan — Step D** |

Cross-references to `pdf-rewrite-plan.md` Step numbers are load-bearing — keep them intact when editing either plan.

---

## Prerequisites

- [ ] `legal-references.md` shipped — 16 BG rows seeded, `domestic_exempt/art_39..49` present, `art_39` carries `is_default = true`
- [ ] `hotfix.md` shipped — `country_code` NOT NULL on tenant + partner, immutability guard in place
- [ ] `pdf-rewrite-plan.md` Steps 1–3 landed in the same PR — provides the `vat_scenario_sub_code` column, the `VatScenario::DomesticExempt` enum case, and the PDF components that will render the legal-basis line

---

## Step A — Invoice form: toggle + sub-code Select

**File:** `app/Filament/Resources/CustomerInvoices/Schemas/CustomerInvoiceForm.php`

Add inside the existing `Section::make('Invoice Details')`, after the `is_reverse_charge` Toggle and before the `Pricing & Currency` Section (sits adjacent to the other VAT-treatment flags; `pricing_mode` lives in its own Section below).

```php
use App\Enums\VatScenario;
use App\Models\CompanySettings;
use App\Models\Partner;
use App\Models\VatLegalReference;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;

Toggle::make('is_domestic_exempt')
    ->label(__('invoice-form.domestic_exempt_toggle'))
    ->helperText(__('invoice-form.domestic_exempt_hint'))
    ->live()
    ->dehydrated(false)
    ->visible(function (Get $get): bool {
        $partnerId = $get('partner_id');
        if (! $partnerId) {
            return false;
        }

        $partner = Partner::find($partnerId);
        $tenantCountry = CompanySettings::get('company', 'country_code');

        return $partner && $partner->country_code === $tenantCountry;
    })
    ->afterStateHydrated(function ($component, ?Model $record): void {
        if ($record?->vat_scenario === VatScenario::DomesticExempt) {
            $component->state(true);
        }
    })
    ->afterStateUpdated(function (bool $state, callable $set): void {
        if (! $state) {
            $set('vat_scenario_sub_code', null);

            return;
        }

        $country = CompanySettings::get('company', 'country_code');
        $default = VatLegalReference::forCountry($country)
            ->ofScenario('domestic_exempt')
            ->default()
            ->first();

        if ($default) {
            $set('vat_scenario_sub_code', $default->sub_code);
        }
    }),

Select::make('vat_scenario_sub_code')
    ->label(__('invoice-form.exemption_article'))
    ->options(function (): array {
        $country = CompanySettings::get('company', 'country_code');

        return VatLegalReference::listForScenario($country, 'domestic_exempt')
            ->mapWithKeys(fn ($ref) => [
                $ref->sub_code => "{$ref->legal_reference} — {$ref->getTranslation('description', app()->getLocale(), false)}",
            ])
            ->toArray();
    })
    ->visible(fn (Get $get): bool => (bool) $get('is_domestic_exempt'))
    ->required(fn (Get $get): bool => (bool) $get('is_domestic_exempt')),
```

**Translation keys** to add to `lang/bg/invoice-form.php` and `lang/en/invoice-form.php`:

| Key | BG | EN |
|-----|----|----|
| `domestic_exempt_toggle` | Освободена доставка по ЗДДС | Domestic VAT exemption |
| `domestic_exempt_hint` | Отбележете фактурата като освободена по конкретен член от ЗДДС (чл. 39–49). | Mark this invoice as exempt under a specific ЗДДС article (39–49). |
| `exemption_article` | Основание за освобождаване | Exemption article |

The existing form currently uses hard-coded `->label('...')` strings. If `lang/*/invoice-form.php` does not yet exist, create it as part of this step. Do not retroactively migrate every other label — scope creep.

---

## Step B — Items Relation Manager: 0% rate restriction

**File:** `app/Filament/Resources/CustomerInvoices/RelationManagers/CustomerInvoiceItemsRelationManager.php`

Replace the current `vat_rate_id` Select (hard-coded to `VatRate::active()->orderBy('rate')->pluck('name', 'id')`) with a closure that restricts options when the parent invoice is already in a zero-rate scenario:

```php
use App\Enums\VatScenario;
use App\Models\CompanySettings;

Select::make('vat_rate_id')
    ->label('VAT Rate')
    ->options(function (): array {
        /** @var CustomerInvoice $parent */
        $parent = $this->getOwnerRecord();

        $forcesZero = $parent->vat_scenario === VatScenario::DomesticExempt
            || $parent->vat_scenario === VatScenario::Exempt
            || $parent->is_reverse_charge;

        if ($forcesZero) {
            $country = CompanySettings::get('company', 'country_code');

            $zero = VatRate::query()
                ->where('country_code', $country)
                ->where('rate', 0)
                ->where('is_active', true)
                ->first();

            return $zero ? [$zero->id => $zero->name] : [];
        }

        return VatRate::active()
            ->where('country_code', CompanySettings::get('company', 'country_code'))
            ->orderBy('rate')
            ->pluck('name', 'id')
            ->toArray();
    })
    ->searchable()
    ->required(),
```

**Known gap:** a brand-new draft has no persisted scenario yet, so the RM sees the full list. The service forces 0% at confirmation, so final state is always correct; the UX is merely permissive for a draft. Acceptable for v1; revisit only if users complain.

---

## Step C — Service: signature extension + routing + view-page wiring

### C.1 — Extend `CustomerInvoiceService::confirmWithScenario()` signature

**File:** `app/Services/CustomerInvoiceService.php` (current signature at lines 208–213)

Extend with **two new trailing optional arguments** — preserves backward compatibility for every existing caller.

```php
use App\Enums\VatScenario;
use App\Models\CompanySettings;

public function confirmWithScenario(
    CustomerInvoice $invoice,
    ?array $viesData = null,
    bool $treatAsB2c = false,
    ?ManualOverrideData $override = null,
    bool $isDomesticExempt = false,
    ?string $subCode = null,
): void {
    if ($invoice->status !== DocumentStatus::Draft) {
        throw new DomainException('Only draft invoices can be confirmed.');
    }

    // ... existing over-invoice guard (lines 218–239) unchanged

    // F-023 + F-028 guards added by pdf-rewrite-plan.md Step 10 already sit in
    // this region on the merged branch. Do not re-emit here.

    if ($isDomesticExempt && empty($subCode)) {
        throw new DomainException('DomesticExempt confirmation requires a sub_code.');
    }

    // ... existing VIES / manual-override audit storage (lines 242–254) unchanged

    $tenantIsVatRegistered = (bool) tenancy()->tenant?->is_vat_registered;

    DB::transaction(function () use ($invoice, $treatAsB2c, $tenantIsVatRegistered, $isDomesticExempt, $subCode): void {
        if ($isDomesticExempt) {
            $tenantCountry = CompanySettings::get('company', 'country_code');
            if (empty($tenantCountry)) {
                throw new DomainException('Company country code is not configured. Please set it in Company Settings.');
            }

            $invoice->vat_scenario = VatScenario::DomesticExempt;
            $invoice->vat_scenario_sub_code = $subCode;
            $invoice->is_reverse_charge = false;

            $targetRate = $this->resolveZeroVatRate($tenantCountry);
            $invoice->loadMissing('items');
            foreach ($invoice->items as $item) {
                $item->vat_rate_id = $targetRate->id;
                $item->save();
                $item->setRelation('customerInvoice', $invoice);
                $item->setRelation('vatRate', $targetRate);
                $this->recalculateItemTotals($item);
            }
            $this->recalculateDocumentTotals($invoice);
        } else {
            $this->determineVatType($invoice, $treatAsB2c, $tenantIsVatRegistered);
            $invoice->vat_scenario_sub_code = $this->resolveSubCode($invoice);
        }

        $invoice->status = DocumentStatus::Confirmed;
        $invoice->save();

        // ... existing SO updateInvoicedQuantities + qty_delivered block (lines 264–298) unchanged
    });

    if ($invoice->payment_method === PaymentMethod::Cash) {
        FiscalReceiptRequested::dispatch($invoice);
    }

    // Skip OSS for Exempt and DomesticExempt
    $invoice->loadMissing('partner');
    if (! in_array($invoice->vat_scenario, [VatScenario::Exempt, VatScenario::DomesticExempt], true)) {
        app(EuOssService::class)->accumulate($invoice);
    }
}
```

Keep the thin `confirm()` wrapper at line 320 as-is — it still delegates to `confirmWithScenario()` with the two new args defaulted.

### C.2 — Private helpers for sub-code resolution

Add below `determineVatType()` (after line 382):

```php
use App\Enums\ProductType;

private function resolveSubCode(CustomerInvoice $invoice): ?string
{
    return match ($invoice->vat_scenario) {
        VatScenario::Exempt => 'default',
        VatScenario::EuB2bReverseCharge, VatScenario::NonEuExport => $this->inferGoodsOrServices($invoice),
        default => null,
    };
}

private function inferGoodsOrServices(CustomerInvoice $invoice): string
{
    $invoice->loadMissing('items.productVariant.product');

    $types = $invoice->items
        ->map(fn ($i) => $i->productVariant?->product?->type)
        ->unique()
        ->filter();

    if ($types->count() === 1 && $types->first() === ProductType::Service) {
        return 'services';
    }

    // BG SME majority assumption: goods. Document.
    return 'goods';
}
```

`resolveZeroVatRate()` already exists on the service at line 388. The rate-application loop in `determineVatType()` (lines 371–379) is currently inlined. If we want both `determineVatType()` and the new DomesticExempt branch to share one helper, extract a protected `applyZeroRateToItems(CustomerInvoice $invoice, VatRate $rate): void` **before** wiring the DomesticExempt branch so both callers stay in lock-step. Optional polish; the duplication above is short, local, and acceptable for v1.

### C.3 — View-page wiring

**File:** `app/Filament/Resources/CustomerInvoices/Pages/ViewCustomerInvoice.php`

Read the file before editing — verify the existing confirm-modal pattern for ephemeral fields. `is_domestic_exempt` is `dehydrated(false)`, so it will not survive the form's standard dehydration pipeline as a persisted attribute. Two patterns are common in this repo:

1. **Schema-based confirm modal** with ephemeral fields re-declared on the modal schema — read from `$data` inside `->action(function (array $data) { ... })`.
2. **Inline ephemeral state** on the page Livewire component, read via `mutateFormDataUsing()` from the pre-dehydration snapshot.

Pick whichever the existing page uses. The VIES manual-override flow already models the ephemeral-modal pattern the repo has chosen — follow it. Wire the two new args:

```php
app(CustomerInvoiceService::class)->confirmWithScenario(
    $this->record,
    viesData: $viesData,
    isDomesticExempt: (bool) ($data['is_domestic_exempt'] ?? false),
    subCode: $data['vat_scenario_sub_code'] ?? null,
);
```

Do not invent a new pattern here.

---

## Step D — Tests

### D.1 — `tests/Unit/VatScenarioDomesticExemptTest.php`

```php
<?php

declare(strict_types=1);

use App\Enums\VatScenario;
use App\Models\Partner;

it('DomesticExempt enum case exists', function () {
    expect(VatScenario::tryFrom('domestic_exempt'))->toBe(VatScenario::DomesticExempt);
});

it('requires rate change', function () {
    expect(VatScenario::DomesticExempt->requiresVatRateChange())->toBeTrue();
});

it('determine() never returns DomesticExempt', function () {
    $partner = Partner::factory()->create(['country_code' => 'BG']);

    $result = VatScenario::determine($partner, 'BG', tenantIsVatRegistered: true);

    expect($result)->toBe(VatScenario::Domestic)
        ->and($result)->not->toBe(VatScenario::DomesticExempt);
});
```

### D.2 — `tests/Feature/Invoice/CustomerInvoiceDomesticExemptConfirmationTest.php`

Four tests — scenario semantics only. PDF-rendering lives in `pdf-rewrite-plan.md` Step 13.

1. **Confirms a domestic invoice as DomesticExempt** when `isDomesticExempt=true, subCode='art_45'`. Asserts `vat_scenario === VatScenario::DomesticExempt`, `vat_scenario_sub_code === 'art_45'`, `is_reverse_charge === false`.
2. **Applies 0% rate to items on DomesticExempt confirm** — every item's `vatRate->rate == 0.0` and `vat_amount == 0.00`.
3. **Skips OSS accumulation on DomesticExempt** — `EuOssAccumulation::count()` unchanged before vs after confirmation.
4. **Throws `DomainException`** when `isDomesticExempt=true` and `subCode=null`.

Invoke the service directly (not through the page) — the view-page path is exercised by Step D.3. Use existing factory states (e.g. `CustomerInvoice::factory()->draft()->bgTenant()`). If a `withItems(n)` state does not yet exist on the factory, create items inline or add the state alongside this test.

### D.3 — `tests/Feature/Invoice/CustomerInvoiceFormDomesticExemptTest.php`

Two Livewire form tests (Filament schema).

1. **Shows the DomesticExempt Toggle only for a domestic partner.** Filling the form with a BG partner reveals the Toggle; filling with a DE partner hides it. Assert via `assertFormFieldExists('is_domestic_exempt')` / `assertFormFieldHidden('is_domestic_exempt')`.
2. **Defaults the sub-code Select to `art_39`** (the `is_default = true` seed row) when the Toggle is enabled. Assert via `assertFormSet(['vat_scenario_sub_code' => 'art_39'])`.

Test via `CreateCustomerInvoice` (or the edit / view page exposing the form, whichever matches the repo's existing Filament-test convention). Authenticate an admin in the `beforeEach` per the existing Filament-panel test setup (`Filament::setCurrentPanel('tenant')` if multi-panel — see MEMORY note `feedback_filament_multi_panel_testing`).

---

## Gotchas

1. **`is_domestic_exempt` is ephemeral.** The Toggle is `dehydrated(false)`; the only source of truth on a persisted record is `vat_scenario === VatScenario::DomesticExempt`. The `afterStateHydrated` callback hydrates the Toggle on edit from the persisted scenario — keep it intact on any refactor.
2. **Sub-code default comes from the seed, not a hard-coded `'art_39'`.** `VatLegalReference::forCountry($c)->ofScenario('domestic_exempt')->default()->first()` reads the `is_default` flag. Keeps re-seeding safe — if the statutory default article ever changes, only the seeder is updated.
3. **Items RM reactivity gap.** For a fresh draft the scenario isn't stored yet, so the option closure sees `vat_scenario === null` and returns the full rate list. The service forces 0% at confirmation, so correctness is guaranteed. Document; do not over-engineer.
4. **`resolveSubCode()` heuristic.** Mixed goods + services reverse-charge or non-EU-export invoices default to `'goods'`. BG SME majority assumption, aligned with the `VatLegalReferenceSeeder` defaults (both `eu_b2b_reverse_charge/goods` and `non_eu_export/goods` carry `is_default = true`). Per-line sub-code discrimination is future work.
5. **`applyZeroRateToItems()` potential extraction.** The rate-application loop is inlined today inside `determineVatType()` (lines 371–379). The DomesticExempt branch duplicates it. If both need to diverge later (e.g. per-line sub-codes), extract cleanly first; otherwise the short local duplication is fine for v1.
6. **View-page wiring depends on the existing confirm-modal pattern.** Verify by reading `ViewCustomerInvoice.php` before implementing — the VIES manual-override flow already models the pattern the repo has chosen.
7. **Translation file presence.** `lang/bg/invoice-form.php` and `lang/en/invoice-form.php` may not yet exist (the form currently uses hard-coded `->label(...)` strings). If missing, create them alongside Step A with the three keys listed. Do not retroactively migrate every other label in the form.

---

## Exit Criteria

- [ ] All tests from Step D pass in isolation
- [ ] Full suite green — `./vendor/bin/sail artisan test --parallel --compact`
- [ ] Manual: BG tenant creates a domestic invoice → flips the "Освободена доставка по ЗДДС" Toggle → picks `чл. 45 ЗДДС` → confirms → rendered PDF shows `чл. 45 ЗДДС — Доставка, свързана със земя и сгради` (requires `pdf-rewrite-plan.md` Step 6 merged in the same PR)
- [ ] Pint clean — `vendor/bin/pint --dirty --format agent`
- [ ] Checklist in `domestic-exempt.md` ticked (items owned by this plan: form + items RM + service routing + view-page wiring + tests)
