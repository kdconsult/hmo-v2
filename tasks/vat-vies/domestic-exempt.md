# Task: DomesticExempt VAT Scenario

> **Spec:** `tasks/vat-vies/spec.md`
> **Plan:** `tasks/vat-vies/domestic-exempt-plan.md`
> **Status:** 📋 PLANNED
> **Depends on:** `legal-references.md` landed (`VatLegalReference::listForScenario()` must return BG rows for scenario `domestic_exempt`)
> **Unblocks:** `blocks.md` (shares the sub-code column), `invoice-credit-debit.md` (credit/debit notes carry the same sub-code)

---

## Why this task exists

Under BG ЗДДС **чл. 39–49**, certain domestic supplies are **exempt from VAT** (healthcare, education, cultural, financial, insurance, gambling, postal, …). A VAT-registered tenant supplying such goods / services issues an invoice with:
- 0% VAT on the face
- Legal reference line citing the specific article (`чл. 39 ЗДДС`, `чл. 45 ЗДДС`, etc.)
- No "Reverse charge" wording — this is not reverse charge; it is a domestic exemption

The current VAT/VIES design (Areas 1–3) has no expression of this. A tenant selling, say, a medical device domestically must currently either (a) issue a standard 20% invoice (wrong), or (b) manually zero-out line VAT (wrong — legal basis line is missing).

This task adds a **user-toggled** `DomesticExempt` scenario: user explicitly marks a draft invoice as exempt and picks the applicable article. The scenario is never auto-determined (unlike the other five) — always an explicit user choice on the draft form.

---

## Scope

- Add `vat_scenario_sub_code` nullable string column on `customer_invoices` — stores the sub-code (`art_39`..`art_49` for DomesticExempt; `goods`/`services` for EU B2B / Non-EU Export; `default` for others)
- Add `DomesticExempt` case to `VatScenario` enum — but `determine()` NEVER returns it
- Invoice form: "Domestic exemption" toggle (visible only when tenant + partner are both domestic, i.e. scenario would otherwise be `Domestic`). Toggling on: select sub-code from `VatLegalReference::listForScenario(tenantCountry, 'domestic_exempt')`
- Items relation manager: when `DomesticExempt` is toggled, force line VAT rate to 0% exempt
- `CustomerInvoiceService::confirmWithScenario()`: route `DomesticExempt` path — skip VIES, skip OSS, apply 0% rate to items, store sub-code
- PDF: render legal reference line from `VatLegalReference::resolve(country, 'domestic_exempt', $invoice->vat_scenario_sub_code)`
- Backfill migration: existing `eu_b2b_reverse_charge` and `non_eu_export` invoices default their sub-code to `'goods'` (safer assumption for BG SMEs; documented trade-off)

---

## Non-scope

