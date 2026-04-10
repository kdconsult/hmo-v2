# Task 1.18 — Landlord Panel Security Hardening

## Context

A 4-agent security review of the landlord panel identified 25+ findings across authorization, input validation, atomicity, and information disclosure. This task remediates all actionable issues.

**Key problem:** Billing actions (changePlan, cancelSubscription, recordPayment, sendProformaInvoice) are protected only by `->hidden()` in both `ViewTenant.php` and `TenantsTable.php`. `->hidden()` is a Blade rendering directive — a crafted Livewire POST bypasses it entirely. The lifecycle actions correctly use `->authorize()` + policy methods; the billing actions do not.

**Architecture:** All fixes follow the existing dual-layer pattern established by lifecycle actions: `->visible()` for UI gating + `->authorize()` for server-side enforcement via `TenantPolicy`. No new packages or architectural changes.

---

## Sub-task 1.18.1 — Billing Policy Methods + Authorize Enforcement ✅

**Goal:** Add 4 billing policy methods and wire `->authorize()` to all 8 billing action instances.

**Findings addressed:** H1

**Modify:**
- `app/Policies/TenantPolicy.php` — add after line 64: `changePlan`, `cancelSubscription`, `recordPayment`, `sendProformaInvoice` — each checks `$user->is_landlord && !$tenant->isLandlordTenant()`
- `app/Filament/Landlord/Resources/Tenants/Pages/ViewTenant.php` — for each billing action (lines 137, 152, 202, 232): replace `->hidden(fn (): bool => $this->record->isLandlordTenant())` with `->visible(fn (): bool => !$this->record->isLandlordTenant())` + `->authorize(fn (): bool => auth()->user()->can('{method}', $this->record))`
- `app/Filament/Landlord/Resources/Tenants/Tables/TenantsTable.php` — same for lines 179, 192, 241, 269

**Create:**
- `tests/Feature/TenantBillingPolicyTest.php` — 12 tests: each of 4 methods × (false for non-landlord, false for landlord tenant, true for landlord + normal tenant)

---

## Sub-task 1.18.2 — Remove ForceDelete/Restore + Belt-and-Suspenders Policy ✅

**Goal:** Remove dead-code actions that bypass the lifecycle state machine; add explicit false-returning policy methods.

**Findings addressed:** H2

**Modify:**
- `app/Filament/Landlord/Resources/Tenants/Pages/EditTenant.php` — remove `use` for ForceDeleteAction/RestoreAction (lines 7-8) and the action calls (lines 21-22)
- `app/Policies/TenantPolicy.php` — add `forceDelete()` and `restore()` both returning `false`

**Tests:** add to `tests/Feature/LandlordTenantTest.php`

---

## Sub-task 1.18.3 — UserPolicy + UserForm Hardening ✅

**Goal:** Create missing UserPolicy; fix password-on-edit bug; restrict is_landlord toggle; protect audit fields.

**Findings addressed:** H4, H5, M2

**Create:**
- `app/Policies/UserPolicy.php` — viewAny/view/create/update check `is_landlord`; delete checks `is_landlord && id !== self`

**Modify:**
- `app/Filament/Landlord/Resources/Users/Schemas/UserForm.php`:
  - `email_verified_at`: `->disabled(fn (string $operation) => $operation === 'edit')`
  - `password`: `->required(fn (string $operation) => $operation === 'create')` + `->dehydrated(fn ($state) => filled($state))`
  - `is_landlord`: `->disabled(fn (?User $record) => $record?->id === auth()->id())` + helperText warning
  - `last_login_at`: `->disabled()`
- `app/Filament/Landlord/Resources/Tenants/RelationManagers/UsersRelationManager.php` — same password guards; is_landlord `->disabled()`

**Create:**
- `tests/Feature/UserPolicyTest.php` — 5 policy method tests

---

## Sub-task 1.18.4 — Scope Gate::before to Tenant Context ✅

**Goal:** Super-admin bypass only fires on the tenant admin panel, not the landlord panel.

**Findings addressed:** M1

**Modify:**
- `app/Providers/AppServiceProvider.php` — wrap Gate::before body in `tenancy()->initialized` check

**Tests:** add to `tests/Feature/LandlordTenantTest.php`

---

## Sub-task 1.18.5 — DB Transactions for Lifecycle + Subscription ✅

**Goal:** Wrap multi-step DB mutations in transactions to prevent race conditions and partial writes.

**Findings addressed:** H3, M9

**Modify:**
- `app/Models/Tenant.php` — wrap suspend/markForDeletion/scheduleForDeletion/reactivate in `DB::transaction()`
- `app/Services/SubscriptionService.php` — wrap recordPaymentAndActivate/handleStripePaymentSucceeded/handleStripePaymentFailed in `DB::transaction()`; also create Payment directly as Completed (not Pending→Completed)

**Depends on:** 1.18.1

---

## Sub-task 1.18.6 — Input Validation Hardening ✅

**Goal:** Sanitize VIES URL; tighten registration validation; add field-length caps; validate plan is_active.

**Findings addressed:** M4, M5, M6, M8

