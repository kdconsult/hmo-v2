# Plan: Non-VAT-Registered Tenant Blocks — Customer Invoices

> **Task:** `tasks/vat-vies/blocks.md`
> **Review:** `review.md` (F-004, F-022)
> **Status:** ✅ SHIPPED (2026-04-18)

---

## Prerequisites

- [ ] `legal-references.md` merged (`VatLegalReference` table seeded with the `exempt / default / чл. 113, ал. 9 ЗДДС` row)
- [ ] `domestic-exempt.md` merged (`vat_scenario_sub_code` column exists; `DomesticExempt` toggle added to form)
- [ ] `hotfix.md` merged (country_code not null; immutability)
- [ ] `pdf-rewrite.md` ideally merged (PDF uses `VatLegalReference::resolve` for the exempt notice)

---

## Step 1 — `TenantVatStatus` helper

**File:** `app/Support/TenantVatStatus.php`

```php
<?php

namespace App\Support;

use App\Models\CompanySettings;
use App\Models\VatRate;

class TenantVatStatus
{
    public static function isRegistered(): bool
    {
        // is_vat_registered lives on the tenants table; CompanySettings reads it via group 'company'.
        // Verify exact key — may be CompanySettings::get('company', 'is_vat_registered') or via tenancy()->tenant.
        $tenant = tenancy()->tenant;
        return (bool) ($tenant?->is_vat_registered ?? false);
    }

    public static function country(): ?string
    {
        return tenancy()->tenant?->country_code;
    }

    /**
     * The tenant's 0% exempt rate. Created on demand if missing (should be seeded; defensive).
     */
    public static function zeroExemptRate(): VatRate
    {
        $country = static::country();

        return VatRate::firstOrCreate(
            ['country_code' => $country, 'rate' => 0, 'type' => 'zero'],
            ['name' => '0% — Exempt', 'is_active' => true, 'is_default' => false],
        );
    }
}
```

**Usage pattern:** anywhere in UI / service that asks "is tenant VAT-registered?" → `TenantVatStatus::isRegistered()`. Don't duplicate the read.

---

## Step 2 — Product form VAT-rate restriction

**File:** `app/Filament/Resources/Products/Schemas/ProductForm.php`

Find the `vat_rate_id` Select. Wrap options:

```php
Select::make('vat_rate_id')
    ->label('VAT Rate')
    ->options(function () {
        if (!TenantVatStatus::isRegistered()) {
            $zero = TenantVatStatus::zeroExemptRate();
            return [$zero->id => $zero->name];
        }

        return VatRate::active()
            ->forCountry(TenantVatStatus::country())
            ->pluck('name', 'id');
    })
    ->default(function () {
        if (!TenantVatStatus::isRegistered()) {
            return TenantVatStatus::zeroExemptRate()->id;
        }
        return VatRate::active()->forCountry(TenantVatStatus::country())->default()->first()?->id;
    })
    ->required(),
```

**File:** `app/Filament/Resources/ProductCategories/Schemas/ProductCategoryForm.php` (if categories have their own VAT rate field — verify first)

Same pattern.

---

## Step 3 — Invoice form blocks

**File:** `app/Filament/Resources/CustomerInvoices/Schemas/CustomerInvoiceForm.php`

Add visibility guards on the relevant fields:

```php
use App\Support\TenantVatStatus;

// Pricing-mode selector (existing):
Select::make('pricing_mode')
    ->label('Pricing Mode')
    ->options([...])
    ->visible(fn () => TenantVatStatus::isRegistered())
    ->disabled(fn (Get $get) => ...current disable logic...),  // keep existing reactivity

// Reverse-charge toggle (existing):
Toggle::make('is_reverse_charge')
    ->visible(fn () => TenantVatStatus::isRegistered())
    ->disabled(),  // existing

// DomesticExempt toggle (from domestic-exempt.md):
Toggle::make('is_domestic_exempt')
    ->visible(function (Get $get) {
        // Add tenant-registration gate to the existing domestic-only gate
        if (!TenantVatStatus::isRegistered()) {
            return false;
        }
        $partner = Partner::find($get('partner_id'));
        return $partner && $partner->country_code === TenantVatStatus::country();
    }),

// Partner select helperText — short-circuit:
Select::make('partner_id')
    ->helperText(function (Get $get) {
        if (!TenantVatStatus::isRegistered()) {
            return __('invoice-form.exempt_non_registered_tenant');  // "Exempt — tenant is not VAT registered"
        }
        // ... existing scenario-based helper text logic
    }),
```

**Translation key:** add `'exempt_non_registered_tenant' => 'Exempt — tenant is not VAT-registered'` in `lang/{bg,en}/invoice-form.php` (BG: `'Освободено — лицето не е регистрирано по ЗДДС'`).

---

## Step 4 — Items Relation Manager VAT-rate restriction

**File:** `app/Filament/Resources/CustomerInvoices/RelationManagers/CustomerInvoiceItemsRelationManager.php`

