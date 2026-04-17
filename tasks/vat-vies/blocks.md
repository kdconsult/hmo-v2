# Task: Non-VAT-Registered Tenant Blocks — Customer Invoices

> **Spec:** `tasks/vat-vies/spec.md` — Area 4
> **Plan:** `tasks/vat-vies/blocks-plan.md`
> **Review:** `review.md` (F-004, F-021, F-022)
> **Status:** 📋 PLANNED
> **Depends on:** `legal-references.md` landed (for the `чл. 113, ал. 9 ЗДДС` notice), `domestic-exempt.md` landed (for `vat_scenario_sub_code`)
> **Unblocks:** `blocks-credit-debit.md` (credit/debit notes reuse the same block logic)

---

## Why this task exists

When a tenant has `is_vat_registered = false`, they legally **cannot**:
- Charge VAT on any invoice
- Apply reverse charge (no VAT ID to stand behind)
- Participate in OSS (no VAT registration at all)
- Use the DomesticExempt scenario (that's a VAT-registered-supplier exemption)

This means every outgoing invoice of a non-registered tenant is formally a **non-VAT document**: 0% VAT, mandatory notice "**не е регистриран по ЗДДС**" and legal basis "**чл. 113, ал. 9 ЗДДС**".

The current implementation short-circuits `VatScenario::determine()` to `Exempt` when tenant VAT is null (done in Area 3). That handles the scenario routing, but the UI surface still shows VAT controls, pricing-mode selector, and reverse-charge toggle — all nonsense for a non-registered tenant. And on the PDF, F-004 surfaced stale citations referencing Art. 96 (wrong article).

This task cleans up the entire UI + PDF + service-layer surface for the non-registered tenant case.

---

## Scope

- `VatScenario::Exempt` — already exists; rename-only edits, no new case
- Invoice form:
  - Hide pricing-mode selector entirely when tenant is non-registered
  - Hide reverse-charge toggle entirely
  - Hide DomesticExempt toggle (can't apply — tenant isn't VAT-registered)
  - VAT-rate field on line items: forced to the 0% exempt rate; user cannot pick another
  - Partner select `helperText`: shows "Exempt — tenant is not VAT-registered" regardless of partner country
  - VIES re-check button: not shown (no reverse charge possible)
- Confirmation flow:
  - VIES check does not run
  - Scenario always `Exempt`
  - `is_reverse_charge` always false
  - OSS accumulation skipped
  - `vat_scenario_sub_code` stored as `'default'` (matches legal-references seed)
- Invoice PDF:
  - No VAT breakdown section
  - Legal notice rendered: **"чл. 113, ал. 9 ЗДДС — Доставки от лице, което не е регистрирано по ЗДДС"** (resolved via `VatLegalReference::resolve(tenantCountry, 'exempt', 'default')`)
  - Heading still localized; all other PDF content normal
- Products & Categories form:
  - VAT rate field visible but options restricted to the tenant's 0% exempt rate
  - Field unlocks automatically if tenant later registers
- Blocking decision is **authoritative at the service layer**, not just the UI. Any direct service call with a non-registered tenant gets the blocks regardless of UI state.

---

## Non-scope

- Credit / debit note blocks (→ `blocks-credit-debit.md`)
- Intra-Community acquisitions by non-registered tenant (чл. 99 ЗДДС self-registration trigger) — backlog
- Reverse-charge liability under чл. 82(5) for non-registered tenants receiving services — backlog (F-033 / inbound protocols)
- Threshold-crossing notification for non-registered tenant approaching чл. 96 (→ `pre-launch.md` F-020)
- Fiscal-receipt tax-group mapping (→ backlog, F-026)

---

## Known Changes

### `VatScenario` enum

No new cases. `Exempt` already carries the short-circuit logic from Area 3. Verify:
- `description()` says "Exempt — tenant is not VAT registered." (ok)
- `requiresVatRateChange()` returns true (ok)
- `determine()` checks `$tenantIsVatRegistered` first and returns `Exempt` before any partner logic (ok)

### Products & Categories forms

- `app/Filament/Resources/Products/Schemas/ProductForm.php` — `vat_rate_id` Select: when `CompanySettings::get('company', 'is_vat_registered')` is false, restrict options to the 0% exempt rate
- Same for `app/Filament/Resources/ProductCategories/Schemas/ProductCategoryForm.php` if a category-level VAT rate is stored

### Invoice form

- Top of the form (or in service layer helper): read `is_vat_registered` once; propagate as a Blade closure / form state
- Pricing-mode selector: `->visible(fn () => tenantIsVatRegistered())`. When hidden, the stored value is ignored by the service (all pricing is treated as final amount because VAT is 0)
- Reverse-charge toggle: `->visible(fn () => tenantIsVatRegistered())`
- Partner select `helperText`: short-circuit to "Exempt — tenant is not VAT-registered" when tenant unregistered
- Items RM: `vat_rate_id` Select options restricted to 0% exempt

### Service layer

- `CustomerInvoiceService::previewScenario()` — first check tenant `is_vat_registered`; return `Exempt` scenario immediately, no VIES call, no OSS computation
- `CustomerInvoiceService::confirmWithScenario()` — same short-circuit; set `vat_scenario = Exempt`, `vat_scenario_sub_code = 'default'`, `is_reverse_charge = false`, skip VIES + OSS
- Confirmation modal: show "Exempt" scenario, no VAT breakdown, total = subtotal

### Invoice PDF

Handled primarily in `pdf-rewrite.md`. This task ensures the right data flows through:
- `$invoice->vat_scenario = VatScenario::Exempt` AND `$invoice->vat_scenario_sub_code = 'default'`
- PDF template resolves `VatLegalReference::resolve(tenantCountry, 'exempt', 'default')` → renders `чл. 113, ал. 9 ЗДДС — Доставки от лице, което не е регистрирано по ЗДДС`
- No VAT breakdown rows in the totals block (only subtotal → total)
- No reverse-charge meta box

### Helper

Create `App\Support\TenantVatStatus::isRegistered(): bool` — single source of truth for the blocks. All UI conditionals and service guards call it:

```php
public static function isRegistered(): bool
{
    return (bool) CompanySettings::get('company', 'is_vat_registered', false);
}
```

This avoids sprinkling `CompanySettings::get(...)` calls and keeps the read path auditable.

---

## Tests Required

- [ ] Unit: `VatScenario::determine()` → `Exempt` when tenant VAT is null, regardless of partner country
- [ ] Feature: Product form — only 0% rate selectable when tenant non-registered; full rate list otherwise
- [ ] Feature: Invoice form — pricing-mode selector hidden when tenant non-registered
- [ ] Feature: Invoice form — reverse-charge toggle hidden when tenant non-registered
- [ ] Feature: Invoice form — DomesticExempt toggle hidden when tenant non-registered
- [ ] Feature: Invoice form — items VAT rate forced to 0% when tenant non-registered
- [ ] Feature: Partner helper text — "Exempt — tenant is not VAT-registered" (regardless of partner country)
- [ ] Feature: Confirmation — VIES service is NOT called
- [ ] Feature: Confirmation — OSS accumulation is skipped (OSS count unchanged)
- [ ] Feature: Confirmation — `vat_scenario = Exempt`, `vat_scenario_sub_code = 'default'`, `is_reverse_charge = false`
- [ ] Feature: Confirmation modal shows "Exempt" scenario, no VAT breakdown, total = subtotal
- [ ] Feature: PDF renders `чл. 113, ал. 9 ЗДДС` legal notice (not `Art. 96 ЗДДС`)
- [ ] Feature: PDF has no VAT breakdown block
- [ ] Feature: Tenant later registers (flips `is_vat_registered = true`) — UI unlocks, new invoices flow through normal scenario determination
- [ ] Regression: An existing Confirmed `Exempt` invoice issued under a non-registered tenant still renders correctly after this task's PDF changes

---

## Refactor Findings

> Filled during / after implementation.

---

## Checklist

- [ ] Investigation complete
- [ ] Plan written (`blocks-plan.md`)
- [ ] `TenantVatStatus` helper created
- [ ] Form + items RM blocks landed
- [ ] Service short-circuit in place
- [ ] Product + category forms restricted
- [ ] PDF renders correct legal notice (cross-verified against `pdf-rewrite.md`)
- [ ] Automated tests pass
- [ ] Browser-tested: non-registered tenant creates → confirms → prints invoice
- [ ] Browser-tested: tenant flips to registered → form reopens full VAT controls
- [ ] Pint clean
- [ ] Final test run green
