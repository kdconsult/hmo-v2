# Task 1.16 — Billing & Subscription Management

## Context

Phase 1 (Tasks 1.1–1.15) complete. Tenant self-registration and trial flow works. This task makes the SaaS monetizable.

**Architecture:** App owns subscription state (`Tenant.subscription_status` + `subscription_ends_at`). Stripe (via Cashier Billable trait) handles card payments via Checkout in `payment` mode. Landlord records bank transfer payments manually. Both paths go through `SubscriptionService`.

**Payment model:** Stripe for card-paying tenants, bank transfer + proforma invoice for traditional invoicing. No Cashier `Subscription` model — app manages recurrence via existing scheduled commands.

---

## Sub-task 1.16.1 — Subscription Expired Page ✅

**Goal:** Wire up `subscription.expired` route that `EnsureActiveSubscription` redirects to (currently 404).

**Create:**
- `app/Filament/Pages/SubscriptionExpiredPage.php`
  - `$slug = 'subscription-expired'`
  - `$shouldRegisterNavigation = false`
  - Shows: plan name, status badge, trial/subscription end date, "Contact support" link
  - After 1.16.6: gains "Upgrade Now" per-plan buttons

**Modify:**
- `app/Http/Middleware/EnsureActiveSubscription.php` — add `filament.admin.pages.subscription-expired` to allow-through routes

---

## Sub-task 1.16.2 — Landlord Notification on Self-Registration ✅

**Goal:** Landlord knows when a new tenant signs up.

**Create:**
- `config/hmo.php` — landlord_email, company_name, company_vat, company_eik, company_address (all from env)
- `app/Mail/NewTenantRegistered.php` — to `config('hmo.landlord_email')`. Contains: tenant name, slug, email, plan, timestamp, link
- `resources/views/mail/tenant/new-tenant-registered.blade.php`
- `app/Notifications/NewTenantRegisteredNotification.php` — Filament database notification

**Modify:**
- `app/Livewire/RegisterTenant.php` — after line 117: send mail + notify all `User::where('is_landlord', true)`
- `app/Providers/Filament/LandlordPanelProvider.php` — add `->databaseNotifications()`

---

## Sub-task 1.16.3 — Payment Model + Enums ✅

**Goal:** Central DB records for every payment (Stripe or bank transfer).

**Create:**
- `app/Enums/PaymentGateway.php` — Stripe, BankTransfer, Manual (HasLabel/HasColor/HasIcon)
- `app/Enums/PaymentStatus.php` — Pending, Completed, Failed, Refunded (HasLabel/HasColor/HasIcon)
- Migration `create_payments_table`: tenant_id (FK), plan_id (FK nullable), amount (decimal 10,2), currency (string 3 default 'EUR'), gateway, status, stripe_payment_intent_id (nullable unique), bank_transfer_reference, notes, paid_at, period_start (date), period_end (date), recorded_by (FK users nullable), timestamps
- `app/Models/Payment.php` — belongsTo Tenant/Plan/recordedBy, casts, scopes: completed/pending/forTenant
- `database/factories/PaymentFactory.php`

**Modify:**
- `app/Models/Tenant.php` — add `payments(): HasMany`

---

## Sub-task 1.16.4 — SubscriptionService ✅

**Goal:** Single service for all subscription state transitions.

**Create `app/Services/SubscriptionService.php`:**
- `activateSubscription(Tenant, Plan, ?Carbon $endsAt): void` — plan_id, status=Active, subscription_ends_at
- `recordPaymentAndActivate(Tenant, Plan, Payment): void` — completes payment, calculates end date from billing_period (monthly=+1mo, yearly=+1yr, lifetime=null), calls activateSubscription
- `changePlan(Tenant, Plan): void` — updates plan_id
- `cancelSubscription(Tenant): void` — status=Cancelled, access until subscription_ends_at
- `handleStripePaymentSucceeded(Tenant, string $paymentIntentId, float $amount): Payment`
- `handleStripePaymentFailed(Tenant, string $paymentIntentId): void`

**Depends on:** 1.16.3

---

## Sub-task 1.16.5 — Install Cashier + Stripe Config ✅

**Goal:** Stripe infrastructure, Billable on Tenant.

**Steps:**
1. `composer require laravel/cashier`
2. `Cashier::ignoreMigrations()` in `AppServiceProvider::register()`
3. Create migration `add_stripe_columns_to_tenants`: stripe_id (string nullable unique), pm_type (string nullable), pm_last_four (string 4 nullable)
4. `php artisan vendor:publish --tag="cashier-config"` → edit: model=Tenant, currency=eur

**Modify:**
- `app/Models/Tenant.php` — `use Billable`, add stripe columns to `getCustomColumns()`
- `.env.example` — STRIPE_KEY, STRIPE_SECRET, STRIPE_WEBHOOK_SECRET, CASHIER_CURRENCY=eur

---

## Sub-task 1.16.6 — Stripe Checkout Flow ✅

**Goal:** Tenant can pay by card via Stripe Checkout.