Extend the `vat_rate_id` options closure started in `domestic-exempt-plan.md` Step 4:

```php
Select::make('vat_rate_id')
    ->options(function () {
        if (!TenantVatStatus::isRegistered()) {
            $zero = TenantVatStatus::zeroExemptRate();
            return [$zero->id => $zero->name];
        }

        // ... existing logic for DomesticExempt / reverse-charge / normal
    })
    ->default(fn () => !TenantVatStatus::isRegistered()
        ? TenantVatStatus::zeroExemptRate()->id
        : null),
```

Same override on any "import from" action that copies items into the invoice (e.g. from a sales order) — after copy, if tenant non-registered, rewrite each copied line's `vat_rate_id` to the 0% exempt rate.

---

## Step 5 — Service layer short-circuit

**File:** `app/Services/CustomerInvoiceService.php`

`previewScenario()` and `confirmWithScenario()` — first-line guard:

```php
public function previewScenario(CustomerInvoice $invoice): array
{
    if (!TenantVatStatus::isRegistered()) {
        return [
            'scenario' => VatScenario::Exempt,
            'sub_code' => 'default',
            'is_reverse_charge' => false,
            'vies_result' => null,
            'vies_request_id' => null,
            'preview_subtotal' => $invoice->subtotal,
            'preview_vat' => '0.00',
            'preview_total' => $invoice->subtotal,
        ];
    }

    // ... existing logic
}

public function confirmWithScenario(CustomerInvoice $invoice, ...): void
{
    DB::transaction(function () use ($invoice) {
        if (!TenantVatStatus::isRegistered()) {
            $this->applyZeroRateToItems($invoice, TenantVatStatus::country());
            $invoice->update([
                'vat_scenario' => VatScenario::Exempt,
                'vat_scenario_sub_code' => 'default',
                'is_reverse_charge' => false,
                'vies_result' => null,
                'vies_request_id' => null,
                'vies_checked_at' => null,
                'status' => DocumentStatus::Confirmed,
            ]);
            // Skip VIES, skip OSS.
            return;
        }

        // ... existing logic
    });
}
```

---

## Step 6 — Confirmation modal display

**File:** `app/Filament/Resources/CustomerInvoices/Pages/ViewCustomerInvoice.php`

Before rendering the standard confirmation modal, branch on tenant status:

```php
if (!TenantVatStatus::isRegistered()) {
    // Simpler modal — no VIES, no reverse-charge acknowledgement
    Section::make([
        TextEntry::make('scenario')->state('Exempt — tenant is not VAT-registered'),
        TextEntry::make('legal_basis')->state('чл. 113, ал. 9 ЗДДС'),
        Grid::make(3)->schema([
            TextEntry::make('subtotal')->money(),
            TextEntry::make('vat')->state('0.00')->label('VAT'),
            TextEntry::make('total')->state($invoice->subtotal),
        ]),
    ]);
}
```

---

## Step 7 — PDF rendering (verification only)

**File:** `resources/views/pdf/customer-invoice.blade.php` (handled in `pdf-rewrite-plan.md`)

Verify after that task lands:
- Exempt invoice renders `чл. 113, ал. 9 ЗДДС — Доставки от лице, което не е регистрирано по ЗДДС` (NOT Art. 96)
- No VAT breakdown rows
- No reverse-charge meta box

If `pdf-rewrite.md` has already landed, just run a PDF test against an Exempt invoice to confirm.

---

## Tests

**File:** `tests/Unit/TenantVatStatusTest.php`

```php
use App\Support\TenantVatStatus;

it('returns false when tenant is_vat_registered is null', function () {
    tenancy()->tenant->update(['is_vat_registered' => null]);
    expect(TenantVatStatus::isRegistered())->toBeFalse();
});

it('returns true when tenant is VAT-registered', function () {
    tenancy()->tenant->update(['is_vat_registered' => true]);
    expect(TenantVatStatus::isRegistered())->toBeTrue();
});

it('zeroExemptRate finds or creates the 0% rate for the tenant country', function () {
    $rate = TenantVatStatus::zeroExemptRate();
    expect($rate->rate)->toBe(0.0)
        ->and($rate->country_code)->toBe(tenancy()->tenant->country_code);
});
```

**File:** `tests/Feature/InvoiceBlocksNonRegisteredTenantTest.php`