**Modify:**
- `app/Filament/Landlord/Resources/Tenants/Schemas/TenantForm.php` — sanitize `$vatNumber` + `$vatPrefix` with `preg_replace`
- `app/Livewire/RegisterTenant.php` — add `Rule::in(EuCountries::codes())` for country_code; add locale/timezone/currency_code/vat_number rules
- `app/Filament/Landlord/Resources/Tenants/Pages/ViewTenant.php` — `->maxLength(255)` on bank_transfer_reference, `->maxLength(1000)` on notes
- `app/Filament/Landlord/Resources/Tenants/Tables/TenantsTable.php` — same
- `app/Services/SubscriptionService.php` — `is_active` guard in `changePlan()`

**Tests:** add registration + billing validation tests

---

## Sub-task 1.18.7 — Deletion Guard + Command Safety ✅

**Goal:** Add landlord tenant + null-date guards to TenantDeletionGuard. Fix email ordering in delete command.

**Findings addressed:** M10, M11

**Modify:**
- `app/Services/TenantDeletionGuard.php` — add `isLandlordTenant()` + null `deletion_scheduled_for` explicit checks
- `app/Console/Commands/DeleteScheduledTenantsCommand.php` — exclude landlord tenant from query; move email send to after confirmed deletion

**Tests:** add to `tests/Feature/LandlordTenantTest.php`

---

## Sub-task 1.18.8 — Visible Guard Consistency + RelationManager Authorization ✅

**Goal:** Align `->visible()` closures with `->authorize()` policies; lock relation managers on landlord tenant.

**Findings addressed:** M3, M7, L8

**Modify:**
- `app/Filament/Landlord/Resources/Tenants/Tables/TenantsTable.php` — add `&& !$record->isLandlordTenant()` to all 4 lifecycle visible closures
- `app/Filament/Landlord/Resources/Tenants/Pages/ViewTenant.php` — add `&& !$this->record->isLandlordTenant()` to suspend visible
- `app/Filament/Landlord/Resources/Tenants/RelationManagers/DomainsRelationManager.php` — add visible guard + alphaDash validation
- `app/Filament/Landlord/Resources/Tenants/RelationManagers/UsersRelationManager.php` — add visible guard

**Depends on:** 1.18.1

---

## Sub-task 1.18.9 — URL Scheme Helper + Cosmetic Fixes ✅

**Goal:** Replace 9 hardcoded `http://` URLs; fix tenant root route; mask Stripe IDs; add `$hidden` to Tenant model.

**Findings addressed:** L2, L3, L4, M12

**Create:**
- `app/Support/TenantUrl.php` — `to(slug, path)` and `central(path)` deriving scheme from `config('app.url')`

**Modify:**
- `app/Livewire/RegisterTenant.php`, `app/Http/Controllers/StripeCheckoutController.php`, `app/Filament/Pages/SubscriptionExpiredPage.php`, `app/Filament/Pages/SubscriptionPage.php`, `app/Mail/WelcomeTenant.php`, `app/Mail/TrialExpiringSoon.php`, `app/Mail/NewTenantRegistered.php`, `app/Notifications/NewTenantRegisteredNotification.php` — use `TenantUrl::to()` / `TenantUrl::central()`
- `routes/tenant.php` — replace placeholder with `redirect('/admin')`
- `app/Filament/Landlord/Resources/Payments/PaymentResource.php` — mask `stripe_payment_intent_id`
- `app/Models/Tenant.php` — add `$hidden = ['stripe_id', 'pm_type', 'pm_last_four']`

**Create:**
- `tests/Unit/TenantUrlTest.php`

---

## Execution Order

```
Phase A (parallel):  1.18.1, 1.18.2, 1.18.3, 1.18.4
Phase B (needs A):   1.18.5, 1.18.6, 1.18.7
Phase C (needs B):   1.18.8, 1.18.9
```

---

## Verification ✅

- [x] `vendor/bin/pint --dirty --format agent` clean
- [x] `./vendor/bin/sail artisan test --compact` all green (211/211)
- [x] Billing actions have `->authorize()` in ViewTenant + TenantsTable
- [x] ForceDeleteAction/RestoreAction removed from EditTenant
- [x] UserForm password optional on edit, dehydrated guard
- [x] is_landlord toggle disabled for self-edit
- [x] Gate::before guarded by `tenancy()->initialized`
- [x] Lifecycle methods wrapped in `DB::transaction()`
- [x] EIK sanitized before VIES URL
- [x] Registration rejects invalid country_code
- [x] TenantDeletionGuard rejects landlord tenant + null dates
- [x] Delete command sends email AFTER successful deletion
- [x] All URLs use scheme from `config('app.url')`
- [x] Tenant root route redirects to /admin
- [x] stripe_payment_intent_id masked in PaymentResource

## Post-1.18 Additions

- [x] **Bank Details in TenantForm/TenantInfolist** — `bank_name`, `iban`, `bic` fields visible only on the landlord tenant record; stored in `data` JSON column (no migration); `TenantPolicy::updateBankDetails()` method added; 3 policy tests in `TenantBankDetailsPolicyTest.php`
- [x] **Free-plan billing action guard** — `recordPayment` and `sendProformaInvoice` hidden + unauthorized when tenant plan price is €0 or plan is null; `recordPayment`/`sendProformaInvoice` policy methods updated accordingly; 4 additional test cases added

## Accepted Risks (no code changes)

- **L1** LandlordPanelProvider relying on `canAccessPanel()` — Filament idiomatic
- **L5** AdminPanelProvider middleware ordering — operational verification only
- **L6** LT regex alternation — safe given `max:20` at all layers
- **L7** `landlordTenant()` cache orphaning on config rotation — operational procedure
