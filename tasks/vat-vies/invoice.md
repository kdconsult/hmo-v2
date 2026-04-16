# Task: Invoice VAT Determination

> **Spec:** `tasks/vat-vies/spec.md` — Area 3
> **Plan:** `tasks/vat-vies/invoice-plan.md` (created when ready to build)
> **Status:** Discussion complete — ready to plan

---

## Scope

Implement VAT scenario determination, VIES re-validation at confirmation, and the confirmation UI flow on customer invoices. Covers data model, service layer, UI, and audit trail. Replaces the old `$viesInvalidDetected` / `$treatAsB2c` approach entirely.

---

## What Needs Investigation Before Planning

- [ ] What columns exist today on `customer_invoices` for VAT? (`is_reverse_charge`, any `vat_scenario` column already?)
- [ ] Does `ViesValidationService::callVies()` currently capture and return `requestIdentifier` from the SOAP response? If not, add it.
- [ ] What RBAC roles/permissions exist — which role should gate the reverse charge manual override opt-in?
- [ ] Does `EuOssAccumulation::isThresholdExceeded()` work correctly standalone for scenario determination?
- [ ] Confirm `VatScenario::determine()` currently uses `hasValidEuVat()` — verify the exact call site and update plan accordingly.

---

## Known Changes

### Data model — `customer_invoices`

Add columns:
- `vat_scenario` — enum (`domestic` / `eu_b2b_reverse_charge` / `eu_b2c_under_threshold` / `eu_b2c_over_threshold` / `non_eu_export`); nullable until confirmed; frozen at confirmation
- `vies_request_id` — nullable string; from VIES SOAP `requestIdentifier`; null when VIES unavailable
- `vies_checked_at` — nullable timestamp; when the confirmation-time check ran
- `vies_result` — enum (`valid` / `invalid` / `unavailable`); result of that check
- `reverse_charge_manual_override` — boolean, default false
- `reverse_charge_override_user_id` — nullable FK → users
- `reverse_charge_override_at` — nullable timestamp
- `reverse_charge_override_reason` — nullable enum (`vies_unavailable`)

### ViesValidationService

- Capture `requestIdentifier` from the SOAP response and include it in the return array
- No other changes expected

### VatScenario

- Update `determine()` to use `partner.vat_status === VatStatus::Confirmed` instead of `hasValidEuVat()`
- Remove `$ignorePartnerVat` parameter — no longer needed; scenario is always determined from current partner state

### CustomerInvoiceService

- Remove `$treatAsB2c` parameter from `confirm()` and `determineVatType()`
- Split the confirmation logic:
  - `previewScenario(CustomerInvoice $invoice): array` — runs VIES, determines scenario, computes preview totals in-memory; no DB writes; returns scenario + totals + VIES result for modal display
  - `applyAndConfirm(CustomerInvoice $invoice): void` — re-runs the same logic inside a transaction and writes everything to DB
- Store `vat_scenario`, `vies_request_id`, `vies_checked_at`, `vies_result` on the invoice inside `applyAndConfirm()`
- Store reverse charge override columns when opt-in path is taken

### ViewCustomerInvoice

- Remove `$viesInvalidDetected` Livewire property
- Remove "Confirm with Standard VAT" action
- "Confirm Invoice" button:
  - On click: calls `previewScenario()` (spinner on button while VIES runs)
  - On success (VIES valid or invalid): opens confirmation modal with scenario + financial summary
  - On unavailable: does not open modal; renders VIES error state inline
- Confirmation modal (Filament Action with custom modal schema):
  - Shows scenario badge, partner VAT reference, VIES `request_id` + timestamp, subtotal / VAT / total
  - Cancel → closes modal, nothing saved
  - Confirm → calls `applyAndConfirm()`, redirects to confirmed view
