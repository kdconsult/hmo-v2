# Task: Tenant Company VAT Setup

> **Spec:** `tasks/vat-vies/spec.md` — Area 1
> **Plan:** `tasks/vat-vies/tenant-plan.md` — refactor queue for the deferred items below
> **Review:** `tasks/vat-vies/review.md` — 2026-04-17 audit
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

### From implementation (2026-04-16)

- **Registration flow** (`app/Livewire/RegisterTenant.php`) stores user-typed VAT numbers directly to `tenants.vat_number` — violates Principle 5 (VAT numbers must come from VIES). Needs separate cleanup.
- **Onboarding wizard** has no VIES check — acceptable for now since `is_vat_registered` defaults to `false`.
- **`company_settings.company.country_code` initial seeding** — `TenantOnboardingService` doesn't seed this KV key. `mount()` fallback to `tenancy()->tenant->country_code` handles it. Could be improved later.
- **Cross-group `$set`/`$get` fragility** — Filament v5 relative paths across form groups are unreliable. Used `data_set($this->data, ...)` and Livewire methods instead.

### From 2026-04-17 review (review.md)

- **F-004 — Art. 96 ЗДДС citation was wrong.** Resolved in `legal-references.md` / `phase-a-plan.md` — correct basis is **чл. 113, ал. 9 ЗДДС** for a non-VAT-registered supplier's invoice notice. No action needed on `tenant.md` itself; cross-link only.
- **F-030 — `country_code` nullable / not required.** On the tenant side this is handled in `hotfix.md` (Migration: NOT NULL on `tenants.country_code`; Company Settings form `->required()`). No tenant-specific refactor beyond that.
- **F-031 — tenant row immutability.** Not applicable (tenant is not an issued document), but related — when tenant `is_vat_registered` flips, any historical Confirmed invoice must still carry its original treatment. Enforced at the invoice level (see `invoice.md` refactor queue). No action here.
- **F-034 — legacy VAT-DETERMINATION-1.** Verified gone from `app/`. Memory note pruned in `hotfix.md` Step 12.

### Open refactor items (executed via `tenant-plan.md`)

1. Clean up `RegisterTenant.php` to never write user-typed VAT → either remove the field or route through VIES.
2. Extend onboarding wizard with an optional VIES check step for VAT-registered tenants (keep skippable).
3. Seed `company_settings.company.country_code` inside `TenantOnboardingService` explicitly (remove the `mount()` fallback).
4. Review principle-5 invariant: add a DB-level CHECK constraint on `tenants` — `CHECK (NOT is_vat_registered OR vat_number IS NOT NULL)`. Defence-in-depth.

---

## Checklist

- [x] Investigation complete
- [x] Plan written (`tenant-plan.md`)
- [x] Implementation complete
- [x] Automated tests pass — 541/541 (8 VAT-specific)
- [x] Code review clean — advisor-reviewed, 5 bugs found and fixed
- [x] Browser tested (manual)
- [x] Refactor findings written
- [ ] Refactor implemented — deferred (see `tenant-plan.md`)
- [x] Pint clean
- [x] Final test run — 541/541 pass
