# Task: Partner VAT Setup

> **Spec:** `tasks/vat-vies/spec.md` — Area 2
> **Plan:** `tasks/vat-vies/partner-plan.md` (created when ready to build)
> **Status:** ✅ DONE

---

## Scope

Implement VIES-verified VAT registration on Partner create/edit.
Covers data model, service layer, and UI only.
Invoice-time re-verification behaviour is defined in `invoice.md`.

---

## What Needs Investigation Before Planning

- [x] What columns exist today on `partners` for VAT? (`vat_number`, `is_vat_registered`, `vies_verified_at`, `vies_valid`, etc.)
- [x] Does `Partner::hasValidEuVat()` need to be updated to use the new three-state model?
- [x] Does the partner form already have a country selector?
- [x] `ViesValidationService` — already returns `available`, `valid`, `name`, `address`; confirm no changes needed
- [x] Check `VAT-VIES-1` entry in backlog — it partially overlaps this task; decide if it gets absorbed or removed

---

## Known Changes

### Data model
- Confirm or add to `partners` table:
  - `is_vat_registered` boolean, default `false`
  - `vat_number` nullable string — from VIES response only
  - `vat_status` enum: `not_registered` / `confirmed` / `pending` — derived or stored
  - `vies_verified_at` nullable timestamp — last successful VIES confirmation
  - `vies_last_checked_at` nullable timestamp — last attempt (any result)

### Model
- `Partner::hasValidEuVat()` — update to use `vat_status = confirmed` instead of format-only check
- `Partner::isEligibleForReverseCharge()` — new helper: `vat_status === confirmed && isEuCountry`

### Service
- `ViesValidationService::validate()` — already exists; no changes expected
- Partner-specific handling of the three response states at save time

### UI — Partner form
- All same components as tenant (placeholder field, toggle, locked VAT field, name/address pre-fill)
- Additional: "Validate VAT" action button on partner **view** page for manual re-verification

### Reset triggers (same as tenant)
- Country change → reset toggle + clear VAT field + status = `not_registered`
- Toggle OFF → clear VAT field + status = `not_registered`
- VIES invalid → reset toggle + clear VAT field + status = `not_registered`
- VIES unavailable → status = `pending`; partner saves without VAT number; user notified

---

## Tests Required

- [x] Unit: `PartnerVatService` — all three update states + all three re-verify outcomes (6 tests)
- [x] Feature: Partner create — happy path (VIES valid → confirmed)
- [x] Feature: Partner create — VIES invalid → not_registered, nothing saved
- [x] Feature: Partner create — VIES unavailable → pending, partner saved without VAT
- [x] Feature: Save blocked when toggle ON + no confirmed VAT (except pending state)
- [x] Feature: Manual re-verify action — valid / invalid / unavailable responses
- [x] Feature: Country change resets state
- [x] Feature: Validate VAT action hidden for pending partners

---

## Refactor Findings

> Filled during / after implementation.

---

## Checklist

- [x] Investigation complete
- [x] Plan written (`partner-plan.md`)
- [x] Implementation complete
- [x] Automated tests pass (557 tests, 0 failures)
- [ ] Code review clean
- [ ] Browser tested (manual)
- [ ] Refactor findings written
- [ ] Refactor implemented
- [x] Pint clean
- [x] Final test run
