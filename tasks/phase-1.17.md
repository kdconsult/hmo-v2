# Task 1.17 — Landlord Billing Management & Tenant Link

## Context

Task 1.16 (Billing & Subscription Management) is complete. The landlord can record payments and send proforma emails from the TenantsTable, but key management actions were missing: changing a tenant's plan and cancelling subscriptions. Additionally, there was no way to identify the landlord's own tenant — the SaaS operator will also use the app as a regular tenant for their own business (dogfooding).

**Architecture decision:** The landlord does NOT issue invoices from the landlord panel. Instead, when tenant-side invoicing is built (future phase), the landlord will use their own tenant account's invoicing system to bill clients. For now, we build the bridge infrastructure (config-based landlord tenant identification, protection) and improve the existing billing actions.

**Key constraint:** The system operates normally when no landlord tenant is configured (`HMO_LANDLORD_TENANT_ID` is null).

---

## Sub-task 1.17.1 — Landlord Tenant Config & Model Helper ✅

**Goal:** Identify the landlord's own tenant via config, with graceful degradation.

**Modify:**
- `config/hmo.php` — added `'landlord_tenant_id' => env('HMO_LANDLORD_TENANT_ID')` with documentation comment
- `app/Models/Tenant.php` — added `isLandlordTenant(): bool` and `static landlordTenant(): ?self`
- `.env.example` — added `HMO_LANDLORD_TENANT_ID=` with instructions

---

## Sub-task 1.17.2 — DatabaseSeeder: Landlord Tenant ✅

**Goal:** Seeder creates the landlord's own tenant on the highest plan, Active, never expires.

**Modify:**
- `database/seeders/DatabaseSeeder.php` — after demo tenant block:
  - Fetches Professional plan (highest `sort_order` among active plans)
  - Creates tenant with slug `landlord`, Active status, null `subscription_ends_at`, null `trial_ends_at`
  - Sets name/email/EIK/VAT from `config('hmo.*')`
  - Attaches landlord admin user, creates `landlord` domain, calls `TenantOnboardingService::onboard()`
  - Outputs the tenant ID: "Set HMO_LANDLORD_TENANT_ID={id} in .env"

---

## Sub-task 1.17.3 — Change Plan Action ✅

**Goal:** Landlord can change any tenant's plan.

**Modify:**
- `app/Filament/Landlord/Resources/Tenants/Tables/TenantsTable.php` — added `changePlan` action:
  - Icon: `heroicon-o-arrow-path`, color: info
  - Modal: Plan select (active plans, defaults to current plan)
  - Calls `SubscriptionService::changePlan($record, $plan)`
  - Hidden for landlord tenant

---

## Sub-task 1.17.4 — Cancel Subscription Action ✅

**Goal:** Landlord can cancel any tenant's subscription.

**Modify:**
- `app/Filament/Landlord/Resources/Tenants/Tables/TenantsTable.php` — added `cancelSubscription` action:
  - Icon: `heroicon-o-x-mark`, color: danger
  - Confirmation with access-until date in description
  - Calls `SubscriptionService::cancelSubscription($record)`
  - Visible only when `subscription_status === Active`
  - Hidden for landlord tenant

---

## Sub-task 1.17.5 — Landlord Tenant Protection ✅

**Goal:** Landlord tenant cannot be billed, suspended, or deleted.

**Modify:**
- `app/Filament/Landlord/Resources/Tenants/Tables/TenantsTable.php`:
  - `recordPayment`, `sendProformaInvoice`, `suspend`, `markForDeletion`, `scheduleForDeletion` — all hidden for landlord tenant
  - Slug column shows `★` suffix when `isLandlordTenant()`
- `app/Filament/Landlord/Resources/Tenants/Schemas/TenantInfolist.php` — added `IconEntry` showing landlord tenant indicator (star icon, warning color)
- `app/Policies/TenantPolicy.php` — `suspend`, `markForDeletion`, `scheduleForDeletion` return false when `$tenant->isLandlordTenant()`

