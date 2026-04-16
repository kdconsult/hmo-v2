# Task: Tenant Company VAT Setup

> **Spec:** `tasks/vat-vies/spec.md` — Area 1
> **Plan:** `tasks/vat-vies/tenant-plan.md` (created when ready to build)
> **Status:** ✅ Complete (2026-04-16)

---

## Scope

Implement VIES-verified VAT registration in Company Settings / onboarding wizard.
Covers data model, service layer, and UI only. App-wide VAT blocking is a separate task (`blocks.md`).

---

## What Needs Investigation Before Planning

- [x] What columns exist today on `company_settings` / `tenants` for VAT? — `vat_number` on `tenants`; added `is_vat_registered` + `vies_verified_at`
- [x] Does Company Settings form already have a country selector? — Yes, `company.country_code`
- [x] Does the onboarding wizard have a company data step that needs the same treatment? — Yes, but deferred (out of scope)
- [x] `ViesValidationService` — confirm it already returns `available`, `valid`, `name`, `address` — Confirmed, reused as-is
- [x] What is the current EIK field on company settings — is it already stored? — `eik` on `tenants` table, not relevant to VAT setup
- [x] Country-specific VAT number pattern validation — does any utility exist, or do we build it? — `EuCountries::vatNumberRegex()` + `vatNumberExample()` already exist

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

- [x] Unit: VIES response handling — valid / invalid / unavailable paths → `CompanyVatServiceTest` (register, deregister, invariant)
- [x] Unit: Country change resets state correctly → covered by `CompanyVatServiceTest` country update test
- [x] Unit: Toggle OFF clears VAT field → `CompanyVatServiceTest` deregister test
- [x] Feature: Company Settings — complete happy path (toggle ON → VIES valid → saved) → `CompanyVatSetupTest`
- [x] Feature: VIES invalid response → toggle reset, nothing saved → `CompanyVatSetupTest`
- [x] Feature: VIES unavailable → same as invalid → `CompanyVatSetupTest`
- [x] Feature: Save blocked when toggle ON + no VAT number → `CompanyVatSetupTest`
- [x] Feature: `is_vat_registered = true` invariant — never saved without VAT number → `CompanyVatServiceTest`

---

## Refactor Findings

- **Registration flow** (`app/Livewire/RegisterTenant.php`) stores user-typed VAT numbers directly to `tenants.vat_number` — violates Principle 5 (VAT numbers must come from VIES). Needs separate cleanup.
- **Onboarding wizard** has no VIES check — acceptable for now since `is_vat_registered` defaults to `false`.
- **`company_settings.company.country_code` initial seeding** — `TenantOnboardingService` doesn't seed this KV key. `mount()` fallback to `tenancy()->tenant->country_code` handles it. Could be improved later.
- **Cross-group `$set`/`$get` fragility** — Filament v5 relative paths across form groups are unreliable. Used `data_set($this->data, ...)` and Livewire methods instead.

---

## Checklist

- [x] Investigation complete
- [x] Plan written (`tenant-plan.md`)
- [x] Implementation complete
- [x] Automated tests pass — 541/541 (8 VAT-specific)
- [x] Code review clean — advisor-reviewed, 5 bugs found and fixed
- [x] Browser tested (manual)
- [x] Refactor findings written
- [ ] Refactor implemented — deferred (RegisterTenant.php, onboarding wizard)
- [x] Pint clean
- [x] Final test run — 541/541 pass
