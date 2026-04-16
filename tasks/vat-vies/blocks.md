# Task: Non-VAT-Registered Tenant Blocks

> **Spec:** `tasks/vat-vies/spec.md` — Area 4
> **Plan:** `tasks/vat-vies/blocks-plan.md` (created when ready to build)
> **Status:** Discussion complete — ready to plan

---

## Scope

Implement the app-wide restrictions that apply when a tenant is not VAT registered (`is_vat_registered = false`). Covers `VatScenario` enum extension, product/category forms, invoice form, confirmation flow, and invoice PDF. All outgoing document types are affected.

---

## What Needs Investigation Before Planning

- [ ] Confirm which outgoing document types share the same form/confirmation infrastructure as customer invoices (credit notes, debit notes, proforma — do they all go through the same service layer?)
- [ ] Check if a "0% Exempt" VatRate record already exists or needs to be seeded
- [ ] Confirm how `is_vat_registered` is read — from `CompanySettings` or from the tenant model directly
- [ ] Verify the correct ЗДДС article for the PDF notice (Art. 96 is the registration threshold article — confirm it covers voluntary non-registration too, or whether Art. 97 applies)
- [ ] Check the current invoice PDF template — locate the VAT breakdown section that needs to be conditional

---

## Known Changes

### VatScenario enum

- Add `Exempt = 'exempt'` case
- Update `determine()` to accept tenant VAT status as a parameter and check it first
- Update `description()` to handle the new case
- Update `requiresVatRateChange()` — `Exempt` returns `false` (rates are already 0%)

### Products & Categories forms

- VAT rate field: keep visible but restrict options to the single "0% — Exempt" rate when tenant is non-registered
- Condition applied at form build time by reading `CompanySettings`

### CustomerInvoiceForm (and equivalent forms for other outgoing documents)

- **Pricing mode selector**: hidden when `is_vat_registered = false`
- **VAT rate on line items**: forced to 0% exempt rate; field disabled/hidden
- **Partner select `helperText`**: short-circuit to "Exempt — not VAT registered" before calling `VatScenario::determine()`
- **VIES re-check button** (for pending partners): not rendered when non-registered
- **`is_reverse_charge` toggle**: not rendered when non-registered

### CustomerInvoiceService (confirmation flow)

- At the top of `previewScenario()` and `applyAndConfirm()`: check `is_vat_registered`
- If `false`: set `vat_scenario = exempt`, `is_reverse_charge = false`, skip VIES, skip OSS accumulation
- No changes to the existing 5-scenario path

### Invoice PDF template

- VAT breakdown section: conditional on `vat_scenario !== exempt`
- When exempt: render legal notice line — "Not subject to VAT — Art. [X] ЗДДС"

---

## Tests Required

- [ ] Unit: `VatScenario::determine()` — `exempt` returned first regardless of partner country/status
- [ ] Unit: `VatScenario::requiresVatRateChange()` — `exempt` returns `false`
- [ ] Feature: Product form — only 0% rate selectable when tenant non-registered
- [ ] Feature: Invoice form — pricing mode hidden, VAT rate locked when non-registered
- [ ] Feature: Invoice confirmation — VIES not called, `is_reverse_charge = false`, `vat_scenario = exempt` stored
- [ ] Feature: OSS accumulation skipped when exempt
- [ ] Feature: Confirmation modal shows "Exempt" scenario, no VAT line, total = subtotal
- [ ] Feature: Invoice PDF — no VAT breakdown, legal notice present when exempt

---

## Refactor Findings

> Filled during / after implementation.

---

## Checklist

- [ ] Investigation complete
- [ ] Plan written (`blocks-plan.md`)
- [ ] Implementation complete
- [ ] Automated tests pass
- [ ] Code review clean
- [ ] Browser tested (manual)
- [ ] Refactor findings written
- [ ] Refactor implemented
- [ ] Pint clean
- [ ] Final test run