**Create:**
- `app/Http/Controllers/StripeCheckoutController.php`:
  - `createCheckoutSession(Request)` — validates plan_id, creates/gets Stripe customer, creates Checkout Session in `payment` mode, tenant_id + plan_id in metadata
  - `checkoutSuccess(Request)` — "Payment processing..." page

**Modify:**
- `routes/tenant.php` — POST `/checkout` + GET `/checkout/success` inside subdomain group (with web + InitializeTenancyBySubdomain + auth middleware)
- `app/Filament/Pages/SubscriptionExpiredPage.php` — add Upgrade buttons (per paid plan)
- `app/Http/Middleware/EnsureActiveSubscription.php` — allow `checkout.*` through

**Depends on:** 1.16.1, 1.16.4, 1.16.5

---

## Sub-task 1.16.7 — Stripe Webhook Handler ✅

**Goal:** Process Stripe events to activate/flag subscriptions.

**Create:**
- `app/Http/Controllers/StripeWebhookController.php`:
  - `checkout.session.completed` → extract tenant_id + plan_id from metadata → SubscriptionService::handleStripePaymentSucceeded
  - `payment_intent.payment_failed` → SubscriptionService::handleStripePaymentFailed

**Modify:**
- `routes/web.php` — POST `/stripe/webhook` (central domain, NO tenancy middleware)
- `bootstrap/app.php` — exclude `/stripe/webhook` from CSRF

**Depends on:** 1.16.4, 1.16.5

---

## Sub-task 1.16.8 — Manual Bank Transfer Flow ✅

**Goal:** Landlord can record bank payments and send proforma invoices.

**Create:**
- `app/Filament/Landlord/Resources/Payments/` — PaymentResource (list + view, read-only)

**Modify:**
- `app/Filament/Landlord/Resources/Tenants/Tables/TenantsTable.php` — add:
  - "Record Payment" action → modal (plan, amount prefilled, currency, bank_transfer_reference, notes) → Payment created + SubscriptionService::recordPaymentAndActivate
  - "Send Proforma Invoice" action → sends ProformaInvoice mail to tenant owner

**Depends on:** 1.16.3, 1.16.4

---

## Sub-task 1.16.9 — Tenant Subscription Management Page ✅

**Goal:** Tenant can see their plan, usage, and upgrade/cancel.

**Create:**
- `app/Filament/Pages/SubscriptionPage.php` — Settings nav group, icon=credit-card. Shows: plan name/features, status badge, end date, usage (users + documents via PlanLimitService), available plans with Upgrade buttons, Cancel button

**Modify:**
- `app/Services/PlanLimitService.php` — add `getUsageSummary(Tenant): array`
- `app/Http/Middleware/EnsureActiveSubscription.php` — allow subscription page through for past-due tenants

**Depends on:** 1.16.6

---

## Sub-task 1.16.10 — Proforma Invoice Enhancements ✅

**Goal:** Make proforma invoice production-ready with real company/bank details and PDF.

**Create:**
- `resources/views/pdf/proforma-invoice.blade.php` — Bulgarian-format PDF: company details, client details, line items, VAT, total, bank IBAN/BIC, payment reference

**Modify:**
- `app/Mail/ProformaInvoice.php` — pull bank details from config('hmo.*'), attach PDF via barryvdh/laravel-dompdf
- `resources/views/mail/tenant/proforma-invoice.blade.php` — real bank details, payment reference `{slug}-{plan}-{YYYYMM}`

**Depends on:** 1.16.2 (config/hmo.php), 1.16.3

---

## Sub-task 1.16.11 — Tests ✅

**Create:**
- `tests/Feature/SubscriptionServiceTest.php` — activateSubscription, recordPaymentAndActivate (end date calculation), cancel, stripe success/fail handlers
- `tests/Feature/PaymentModelTest.php` — relationships, scopes, casts
- `tests/Feature/SubscriptionExpiredPageTest.php` — middleware redirect, page accessible when expired
- `tests/Feature/StripeWebhookTest.php` — session.completed activates, payment failed marks past_due, invalid signature = 403, unknown tenant = 200
- `tests/Feature/NewTenantNotificationTest.php` — email sent, DB notification created

**Depends on:** All previous

---

## Execution Order

```
Phase A (parallel):  1.16.1, 1.16.2, 1.16.3, 1.16.5
Phase B (needs A):   1.16.4, 1.16.10
Phase C (needs B):   1.16.6, 1.16.7, 1.16.8
Phase D (needs C):   1.16.9, 1.16.11
```

## Verification

- [ ] `vendor/bin/pint --dirty --format agent` clean
- [ ] `php artisan test --compact` all green
- [ ] Self-register → landlord receives email + Filament notification
- [ ] Trial expires → redirected to subscription expired page (not 404)
- [ ] Stripe Checkout → webhook fires → subscription activated
- [ ] Landlord "Record Payment" → subscription activated
- [ ] Landlord "Send Proforma Invoice" → tenant receives email with PDF
- [ ] Tenant subscription page shows usage stats and upgrade options
