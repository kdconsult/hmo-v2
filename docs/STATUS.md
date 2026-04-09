# Project Status

> **For AI assistants:** Read this file first. It tells you where we are, what's done, and what's next. Then check `tasks/` for detailed specs and `docs/` for architecture/business logic.

## Current State

**Phase 1 — Foundation & Core SaaS** — Tasks 1.1–1.17 ✅ complete. 137/137 tests pass.

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

## File Map for New Sessions

| What to check | Where |
|---------------|-------|
| Task progress | `tasks/phase-1.md` |
| Current task spec | `tasks/phase-1.16.md` |
| Architecture | `docs/ARCHITECTURE.md` |
| Business logic | `docs/BUSINESS_LOGIC.md` |
| Filament panels | `docs/UI_PANELS.md` |
| Features list | `docs/FEATURES.md` |
| Tenant routes | `routes/tenant.php` |
| Central routes | `routes/web.php` |
| Enums | `app/Enums/` |
| Services | `app/Services/` |
| Landlord panel | `app/Filament/Landlord/` |
| Tenant panel | `app/Filament/` (non-Landlord) |
| Tenant models | `app/Models/` (central connection ones extend stancl base) |

## Environment

- PHP 8.5, Laravel 13, Filament v5, Livewire v4, Pest v4
- PostgreSQL 17 via Docker (`hmo-postgres` container — only accessible inside Docker network)
- DB migrations: `database/migrations/` (central), `database/migrations/tenant/` (per-tenant)
- `APP_DOMAIN=hmo.localhost` — central domain
- Artisan must be run inside Docker or via Sail: `./vendor/bin/sail artisan ...`

## Task 1.17 — Complete

| Sub-task | Status |
|----------|--------|
| 1.17.1 — Landlord tenant config + Tenant model helpers | ✅ |
| 1.17.2 — DatabaseSeeder: landlord tenant | ✅ |
| 1.17.3 — Change Plan action | ✅ |
| 1.17.4 — Cancel Subscription action | ✅ |
| 1.17.5 — Landlord tenant protection | ✅ |
| 1.17.6 — Fix Record Payment period_end | ✅ |
| 1.17.7 — Future invoicing bridge documented | ✅ |
| 1.17.8 — Tests | ✅ |

## Recent Changes (last session)

- Task 1.16 Phase A: SubscriptionExpiredPage, Payment model/enums/factory, PaymentGateway/PaymentStatus enums, config/hmo.php, NewTenantRegistered mail+notification, Cashier v16 + Billable on Tenant, stripe columns migration
- Fixed `phpunit.xml` DB_HOST: `127.0.0.1` → `hmo-postgres` (tests now pass: 97/97)