---

## Sub-task 1.17.6 — Fix Record Payment Period Calculation ✅

**Goal:** Fix hardcoded `period_end` in the Record Payment action.

**Modify:**
- `app/Filament/Landlord/Resources/Tenants/Tables/TenantsTable.php` — replaced hardcoded `now()->addMonth()` with:
  ```php
  match ($plan->billing_period) {
      'monthly' => now()->addMonth()->toDateString(),
      'yearly' => now()->addYear()->toDateString(),
      default => null,
  }
  ```

---

## Sub-task 1.17.7 — Tests ✅

**Create:**
- `tests/Feature/LandlordTenantTest.php` — 7 tests covering `isLandlordTenant()`, `landlordTenant()`, expiration command skip
- `tests/Feature/TenantBillingActionsTest.php` — 7 tests covering changePlan, cancelSubscription, period_end calculations, policy protection

---

## Future: Tenant Invoicing Bridge

> **This section documents what must be done when tenant-side invoicing is built.**

The current proforma/payment flow is **interim**. When the tenant invoicing system (Phase 2+) is implemented, the SaaS billing must be updated as follows:

### Current flow (interim)
```
Landlord panel → "Send Proforma Invoice" → ProformaInvoice mailable (PDF email)
Landlord panel → "Record Payment" → Payment record → SubscriptionService::recordPaymentAndActivate
```

### Future flow (via tenant invoicing)
```
Landlord panel → "Create Invoice" 
  → System identifies landlord tenant via config('hmo.landlord_tenant_id')
  → Creates Invoice record in landlord's tenant DB (using tenant invoicing system)
  → Sends proforma to client tenant's email (via tenant invoicing PDF engine)

Client tenant pays by bank → landlord marks Invoice as Paid in their tenant
  → Event/webhook fires in landlord tenant context
  → Calls SubscriptionService::recordPaymentAndActivate for the client tenant
  → Client subscription activated

Stripe card payments → StripeWebhookListener (no change, bypasses invoicing)
```

### Integration points already in place
1. `config('hmo.landlord_tenant_id')` — identifies the landlord's tenant
2. `Tenant::isLandlordTenant()` and `Tenant::landlordTenant()` — model helpers ready to use
3. `SubscriptionService` — the single service for all subscription state transitions; the bridge must call it
4. `Payment` model — must be created by the bridge when an invoice is paid (links invoice to payment)

### What needs to be built (future)
- `Invoice` model in the central DB linking client tenant → landlord tenant's invoice
- OR: the Invoice lives in the landlord's tenant DB; the landlord panel reads it cross-tenant
- Landlord panel action: "Create Proforma Invoice" → opens in tenant panel context
- "Mark Paid" on Invoice → fires event → bridge calls `SubscriptionService::recordPaymentAndActivate`
- Replace `ProformaInvoice` mailable with tenant-side invoice PDF

---

## Execution Order

```
Phase A (parallel):  1.17.1, 1.17.3, 1.17.4, 1.17.6
Phase B (needs A):   1.17.2, 1.17.5
Phase C (needs B):   1.17.7
```

## Verification

- [x] `vendor/bin/pint --dirty --format agent` clean
- [x] `php artisan test --compact` — 137/137 tests pass
- [x] Landlord tenant created by seeder with Professional plan, Active, null end dates
- [x] `isLandlordTenant()` returns true/false correctly, gracefully handles null config
- [x] `Tenant::landlordTenant()` returns null when not configured
- [x] Billing/lifecycle actions hidden for landlord tenant
- [x] Change Plan action updates tenant's plan_id
- [x] Cancel Subscription action sets status to Cancelled
- [x] Record Payment period_end respects billing_period (monthly/yearly/lifetime)
- [x] TenantPolicy: suspend/markForDeletion/scheduleForDeletion return false for landlord tenant
- [x] CheckSubscriptionExpirations skips tenant with null subscription_ends_at
- [x] Future integration documented