- Credit / debit note inheritance of DomesticExempt (→ `invoice-credit-debit.md`)
- Non-registered tenant blocks override (→ `blocks.md`)
- Per-MS domestic-exempt article seeds (currently BG only; backlog for expansion)
- Mixed-items (goods + services) radio on DomesticExempt — not needed (DomesticExempt items don't need goods/services discrimination)

---

## Known Changes

### Data model — `customer_invoices`

Add:
- `vat_scenario_sub_code` — nullable string. For existing confirmed invoices: backfilled per scenario rules below. For new invoices: defaults to `'default'` at save unless the scenario prescribes otherwise.

**Backfill rules (in migration):**
- `vat_scenario = 'exempt'` → `'default'`
- `vat_scenario = 'domestic'` → `null` (not applicable)
- `vat_scenario = 'eu_b2b_reverse_charge'` → `'goods'`
- `vat_scenario = 'eu_b2c_under_threshold'` → `null`
- `vat_scenario = 'eu_b2c_over_threshold'` → `null`
- `vat_scenario = 'non_eu_export'` → `'goods'`

Document the "`goods`" default for legacy reverse-charge / export invoices. If any tenant has historically issued service-only reverse-charge or export invoices, they must correct the sub-code manually (or via a one-off remediation command).

### VatScenario enum

Add:
- `case DomesticExempt = 'domestic_exempt';`
- `description()` → "Domestic exemption — zero-rated under a specific ЗДДС article."
- `requiresVatRateChange()` → true
- `determine()` — **NOT modified**; never auto-returns DomesticExempt. User-selected only.

### CustomerInvoiceForm

- New `Toggle::make('is_domestic_exempt')` — visible only when partner-country = tenant-country AND toggle unset at form start
- When toggled ON:
  - Show `Select::make('vat_scenario_sub_code')` populated from `VatLegalReference::listForScenario(tenantCountry, 'domestic_exempt')->pluck('legal_reference', 'sub_code')` with the description as helper text
  - Default selected sub-code = the `is_default = true` row (i.e. `art_39`)
- When toggled OFF: clear sub-code, revert to normal Domestic scenario
- Reactive on partner selection — clears the toggle if partner changes to a non-domestic scenario

### CustomerInvoiceService

- `determineVatType()` — if `$invoice->is_domestic_exempt_flag` (form input, not a persisted column) → apply `VatScenario::DomesticExempt` directly, bypass `VatScenario::determine()`
- `confirmWithScenario()` — store `vat_scenario` and `vat_scenario_sub_code` on the invoice; apply 0% rate to items (via `applyZeroRateToItems()` reused from reverse-charge path); skip VIES + OSS
- `applyZeroRateToItems()` — already exists; reused. Needs to accept a tenant country parameter for `resolveZeroVatRate()` (verify)

### Items Relation Manager

- When parent invoice has `vat_scenario = 'domestic_exempt'` (loaded from DB on edit / from form state on new):
  - `vat_rate_id` Select options restricted to the `'0% exempt'` VatRate for the tenant country
  - User cannot select any other rate

### Invoice PDF (downstream dependency — handled in `pdf-rewrite.md`)

- `VatLegalReference::resolve(tenantCountry, 'domestic_exempt', $invoice->vat_scenario_sub_code)` returns the row
- Render: `чл. 39 ЗДДС — Доставки, свързани със здравеопазване` (or whichever article was picked)
- No VAT breakdown block; no reverse-charge wording

---

## Tests Required

- [ ] Unit: `VatScenario::DomesticExempt` case exists; `requiresVatRateChange()` returns true; `description()` returns correct text
- [ ] Unit: `VatScenario::determine()` NEVER returns DomesticExempt (regression test — all 5 scenarios covered, DomesticExempt not reachable)
- [ ] Feature: Invoice form — toggle visible only for domestic partner-tenant pair
- [ ] Feature: Invoice form — toggling ON surfaces sub-code Select populated with 11 rows (art_39..art_49)
- [ ] Feature: Invoice form — default sub-code is `art_39` (the `is_default=true` row)
- [ ] Feature: Invoice form — changing partner to a non-domestic country clears the toggle
- [ ] Feature: Items RM — when scenario is DomesticExempt, `vat_rate_id` options are restricted to 0% exempt
- [ ] Feature: Confirmation — DomesticExempt invoice confirms without VIES call, without OSS accumulation
- [ ] Feature: Confirmation — `vat_scenario_sub_code` is stored correctly (e.g. `art_45`)
- [ ] Feature: Confirmation — all items have `vat_rate_id` pointing to 0% exempt rate after confirmation
- [ ] Feature: PDF renders `чл. 45 ЗДДС — Доставка, свързана със земя и сгради` for an invoice with sub-code `art_45`
- [ ] Feature: Backfill migration — existing reverse-charge invoices get `sub_code = 'goods'`, existing non-EU-export invoices get `sub_code = 'goods'`, existing exempt invoices get `sub_code = 'default'`

---

## Refactor Findings

> Filled during / after implementation.

---

## Checklist

- [ ] Investigation complete (current form structure, items RM pattern)
- [ ] Plan written (`domestic-exempt-plan.md`)
- [ ] Migration for `vat_scenario_sub_code` + backfill
- [ ] Enum case added
- [ ] Form + items RM updated
- [ ] Service routing for DomesticExempt
- [ ] PDF rendering (partial — depends on `pdf-rewrite.md`)
- [ ] Automated tests pass
- [ ] Browser-tested: BG tenant creates DomesticExempt invoice for art. 45 → PDF shows correct citation
- [ ] Pint clean
- [ ] Final test run green
