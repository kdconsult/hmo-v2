# Task: Partner VAT Setup

> **Spec:** `tasks/vat-vies/spec.md` — Area 2
> **Plan:** `tasks/vat-vies/partner-plan.md` — refactor queue for the items below
> **Review:** `tasks/vat-vies/review.md` — 2026-04-17 audit
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

### From 2026-04-17 review (review.md)

- **F-005 — VIES cache not per-tenant at key layer.** `ViesValidationService` cache key is `vies_validation_{countryCode}_{vatNumber}` — global across tenants. Depends on `stancl/tenancy` cache bootstrapper being enabled. Handled in `hotfix.md`: verify bootstrapper + prepend tenant id to cache key as defence-in-depth. Plus a regression test for cross-tenant isolation.
- **F-008 — Silent partner downgrade on VIES invalid at invoice confirmation.** The `runViesPreCheck()` side effect updates Partner's `vat_status = NotRegistered` with no visible notification. Add a Filament notification + enrich activity-log entry with the invoice id. See `partner-plan.md` Step 1.
- **F-019 — `VatStatus::Pending` semantics.** Pending state is unhandled in non-invoice UI. Add (a) 7-day staleness watch surfacing a widget on partner view, (b) visual marker on partner lists, (c) test that Pending never applies EuB2bReverseCharge. See `partner-plan.md` Step 2.
- **F-024 — Partner mutation not inside invoice-confirmation transaction.** `runViesPreCheck()` mutates the partner even if the invoice confirmation is cancelled or fails downstream. Move mutation into `confirmWithScenario()` transaction. Tracked in `invoice.md` refactor queue (service is invoice-owned; the partner side only observes). Cross-link.
- **F-025 — VIES address "best-effort parse".** Store raw VIES address string in a new `vies_raw_address` column alongside the parsed fields. Partner view falls back to raw when parse confidence is low. See `partner-plan.md` Step 3.
- **F-027 — No Art. 18(1)(b) "applied but not yet issued" fallback.** New `VatStatus::PendingRegistration` case (manual, supervisor-only, requires uploaded proof). Deferred — future backlog item. Noted here for completeness.

### Open refactor items (executed via `partner-plan.md`)

1. Surface the VIES-invalid downgrade as a visible notification + enriched activity log.
2. Add Pending staleness watch + visual marker + regression test.
3. Add `vies_raw_address` column; have the Partner form pre-fill structured fields best-effort while preserving the raw string for PDF fallback.
4. (Deferred) Art. 18(1)(b) alternative-proof flow — flagged to backlog.

---

## Checklist

- [x] Investigation complete
- [x] Plan written (`partner-plan.md`)
- [x] Implementation complete
- [x] Automated tests pass (557 tests, 0 failures)
- [x] Code review clean (2026-04-17 audit — review.md)
- [ ] Browser tested (manual)
- [x] Refactor findings written (2026-04-17)
- [ ] Refactor implemented — see `partner-plan.md`
- [x] Pint clean
- [x] Final test run
