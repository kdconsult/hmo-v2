# Project Status

> **For AI assistants:** Read this file first. It tells you where we are, what's done, and what's next. Then check `tasks/` for detailed specs and `docs/` for architecture/business logic.

## Current State

**Phase 1 — Foundation & Core SaaS** — Tasks 1.1–1.18 + post-release hardening audit ✅ complete. 232/232 tests pass.

The app is a multi-tenant SaaS ERP (HMO) built with Laravel 13 + Filament v5 + stancl/tenancy. Tenants are Bulgarian SMEs (HMO companies). Landlord is the SaaS operator.

## What Works Today

- Multi-tenant architecture: central DB (landlord) + per-tenant PostgreSQL databases
- Subdomain routing: `hmo.localhost` = landlord, `{slug}.hmo.localhost` = tenant
- Self-registration: 3-step wizard (account → company → plan), 14-day trial auto-started
- Tenant admin panel (`/admin`) with: CRM (partners, contracts, tags), Settings (currencies, VAT rates, document series, users, roles), company settings
- Landlord panel (`/landlord`) with: tenant management, plan management, lifecycle actions (suspend/mark/schedule delete)
- Tenant lifecycle: Active → Suspended → MarkedForDeletion → ScheduledForDeletion → auto-deleted
- Plan management: Free/Starter/Professional with limits (max_users, max_documents)
- Trial expiration: daily command marks expired trials as PastDue, sends emails
- Access control: `EnsureActiveSubscription` middleware redirects to `SubscriptionExpiredPage` for PastDue/Suspended tenants
- Landlord notified (email + Filament DB notification) on every new tenant self-registration
- Payment model (`payments` table) with `PaymentGateway` + `PaymentStatus` enums
- Cashier v16 installed; `Tenant` has `Billable` trait + `stripe_id/pm_type/pm_last_four` columns
- Tenant lifecycle emails fully wired: suspended/marked/scheduled/reactivated/deleted — queued via Redis, requires queue worker
- Landlord tenant table: compact icon buttons + ActionGroup dropdown (View ▪ Edit ▪ ⋮)
- `TenancyServiceProvider` JobPipeline: sync in testing/local, queued in production

## Task 1.16 Progress

**Task 1.16 ✅ COMPLETE** — All 11 sub-tasks done. 123/123 tests pass.

| Sub-task | Status |
|----------|--------|
| 1.16.1 — Subscription expired page + route fix | ✅ |
| 1.16.2 — Landlord notification on self-registration + config/hmo.php | ✅ |
| 1.16.3 — Payment model + PaymentGateway/PaymentStatus enums | ✅ |
| 1.16.4 — SubscriptionService | ✅ |
| 1.16.5 — laravel/cashier v16 + Billable on Tenant | ✅ |
| 1.16.6 — Stripe Checkout flow | ✅ |
| 1.16.7 — Stripe webhook handler | ✅ |
| 1.16.8 — Manual bank transfer flow (landlord panel) | ✅ |
| 1.16.9 — Tenant subscription management page | 🚧 next |
| 1.16.10 — Proforma invoice enhancements (PDF) | ✅ |
| 1.16.11 — Tests | ⬜ |

## Key Technical Decisions

| Decision | Choice | Why |
|----------|--------|-----|
| Tenancy | stancl/tenancy (separate DBs) | True data isolation per tenant |
| Subdomain identification | `InitializeTenancyBySubdomain` | Stores subdomain-only in `domains` table (not full hostname) |
| Billing | Stripe Checkout (payment mode) + bank transfer | App owns subscription state; Bulgarian market prefers bank transfer |
| Subscription state | App-owned (`Tenant.subscription_status`) | Not Stripe/Cashier subscription model |
| Auth | Filament-native per panel | Landlord: `is_landlord` flag. Tenant: TenantUser in tenant DB |
| Permissions | spatie/laravel-permission | Per-tenant roles (super-admin, admin, accountant, viewer...) |

## What's Next

**Phase 2 — Warehouse/WMS + Nomenclature/Catalog**

See `tasks/phase-2.md` for the full spec. Work is tenant-side only. Landlord panel is feature-complete; bugs and minor additions only.

## File Map for New Sessions

| What to check | Where |
|---------------|-------|
| Phase 1 history | `tasks/phase-1.md` |
| Phase 2 tasks | `tasks/phase-2.md` |
| Architecture | `docs/ARCHITECTURE.md` |
| Business logic | `docs/BUSINESS_LOGIC.md` |
| Filament panels | `docs/UI_PANELS.md` |
| Features list | `docs/FEATURES.md` |
| Tenant routes | `routes/tenant.php` |
| Central routes | `routes/web.php` |
| Enums | `app/Enums/` |
| Services | `app/Services/` |
| Landlord panel | `app/Filament/Landlord/` (feature-complete) |
| Tenant panel | `app/Filament/` (non-Landlord) |
| Tenant models | `app/Models/` (central connection ones extend stancl base) |

## Environment

- PHP 8.5, Laravel 13, Filament v5, Livewire v4, Pest v4
- PostgreSQL 17 via Docker (`hmo-postgres` container — only accessible inside Docker network)
- DB migrations: `database/migrations/` (central), `database/migrations/tenant/` (per-tenant)
- `APP_DOMAIN=hmo.localhost` — central domain
- Artisan must be run inside Docker or via Sail: `./vendor/bin/sail artisan ...`

## Post-Release Hardening Audit (2026-04-11) ✅

Security/correctness pass on top of 1.18. 232/232 tests pass (+21 new tests). Full detail in `tasks/phase-1.md`.

### Security
- **S-1** `is_landlord` removed from `#[Fillable]` — mass-assignment privilege escalation blocked
- **S-2** Explicit `Event::listen(WebhookReceived::class, StripeWebhookListener::class)` in `AppServiceProvider::boot()`
- **S-3** Webhook idempotency — replay of `checkout.session.completed` no longer creates duplicate Payment
- **S-4** `RateLimiter` in `RegisterTenant::submit()` — Livewire bypasses route-level throttle

### Bugs
- **B-1** `TenantReactivatedMail` URL fixed — was missing dot separator, hardcoded `https://`; now uses `TenantUrl::to()`
- **B-2** `CheckTrialExpirations` — `TrialExpired` mail changed from `send()` to `queue()`

### Authorization
- **G-1** `ExchangeRatePolicy` created (was missing entirely)
- **G-3** `CompanySettingsPage` — `canAccess()` + `authorize()` in `save()`
- **G-4** `SubscriptionPage::cancelSubscription()` — role guard added

### Infrastructure
- **O-1/O-3** `->domain()` on both Filament panels + `Route::domain()` on web routes
- **O-4** DB indexes on `subscription_status`, `trial_ends_at`, `subscription_ends_at`