- VIES unavailable inline state:
  - Retry button — 1-min cooldown; last attempt tracked in `localStorage` key `vies_retry_{invoiceId}`; Alpine.js manages the disabled state and re-enable after 60s
  - "Confirm with VAT" button — always visible; any user; calls `applyAndConfirm()` without reverse charge
  - "Confirm with Reverse Charge" button — only rendered when `partner.vat_status = confirmed`; role-gated; requires checkbox consent before button enables; stores override audit trail columns

### CustomerInvoiceForm (create/edit)

- **Partner select `helperText`**: already exists and reactive
  - Update to use new `vat_status` model for scenario determination
  - For `pending` partners: show warning message + inline "Re-check VIES" icon-button
  - Successful re-check updates partner record in DB; `helperText` re-renders with new scenario
- **`is_reverse_charge` toggle**: already disabled/read-only
  - Make reactive to partner selection — set based on expected scenario from stored partner data
- **`pricing_mode` selector**:
  - When partner triggers any non-Domestic scenario: disable selector, force value to `VatExclusive`
  - Add `afterStateUpdated` (or reactive closure) on `partner_id` to apply this constraint

---

## Tests Required

- [ ] Unit: `VatScenario::determine()` — all 5 scenarios using new `vat_status` model
- [ ] Unit: VIES re-check × response type (valid/invalid/unavailable) × partner status (confirmed/pending) — all 6 combinations
- [ ] Unit: Pricing mode constraint — non-Domestic scenarios force VAT Exclusive
- [ ] Unit: `previewScenario()` returns correct totals in-memory without DB writes
- [ ] Feature: Full confirmation happy path — Domestic, EU B2B, EU B2C under/over, Non-EU
- [ ] Feature: VIES invalid at confirmation — partner updated to `not_registered`, no reverse charge applied
- [ ] Feature: VIES unavailable — retry state visible; "Confirm with VAT" confirms without reverse charge
- [ ] Feature: Reverse charge opt-in (confirmed + unavailable) — role-gated; audit columns stored; unauthorized user cannot see button
- [ ] Feature: Pending partner + VIES unavailable → confirmed as VAT, no opt-in button shown
- [ ] Feature: `vat_scenario` and VIES columns frozen on invoice after confirmation
- [ ] Feature: Re-check VIES from invoice form (pending partner) — updates partner record in DB

---

## Refactor Findings

1. **Two-action modal pattern broken** — `confirm` action called `$this->mountAction('proceedToConfirm')` from inside its `->action()` handler. Filament's `callMountedAction()` detects `mountedActions` changed mid-execution and aborts. Fixed by merging into a single action using `->mountUsing()` which runs VIES pre-check before the modal opens; `throw new Halt` prevents the modal on failure.

2. **VIES countryCode vs VAT prefix split (Greece bug)** — `GR` (ISO country code) has VAT prefix `EL`. Both `CustomerInvoiceService::runViesPreCheck()` and `PartnerVatService::reVerify()` were passing the VAT prefix (`EL`) as the VIES `countryCode` param. Fixed: `$partner->country_code` is passed to `validate()` as the country code; `$vatPrefix` is used only for stripping the stored VAT number string.

3. **Modal financial preview showed draft values** — for reverse charge / non-EU / exempt scenarios, VAT is zeroed out at confirmation. The modal was showing current draft totals (with VAT). Fixed: preview computes `previewTax = '0.00'` and `previewTotal = subtotal - discount` for zero-rated scenarios.

4. **Modal schema had no visual structure** — flat list of `TextEntry` with HtmlString hacks. Replaced with `Section` + `Grid` layout, `badge()` with per-scenario color on the VAT treatment entry, proper `->money()` formatting, and `->weight(FontWeight::Bold)->size(TextSize::Large)` on the total.

---

## Checklist

- [x] Investigation complete
- [x] Plan written (`invoice-plan.md`)
- [x] Implementation complete
- [x] Automated tests pass
- [x] Code review clean
- [ ] Browser tested (manual)
- [x] Refactor findings written
- [x] Refactor implemented
- [x] Pint clean
- [x] Final test run
