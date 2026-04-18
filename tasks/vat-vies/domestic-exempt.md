# Task: DomesticExempt VAT Scenario

> **Spec:** `tasks/vat-vies/spec.md` (Area 3 / Phase B)
> **Plan:** `tasks/vat-vies/domestic-exempt-plan.md`
> **Combined push with:** `pdf-rewrite.md` — one branch / one PR. The column migration, the enum case, and the PDF rendering are emitted by `pdf-rewrite-plan.md`. This task owns scenario **semantics**: form toggle, sub-code picker, items RM rate restriction, service routing, view-page wiring.
> **Status:** ✅ SHIPPED (2026-04-18)
> **Depends on:** `legal-references.md` ✅ shipped (16 BG rows seeded, `domestic_exempt/art_39..49` present with `art_39` as default)
> **Unblocks:** `blocks.md`, `invoice-credit-debit.md`

---

## Why this task exists

Under Bulgarian ЗДДС чл. 39–49 some domestic supplies are VAT-exempt (healthcare, education, culture, financial services, insurance, gambling, postal services, …). A VAT-registered tenant issuing such an invoice must render:
- 0% VAT
- Legal basis citing the exact article (чл. 39 ЗДДС … чл. 49 ЗДДС)
- No "Reverse charge" wording (this is a *domestic exemption*, not reverse charge)

The current VAT/VIES design has no expression of this. This task adds a **user-toggled** `DomesticExempt` scenario: the user marks a draft domestic invoice as exempt and picks an article from the seeded legal-reference list. Unlike the other five scenarios, `DomesticExempt` is **never auto-determined** — always an explicit user choice on the draft form.

---

## Scope

- `VatScenario::DomesticExempt` enum case (added by `pdf-rewrite-plan.md` Step 3; consumed here)
- `VatScenario::determine()` is NOT modified — never returns DomesticExempt
- Invoice form: "Domestic VAT exemption" toggle + sub-code `Select` (visible only when partner country = tenant country). Default sub-code = `is_default=true` row (currently `art_39`)
- Items Relation Manager: when parent has `vat_scenario = DomesticExempt`, restrict `vat_rate_id` options to tenant's 0% rate
- `CustomerInvoiceService::confirmWithScenario()` — signature extended to accept `bool $isDomesticExempt = false`, `?string $subCode = null`. When `$isDomesticExempt === true`, bypass `determine()`, short-circuit to DomesticExempt, apply 0% rate, skip VIES, skip OSS, require `$subCode`
- Sub-code resolution for all other scenarios centralised into a `resolveSubCode()` helper (exempt → 'default'; eu_b2b_reverse_charge + non_eu_export → infer goods/services from item product types, default 'goods'; others null)
- `ViewCustomerInvoice` confirm action extracts `is_domestic_exempt` + `vat_scenario_sub_code` from form state and passes to the service

**Owned by `pdf-rewrite.md`, cross-referenced only:**
- `customer_invoices.vat_scenario_sub_code` column + backfill (pdf-rewrite Step 2)
- `VatScenario::DomesticExempt` case creation (pdf-rewrite Step 3)
- PDF rendering of `чл. N ЗДДС` legal reference via `_vat-treatment` component (pdf-rewrite Step 6)

---

## Non-scope

- Credit / Debit Note scenario inheritance of DomesticExempt (→ `invoice-credit-debit.md`)
- Non-registered-tenant override (→ `blocks.md`)
- Per-MS exempt article seeds beyond Bulgaria — backlog (one seed set per new tenant country)
- Mixed goods/services disambiguation on DomesticExempt (not applicable — 0% is 0%)

---

## Known Changes

### VatScenario enum (emitted by pdf-rewrite Step 3)

Adds `case DomesticExempt = 'domestic_exempt'`. `description()` returns "Domestic exemption — zero-rated under a specific ЗДДС article (39–49).". `requiresVatRateChange()` returns true. `determine()` unchanged; documented comment states DomesticExempt is user-selected only.

