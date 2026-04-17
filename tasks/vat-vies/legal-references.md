# Task: Legal References Foundation

> **Spec:** `tasks/vat-vies/spec.md`
> **Plan:** `tasks/vat-vies/legal-references-plan.md`
> **Status:** 📋 PLANNED — ready to build
> **Depends on:** `hotfix.md` landed (so we build on a stable schema)
> **Unblocks:** `pdf-rewrite.md`, `domestic-exempt.md`, `blocks.md`, `blocks-credit-debit.md`, `invoice-credit-debit.md`

---

## Why this task exists

The invoice PDF, credit/debit notes, and the blocks feature all need to render a **legal reference** next to any zero-rate / exempt / reverse-charge line (required by **Art. 226(11) Directive 2006/112/EC** and **чл. 114, ал. 1, т. 12 ЗДДС**). The exact citation depends on:

- The tenant's country of establishment (BG tenant cites ЗДДС; DE tenant cites UStG; etc.)
- The VAT scenario on the document
- A **sub-code** that discriminates goods-vs-services for EU B2B / Non-EU supplies, and specific articles (Art. 39..49) for BG domestic exemptions

A hard-coded reference per scenario is not expressive enough. We need a **tenant-scoped lookup table** keyed by `(country_code, vat_scenario, sub_code)` with translatable descriptions.

See `[review.md#f-001]`, `[review.md#f-004]`.

---

## Scope

- Tenant-scoped database table `vat_legal_references`
- Eloquent model `VatLegalReference` with `HasTranslations`
- Seeder class seeding **16 BG rows** on tenant provisioning
- Wire seeder into `app/Services/TenantOnboardingService.php` and `tests/Support/TenantTemplateManager.php` (both `recreateTemplate()` AND `currentHash()`)
- Resolver contract: `VatLegalReference::resolve(country, scenario, sub_code): VatLegalReference` with fallback to `sub_code='default'` → throws `DomainException` if nothing matches
- Lookup for form dropdowns: `VatLegalReference::listForScenario(country, scenario): Collection`
- **No** UI changes in this task — consumers (PDF, form toggles) live in follow-up tasks

---

## Non-scope

- Any PDF rendering (→ `pdf-rewrite.md`)
- Any form-field wiring (→ `domestic-exempt.md`, `blocks.md`)
- Seeding for any country other than BG (future work per tenant country expansion)
- UI for admins to edit the references (backlog)

---

## Known Changes

### Data model — `vat_legal_references` (tenant DB)

Columns:
- `id` — bigint PK
- `country_code` — string(2), indexed, not null (ISO 3166-1 alpha-2; BG, DE, FR, …)
- `vat_scenario` — string, indexed, not null — matches `VatScenario` enum values (`exempt`, `domestic_exempt`, `eu_b2b_reverse_charge`, `eu_b2c_under_threshold`, `eu_b2c_over_threshold`, `non_eu_export`, `domestic`)
- `sub_code` — string, default `'default'`, not null — sentinel for "no sub-code needed". Never NULL (Postgres unique index treats NULL as distinct → duplicates possible).
- `legal_reference` — string, not null — the citation itself (e.g. `'чл. 113, ал. 9 ЗДДС'`, `'Art. 138 Directive 2006/112/EC'`)
- `description` — `json`, not null — translatable human description via `HasTranslations` (e.g. `{"bg": "Доставки, свързани със здравеопазване", "en": "Healthcare-related supplies"}`)
- `is_default` — boolean, default false — flags the row to pre-select in multi-variant UI (e.g. `domestic_exempt` → Art. 39 gets `is_default = true`)
- `sort_order` — small integer, default 0 — for dropdown ordering
- `created_at` / `updated_at`

Unique index: `(country_code, vat_scenario, sub_code)`.

### Model — `app/Models/VatLegalReference.php`

- Uses `HasTranslations` for `description`
- Translatable attribute declared: `protected $translatable = ['description'];`
- Scopes: `scopeForCountry(string)`, `scopeOfScenario(string)`, `scopeDefault()`
- Static helpers:
  - `resolve(string $countryCode, string $scenario, string $subCode = 'default'): self` — exact match first, fallback to `default` sub-code, else throws `DomainException`
  - `listForScenario(string $countryCode, string $scenario): Collection` — for dropdowns, ordered by `sort_order`

### Seeder — `database/seeders/VatLegalReferenceSeeder.php`

Idempotent via `updateOrCreate` keyed on the unique tuple. Seeds 16 BG rows (see plan for the full table).

### Wiring

- `app/Services/TenantOnboardingService.php` — call `VatLegalReferenceSeeder` inside `provisionTenant()` after the tenant DB is bootstrapped
- `tests/Support/TenantTemplateManager.php` — **two** places:
  1. Call `VatLegalReferenceSeeder` inside `recreateTemplate()`
  2. Add `database/seeders/VatLegalReferenceSeeder.php` to `currentHash()` so template rebuilds when the seed data changes

Both changes **MUST** land in the same commit. If only (1) lands, the template hash doesn't change, tests see an empty table. If only (2) lands, template won't rebuild.

---

## Tests Required

- [ ] Unit: `VatLegalReference::resolve()` — exact match
- [ ] Unit: `VatLegalReference::resolve()` — fallback to `default` sub-code
- [ ] Unit: `VatLegalReference::resolve()` — throws `DomainException` when no match and no default
- [ ] Unit: `VatLegalReference::listForScenario()` — returns rows in `sort_order`, respects `is_default` first
- [ ] Feature: Migration runs cleanly on a fresh tenant
- [ ] Feature: Seeder seeds exactly 16 BG rows (Exempt × 1, DomesticExempt × 11 for Art. 39–49, EuB2bReverseCharge × 2 goods+services, NonEuExport × 2 goods+services)
- [ ] Feature: Seeder is idempotent — running twice yields 16 rows, no duplicates
- [ ] Feature: Translations — German locale returns German description if seeded; falls back to English otherwise
- [ ] Feature: `TenantOnboardingService` creates a new tenant → 16 rows present after provisioning
- [ ] Feature: `TenantTemplateManager` — template hash changes when seeder file changes (regression test against silent-test-failure bug)
- [ ] Unit: `is_default` behavior — `domestic_exempt` returns `art_39` when called without sub_code

---

## Refactor Findings

> Filled during / after implementation.

---

## Checklist

- [ ] Investigation complete
- [ ] Plan written (`legal-references-plan.md`)
- [ ] Migration + model + seeder implemented
- [ ] `TenantOnboardingService` wired
- [ ] `TenantTemplateManager` — both places updated
- [ ] Automated tests pass
- [ ] Code review clean
- [ ] Manual verification: new tenant has 16 rows
- [ ] Refactor findings written
- [ ] Refactor implemented
- [ ] Pint clean
- [ ] Final test run (`./vendor/bin/sail artisan test --parallel --compact`)
