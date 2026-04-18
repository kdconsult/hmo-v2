# Plan: Tenant VAT Setup — Refactor Queue

> **Task:** `tasks/vat-vies/tenant.md`
> **Review:** `tasks/vat-vies/review.md`
> **Status:** Refactor-only plan (Area 1 implementation is already shipped). Covers the deferred items from the refactor findings.

---

## Prerequisites

- [ ] `hotfix.md` merged (establishes NOT NULL country_code + required() on CompanySettings)
- [ ] `legal-references.md` merged (so any fresh tenant gets the 16 BG rows automatically)

---

## Scope

Three deferred refactor items + one defence-in-depth DB invariant:

1. Clean up `app/Livewire/RegisterTenant.php` — VAT number must come from VIES, not user input
2. Extend onboarding wizard with an optional VIES step
3. Seed `company_settings.company.country_code` inside `TenantOnboardingService` explicitly
4. DB CHECK constraint: `is_vat_registered = true` requires `vat_number IS NOT NULL`

---

## Step 1 — Clean `RegisterTenant.php`

**File:** `app/Livewire/RegisterTenant.php`

Current behavior: accepts a user-typed `vat_number` and writes it directly. Violates Shared Principle 5 (VAT numbers come from VIES only).

**Change:**
- Remove the raw `vat_number` field from the registration form
- Registration sets `is_vat_registered = false` always (new tenants start as non-registered; they VAT-register via Company Settings post-registration using the VIES flow)
- If the current form offers an "is VAT registered" checkbox, keep it as an **informational flag** only — save as `false` until the post-registration VIES check happens (via Company Settings)

Rationale: registering a tenant is already a multi-step flow; VIES is a separate concern and needs the proper form treatment (country-prefix placeholder, check button, read-only VAT display). Cramming it into registration invites half-verified data.

**Migration for existing registrations:** any tenant created through the old form with a raw `vat_number` should be downgraded to `is_vat_registered = false` until they re-verify via Company Settings. Data-fix in a one-off artisan command (not a schema migration):

```
php artisan hmo:tenants-require-vies-recheck
```

Flags any tenant with `vat_number IS NOT NULL` AND `vies_verified_at IS NULL` → set `is_vat_registered = false` AND queue an email to the tenant admin: "Please re-verify your VAT registration in Company Settings."

---

## Step 2 — Onboarding wizard VIES step

**File:** wherever the onboarding wizard lives (search for "OnboardingWizard" or similar)

Add an optional step between "Company Info" and "Complete":
- Step title: "VAT Registration (optional)"
- Content: toggle "My company is VAT-registered" → if ON, surface the same placeholder + VIES check pattern used in Company Settings
- If the tenant skips it, registration completes with `is_vat_registered = false`. They can come back later.
- If they use it and VIES is valid → store VAT + set `is_vat_registered = true` + mark `vies_verified_at`
- If VIES is invalid / unavailable → behaviour matches Area 1 tenant rules (reset to false)

**Reuse:** the Company Settings VIES-check service logic. Extract the VIES interaction into a shared Livewire trait if it isn't already.

---

## Step 3 — `TenantOnboardingService` seeds `company.country_code`

**File:** `app/Services/TenantOnboardingService.php`

When provisioning a tenant:

```php
$tenant->run(function () use ($countryCode) {
    // ... existing seeders (VatRateSeeder, VatLegalReferenceSeeder from legal-references.md, RolesAndPermissionsSeeder, etc.)

    CompanySettings::set('company', 'country_code', strtoupper($countryCode));
    CompanySettings::set('company', 'is_vat_registered', false);
});
```

The `country_code` is typically provided at tenant creation (from the registration form). Explicit seed removes the need for the `mount()` fallback in Company Settings.

**Decision: retain the `CompanySettingsPage::mount()` fallback** (`if (empty($companyGroup['country_code'])) { $companyGroup['country_code'] = $tenant->country_code; }`). The `hmo:tenants-require-vies-recheck` command does NOT reseed `CompanySettings`, so tenants created before this onboarding change may still lack the setting. Removing the fallback would break those tenants. Keep it as defense-in-depth; it becomes a no-op for newly registered tenants.

---

## Step 4 — DB CHECK invariant

**File:** `database/migrations/{timestamp}_tenants_vat_invariant_check.php`

```php
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE tenants ADD CONSTRAINT tenants_vat_invariant '
            . 'CHECK (NOT is_vat_registered OR vat_number IS NOT NULL)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tenants DROP CONSTRAINT tenants_vat_invariant');
    }
};
```

Run first a data-fix pass: any rows violating the invariant today must be reconciled (likely `is_vat_registered = true` with a historical VAT number — set `vies_verified_at` if not set; or downgrade to false).

---

## Tests

- [x] Unit: `CompanyVatService::updateVatRegistration()` still enforces the invariant (regression)
- [x] Feature: New registration form does NOT accept a `vat_number` field (regression against the old behavior)
- [x] Feature: Onboarding wizard VIES step — valid → stored; invalid → reset; skipped → is_vat_registered stays false
- [x] Feature: `TenantOnboardingService` seeds `company.country_code`; a fresh tenant queried for that key returns the expected value without relying on fallbacks
- [x] Feature: DB CHECK rejects `UPDATE tenants SET is_vat_registered = true WHERE vat_number IS NULL`
- [ ] Feature: `hmo:tenants-require-vies-recheck` command downgrades legacy tenants correctly (manual command; interactive prompt not suitable for automated tests)

---

## Gotchas / load-bearing details

1. **Don't let DB CHECK land before the data-fix.** Migration inlines an UPDATE before the ALTER so existing violations are reconciled automatically.
2. **Backward compatibility.** Removing the `vat_number` field from registration breaks any external API consumers posting it. If there are API callers, version or document.
3. **Onboarding wizard tests** use the `Filament::setCurrentPanel('landlord')` pattern per memory `feedback_filament_multi_panel_testing`. Respect.
4. **VIES check from onboarding** runs in landlord panel, not tenant panel. The existing `ViesValidationService` reads `tenancy()->tenant?->vat_number` for requester info — at onboarding that's null. Accept null requester info (VIES still returns a result; `request_id` will be null). Document acceptable in onboarding-context (no audit-grade storage expected yet).
5. **PostgreSQL CHECK constraints are NOT DEFERRABLE** — `DEFERRABLE INITIALLY IMMEDIATE` is invalid for CHECK. Only FOREIGN KEY constraints are deferrable in PostgreSQL.
6. **F-023 guard removed from `CustomerInvoiceService`** — the DB constraint makes the `empty(vat_number) && is_reverse_charge` path unreachable. Removed as dead code per CLAUDE.md policy. The constraint itself is tested in `RegisterTenantTest.php`.

---

## Exit Criteria

- [x] All tests green (675 passed)
- [ ] Manual: new tenant registration → no VAT field surfaced, 4-step wizard with optional VIES step
- [ ] Manual: onboarding wizard VIES step works — valid confirms, invalid resets, skip leaves is_vat_registered=false
- [ ] Manual: fresh tenant has seeded `company.country_code` + `is_vat_registered = false` in Company Settings
- [x] DB CHECK present (`tenants_vat_invariant`)
- [ ] Legacy command `hmo:tenants-require-vies-recheck` run on any dev / staging tenants; no live production tenants affected
- [x] Pint clean
- [ ] `tenant.md` checklist refactor items ticked
