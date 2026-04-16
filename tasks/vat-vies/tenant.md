# Task: Tenant Company VAT Setup

> **Spec:** `tasks/vat-vies/spec.md` — Area 1
> **Plan:** `tasks/vat-vies/tenant-plan.md` (created when ready to build)
> **Status:** Discussion complete — ready to plan

---

## Scope

Implement VIES-verified VAT registration in Company Settings / onboarding wizard.
Covers data model, service layer, and UI only. App-wide VAT blocking is a separate task (`blocks.md`).

---

## What Needs Investigation Before Planning

- [ ] What columns exist today on `company_settings` / `tenants` for VAT? (`vat_number`, `is_vat_registered`, etc.)
- [ ] Does Company Settings form already have a country selector?
- [ ] Does the onboarding wizard have a company data step that needs the same treatment?
- [ ] `ViesValidationService` — confirm it already returns `available`, `valid`, `name`, `address`
- [ ] What is the current EIK field on company settings — is it already stored?
- [ ] Country-specific VAT number pattern validation — does any utility exist, or do we build it?

---

## Known Changes

### Data model
- Confirm or add to company settings / tenants:
  - `is_vat_registered` boolean, default `false`
  - `vat_number` nullable string — populated from VIES response only
  - `vies_verified_at` nullable timestamp — when last successful check occurred
  - `vies_last_checked_at` nullable timestamp — when last check was attempted (any result)

### Service
- `ViesValidationService::validate()` — already exists; verify it covers this use case
- New or extended: `CompanyVatService` or equivalent to handle the atomic toggle+VAT update

### UI — Company Settings form
- Country selector — drives placeholder field pattern validation; change triggers reset
- Placeholder field: `[CC][ user input ]` — not DB-mapped; concatenated for VIES call
- Country-specific pattern validation on user input portion
- Toggle "Company is VAT registered" — enables check button; OFF clears VAT field
- VIES check button → calls service → handles three response states
- VAT number display field — read-only; populated from VIES response; never editable
- Company name + address — pre-filled from VIES where available; always editable
- Save guard: toggle ON + no confirmed VAT → validation error

### Reset triggers
- Country change → reset toggle to false + clear VAT field
- Toggle OFF → clear VAT field
- VIES invalid or unavailable → reset toggle to false + clear VAT field

---

## Tests Required

- [ ] Unit: VIES response handling — valid / invalid / unavailable paths
- [ ] Unit: Country change resets state correctly
- [ ] Unit: Toggle OFF clears VAT field
- [ ] Feature: Company Settings — complete happy path (toggle ON → VIES valid → saved)
- [ ] Feature: VIES invalid response → toggle reset, nothing saved
- [ ] Feature: VIES unavailable → same as invalid
- [ ] Feature: Save blocked when toggle ON + no VAT number
- [ ] Feature: `is_vat_registered = true` invariant — never saved without VAT number

---

## Refactor Findings

> Filled during / after implementation.

---

## Checklist

- [ ] Investigation complete
- [ ] Plan written (`tenant-plan.md`)
- [ ] Implementation complete
- [ ] Automated tests pass
- [ ] Code review clean
- [ ] Browser tested (manual)
- [ ] Refactor findings written
- [ ] Refactor implemented
- [ ] Pint clean
- [ ] Final test run