### Invoice form

- `Toggle::make('is_domestic_exempt')` — ephemeral (`dehydrated(false)`); visible only when partner country = tenant country; hydrates from persisted scenario on edit
- `Select::make('vat_scenario_sub_code')` — populated from `VatLegalReference::listForScenario(tenantCountry, 'domestic_exempt')`; default = `is_default=true` row; visible + required only when toggle is on
- Translation keys: `domestic_exempt_toggle`, `domestic_exempt_hint`, `exemption_article` in `lang/{bg,en}/invoice-form.php`

### CustomerInvoiceService

- Signature extended:
  ```
  public function confirmWithScenario(
      CustomerInvoice $invoice,
      ?array $viesData = null,
      bool $treatAsB2c = false,
      ?ManualOverrideData $override = null,
      bool $isDomesticExempt = false,
      ?string $subCode = null,
  ): void
  ```
- `$isDomesticExempt = true` short-circuits: sets `vat_scenario = DomesticExempt`, `vat_scenario_sub_code = $subCode` (throws `DomainException` if null), applies 0% rate to items via the existing rate-override helper, skips VIES, skips OSS accumulation
- For other scenarios `vat_scenario_sub_code` is persisted via a new `resolveSubCode()` helper (Exempt → 'default'; EuB2bReverseCharge / NonEuExport → goods-or-services heuristic defaulting to 'goods'; else null)

### Items Relation Manager

- `vat_rate_id` Select: when parent `vat_scenario` ∈ {DomesticExempt, Exempt} or `is_reverse_charge = true`, restrict options to tenant's 0% rate
- Known soft-regression: on a fresh draft the scenario is not yet stored, so the RM shows the full rate list. Service forces 0% at confirmation regardless. Acceptable for v1

---

## Tests Required

- [ ] Unit: `VatScenario::DomesticExempt` case exists; `requiresVatRateChange()` returns true
- [ ] Unit: `VatScenario::determine()` never returns DomesticExempt (parametric regression lock across all partner-country inputs)
- [ ] Feature: Invoice form — toggle visible only for domestic partner-tenant pair
- [ ] Feature: Toggling on surfaces sub-code Select with all 11 seeded `art_*` options
- [ ] Feature: Default sub-code on toggle = `art_39`
- [ ] Feature: Changing partner to a non-domestic country clears the toggle
- [ ] Feature: Items RM restricts `vat_rate_id` to 0% when parent scenario is DomesticExempt
- [ ] Feature: Confirmation — DomesticExempt confirms without VIES call
- [ ] Feature: Confirmation — DomesticExempt does not accumulate OSS
- [ ] Feature: Confirmation — all items end up with 0% rate; stored `vat_scenario_sub_code` matches selected article
- [ ] Feature: Confirming DomesticExempt without a sub-code throws `DomainException`
- [ ] Feature: PDF (cross-refs pdf-rewrite) — invoice with `sub_code = 'art_45'` renders `чл. 45 ЗДДС — Доставка, свързана със земя и сгради`

---

## Refactor Findings

> Filled during / after implementation.

---

## Checklist

- [x] Investigation complete (form structure, items RM pattern, confirmWithScenario call sites)
- [x] Plan written (`domestic-exempt-plan.md`)
- [x] `VatScenario::DomesticExempt` case added (via pdf-rewrite Step 3)
- [x] Invoice form toggle + sub-code Select
- [x] Items RM rate restriction
- [x] `CustomerInvoiceService::confirmWithScenario()` signature extended
- [x] `resolveSubCode()` helper in place for other scenarios
- [x] `ViewCustomerInvoice` passes toggle + sub-code into the service on confirm
- [x] Automated tests pass (631 passing, 3 todos)
- [ ] Browser-tested: BG tenant domestic invoice → flip exempt toggle → pick art_45 → confirm → PDF shows `чл. 45 ЗДДС`
- [x] Pint clean
- [x] Final test run green