```php
use App\Enums\VatScenario;
use App\Services\CustomerInvoiceService;
use function Pest\Livewire\livewire;

beforeEach(function () {
    tenancy()->tenant->update(['is_vat_registered' => false, 'vat_number' => null]);
});

it('confirms a non-registered tenant invoice as Exempt without VIES', function () {
    $invoice = CustomerInvoice::factory()->draft()->domestic()->create();

    app(CustomerInvoiceService::class)->confirmWithScenario($invoice);

    $invoice->refresh();
    expect($invoice->vat_scenario)->toBe(VatScenario::Exempt)
        ->and($invoice->vat_scenario_sub_code)->toBe('default')
        ->and($invoice->is_reverse_charge)->toBeFalse()
        ->and($invoice->vies_request_id)->toBeNull();
});

it('skips OSS accumulation for non-registered tenant', function () {
    $before = EuOssAccumulation::count();
    $invoice = CustomerInvoice::factory()->draft()->euPartner()->create();

    app(CustomerInvoiceService::class)->confirmWithScenario($invoice);

    expect(EuOssAccumulation::count())->toBe($before);
});

it('invoice form hides pricing-mode when tenant is non-registered', function () {
    livewire(CreateCustomerInvoice::class)
        ->assertFormFieldHidden('pricing_mode')
        ->assertFormFieldHidden('is_reverse_charge')
        ->assertFormFieldHidden('is_domestic_exempt');
});

it('items RM restricts vat_rate_id to 0% exempt when tenant non-registered', function () {
    $invoice = CustomerInvoice::factory()->draft()->create();

    livewire(EditCustomerInvoice::class, ['record' => $invoice->id])
        // ... assert the Select options on the items RM contain only the 0% exempt rate
    ;
});

it('partner helper text is short-circuited to Exempt regardless of country', function () {
    $euPartner = Partner::factory()->create(['country_code' => 'DE']);

    livewire(CreateCustomerInvoice::class)
        ->fillForm(['partner_id' => $euPartner->id])
        ->assertSee('Exempt — tenant is not VAT-registered');
});

it('confirmation modal shows Exempt and no VAT breakdown', function () {
    $invoice = CustomerInvoice::factory()->draft()->create();

    livewire(ViewCustomerInvoice::class, ['record' => $invoice->id])
        ->call('confirmInvoice')
        ->assertSee('Exempt — tenant is not VAT-registered')
        ->assertSee('чл. 113, ал. 9 ЗДДС');
});

it('rendered PDF shows correct legal notice', function () {
    $invoice = CustomerInvoice::factory()->confirmed()->exempt()->create();
    $html = view('pdf.customer-invoice', compact('invoice'))->render();
    expect($html)
        ->toContain('чл. 113, ал. 9 ЗДДС')
        ->and($html)->not->toContain('Art. 96');
});
```

**File:** `tests/Feature/ProductFormVatRateRestrictionTest.php`

```php
it('product form restricts vat_rate_id to 0% when tenant non-registered', function () {
    tenancy()->tenant->update(['is_vat_registered' => false]);

    livewire(CreateProduct::class)
        ->assertFormFieldExists('vat_rate_id');
    // Inspect the Select options — should have exactly one, the 0% exempt rate
});

it('product form shows full VAT rate list when tenant VAT-registered', function () {
    tenancy()->tenant->update(['is_vat_registered' => true, 'vat_number' => 'BG123456789']);

    livewire(CreateProduct::class)
        ->assertFormFieldExists('vat_rate_id');
    // Options should include 20%, 9%, 0%
});
```

---

## Gotchas / load-bearing details

1. **Blocks are authoritative at the service layer**, not the UI. A user with DevTools can unhide fields or call the API directly — the service must refuse. `confirmWithScenario()` short-circuit is the guarantee.
2. **Tenant flips registration mid-operation.** If a tenant is non-registered at invoice creation but registers before confirmation, the scenario at confirmation time wins. The service reads `TenantVatStatus::isRegistered()` at confirmation, not at draft-create. This is correct — legal treatment is determined at chargeable event.
3. **Existing Exempt invoices from before this task** — if Area 3 already confirmed any Exempt invoices, they have `vat_scenario = Exempt` but likely `vat_scenario_sub_code = NULL` (column not yet backfilled per `domestic-exempt` Step 1). The backfill migration already sets these to `'default'`. Regression test covers.
4. **Seeded 0% exempt rate exists already** via `VatRateSeeder` (per existing tenant seeder). `zeroExemptRate()` finds it; `firstOrCreate` is defensive.
5. **DomesticExempt is DIFFERENT from Exempt.** DomesticExempt = VAT-registered supplier selling an exempt category. Exempt = supplier not VAT-registered at all. Both produce 0% invoices but cite different articles. Don't merge them.
6. **Reverse-charge liability** — under чл. 82(5) ЗДДС a non-registered BG tenant who receives EU services can still be liable for VAT as the recipient (issues a чл. 117 protocol). That's **inbound**, out of scope for this task; flagged in backlog.

---

## Exit Criteria

- [ ] All tests pass
- [ ] Full suite green
- [ ] Manual: tenant non-registered → create invoice → PDF shows `чл. 113, ал. 9 ЗДДС`; form hides pricing-mode, RC, DomesticExempt
- [ ] Manual: flip tenant to registered → form shows all controls; new invoices use normal scenarios
- [ ] Pint clean
- [ ] Checklist in `blocks.md` ticked
