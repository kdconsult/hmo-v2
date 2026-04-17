# Task: Post-Review Hotfix Bundle

> **Spec:** `tasks/vat-vies/spec.md`
> **Plan:** `tasks/vat-vies/hotfix-plan.md`
> **Status:** 🔧 TODO — highest priority, no phase dependency
> **Unblocks:** everything else (removes live bugs + establishes stable foundation)

---

## Why this task exists

The 2026-04-17 review (`review.md`) surfaced two **live bugs** in already-shipped code and a handful of small drift / doc items. None of them block pre-launch in isolation, but together they are the cheapest sweep to run before any further feature work. Delivering them as one focused hotfix branch avoids mixing corrective work with feature scope.

Findings bundled here: F-030, F-031, F-005, F-017, F-018, F-034, F-036.

---

## Scope

### 1. F-030 — empty `partner.country_code` routes to `NonEuExport` (0% VAT)

Currently `VatScenario::determine()` silently returns `NonEuExport` when a partner has `country_code = NULL`. `country_code` is nullable in the DB and NOT required on the Partner form, and the form's options list is EU-only (`EuCountries::forSelect()`). A BG user creating a domestic customer who forgets to pick a country → 0% invoice → under-declared VAT.

**Fixes:**
- Partner form: `country_code` required, default to tenant country, full-world country list (ISO 3166-1 alpha-2 covering BOTH EU and non-EU)
- Migration: data-fix pass (assign tenant country to null rows where safe) → `ALTER TABLE partners ALTER COLUMN country_code SET NOT NULL`
- `VatScenario::determine()`: throw `DomainException` on empty country instead of silently returning `NonEuExport`
- Same fix on `Tenant` and `CompanySettings` `country_code` fields
- Remediation query shipped as an artisan command (`php artisan hmo:vat-remediate-country-code`) — flags any confirmed `NonEuExport` invoices whose partner now has a null country for tenant review

### 2. F-031 — `CustomerInvoice` has no immutability guard on confirmed rows

`CustomerInvoice` model does not throw on update/delete of a `Confirmed` row. CLAUDE.md documents StockMovement's `RuntimeException` pattern as the project standard; replicate it.

**Fixes:**
- Add model-boot guard on `CustomerInvoice`: throw `RuntimeException` on `updating` / `deleting` when `status === Confirmed` (and original status was Confirmed)
- Same guard on `CustomerInvoiceItem` for items attached to a confirmed parent
- Prepare hook-style extension point for future credit/debit notes (they'll add the same guard in their own tasks — `invoice-credit-debit.md`)

### 3. F-005 — VIES cache key not per-tenant at the application layer

`ViesValidationService` caches under `vies_validation_{countryCode}_{vatNumber}` — global across tenants. Whether it leaks depends on `config/tenancy.php` cache bootstrapper being enabled. Defence-in-depth fix: include tenant id in the cache key explicitly.

**Fixes:**
- Verify `config/tenancy.php` has `\Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper::class` in `bootstrappers`
- Prepend `tenancy()->tenant?->id` to cache key as a belt-and-braces measure
- Add a regression test: two tenants, same VAT number, cache entries isolated

### 4. F-017 / F-018 / F-036 — doc drift

- `spec.md`: already updated in the spec-sync step (localStorage → server-side cooldown)
- `ViesValidationService.php`: remove stale "may require different WSDL" comment (checkVatService.wsdl exposes both operations; verified)
- `invoice.md`: resolve $ignorePartnerVat drift — document the parameter is **kept** (still used by the `confirm()` wrapper + treat-as-B2C path)

### 5. F-034 — memory cleanup

`project_vat_vies_design.md` memory record says "old VAT-DETERMINATION-1 code to be replaced". `grep` shows zero matches in `app/` — the replacement has shipped. Delete the stale line from the memory record.

---

## Non-scope

- PDF / Art. 226 content fixes (→ `pdf-rewrite.md`)
- OSS year bug (→ `invoice-plan.md` refactor queue)
- DomesticExempt scenario (→ `domestic-exempt.md`)

---

## Known Changes Summary

| File | Change |
|------|--------|
| `app/Enums/VatScenario.php` | `determine()` throws `DomainException` on empty `country_code` |
| `app/Models/CustomerInvoice.php` | `booted()` guards — `updating` / `deleting` throw when Confirmed |
| `app/Models/CustomerInvoiceItem.php` | same pattern for items whose parent is Confirmed |
| `app/Models/Partner.php` | no code change; mentioned for context |
| `app/Services/ViesValidationService.php` | tenant id in cache key; comment cleanup |
| `app/Filament/Resources/Partners/Schemas/PartnerForm.php` | `country_code` `->required()`, default to tenant country, full country list |
| `app/Filament/Resources/Tenants/Schemas/TenantForm.php` (if present) | same |
| `app/Filament/Pages/CompanySettingsPage.php` | `country_code` `->required()`, default to tenant country |
| `app/Support/EuCountries.php` OR new `app/Support/Countries.php` | add a `forSelectAll()` (or equivalent) returning the full ISO list |
| `database/migrations/tenant/{timestamp}_partners_country_code_not_null.php` | data-fix + NOT NULL |
| `database/migrations/{timestamp}_tenants_country_code_not_null.php` | same for central tenants table |
| `app/Console/Commands/VatRemediateCountryCodeCommand.php` | remediation query artisan command |
| `config/tenancy.php` | verify `CacheTenancyBootstrapper` present (no write if already there) |
| `tasks/vat-vies/invoice.md` | document $ignorePartnerVat is kept |
| `memory/project_vat_vies_design.md` | remove VAT-DETERMINATION-1 stale line |

---

## Tests Required

- [ ] Unit: `VatScenario::determine()` throws `DomainException` on empty country_code
- [ ] Unit: `VatScenario::determine()` throws when `country_code` is literal empty string, null, or `'   '` (whitespace)
- [ ] Feature: CustomerInvoice `update()` throws RuntimeException when status is Confirmed
- [ ] Feature: CustomerInvoice `delete()` throws RuntimeException when status is Confirmed
- [ ] Feature: Draft CustomerInvoice can still be updated + deleted (no regression)
- [ ] Feature: VIES cache isolation — Tenant A's cached VIES response is not visible to Tenant B
- [ ] Feature: Partner form refuses save with null country_code, shows validation error
- [ ] Feature: Partner form defaults country_code to the tenant's country on creation
- [ ] Feature: Partner form country list includes non-EU countries (e.g. US, GB, CH)
- [ ] Feature: Migration from nullable → NOT NULL handles existing null rows (backfill to tenant country)
- [ ] Feature: `hmo:vat-remediate-country-code` artisan command lists candidate invoices

---

## Refactor Findings

> Filled during / after implementation.

---

## Checklist

- [ ] Investigation complete
- [ ] Plan written (`hotfix-plan.md`)
- [ ] Migrations + model + enum changes shipped
- [ ] Form changes shipped
- [ ] Remediation command shipped
- [ ] ViesValidationService changes shipped
- [ ] Doc drift cleaned (spec.md / invoice.md / ViesValidationService comments)
- [ ] Memory note pruned
- [ ] Automated tests pass
- [ ] Browser-tested: create partner with null country → save blocked
- [ ] Browser-tested: confirmed invoice cannot be edited from Filament
- [ ] Pint clean
- [ ] Final test run green
