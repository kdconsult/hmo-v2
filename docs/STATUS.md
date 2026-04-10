# Project Status

> **For AI assistants:** Read this file first. It tells you where we are, what's done, and what's next. Then check `tasks/` for detailed specs and `docs/` for architecture/business logic.

## Current State

**Phase 1 — Foundation & Core SaaS** — Tasks 1.1–1.18 ✅ complete. 211/211 tests pass.

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

## Task 1.18 — Security Hardening ✅

| Sub-task | Status |
|----------|--------|
| 1.18.1 — Billing policy methods + →authorize() enforcement | ✅ |
| 1.18.2 — Remove ForceDelete/Restore + belt-and-suspenders policy | ✅ |
| 1.18.3 — UserPolicy + UserForm hardening | ✅ |
| 1.18.4 — Scope Gate::before to tenant context | ✅ |
| 1.18.5 — DB transactions for lifecycle + subscription | ✅ |
| 1.18.6 — Input validation hardening | ✅ |
| 1.18.7 — Deletion guard + command safety | ✅ |
| 1.18.8 — Visible guard consistency + RelationManager authorization | ✅ |
| 1.18.9 — URL scheme helper + cosmetic fixes | ✅ |

## Recent Changes (Task 1.18)

- **Billing action authorization** — `->hidden()` replaced with `->visible()` + `->authorize()` on all 4 billing actions in ViewTenant + TenantsTable; 4 billing policy methods added to TenantPolicy
- **ForceDelete/Restore removed** — dead-code actions removed from EditTenant; `forceDelete/restore` policy methods return `false` as safety net
- **UserPolicy created** — covers viewAny/view/create/update/delete; delete prevents self-deletion
- **UserForm hardened** — password optional on edit with dehydrated guard; `is_landlord` toggle disabled for self-edit; `email_verified_at`/`last_login_at` read-only on edit
- **Gate::before scoped** — super-admin bypass only fires when `tenancy()->initialized` (tenant panel), not on landlord panel
- **DB transactions** — lifecycle methods (suspend/markForDeletion/scheduleForDeletion/reactivate) and SubscriptionService methods wrapped in `DB::transaction()`
- **Stripe payment atomicity** — `handleStripePaymentSucceeded` creates Payment as Completed directly (no Pending→Completed two-step)
- **VIES URL sanitization** — vatNumber/vatPrefix sanitized with `preg_replace` before URL insertion
- **Country code allowlist** — Registration step 2 validates `country_code` via `Rule::in(EuCountries::codes())`
- **Field length caps** — `bank_transfer_reference` max 255, `notes` max 1000 in RecordPayment form
- **changePlan inactive guard** — `SubscriptionService::changePlan()` throws if plan is not active
- **TenantDeletionGuard** — added landlord tenant check + explicit null `deletion_scheduled_for` check
- **Delete command safety** — email sent AFTER successful deletion; landlord tenant excluded from query
- **Visible guard consistency** — all 4 lifecycle visible closures include `!isLandlordTenant()` in both TenantsTable + ViewTenant
- **RelationManager guards** — DomainsRelationManager and UsersRelationManager create/delete/dissociate hidden on landlord tenant; domain field has `->alphaDash()` validation
- **TenantUrl helper** — `app/Support/TenantUrl.php` derives scheme from `config('app.url')`; all 9 hardcoded `http://` occurrences replaced
- **Tenant root route** — placeholder replaced with `redirect('/admin')` (no UUID leak)
- **Stripe ID masking** — `stripe_payment_intent_id` masked as `pi_xxxxx...YYYY` in PaymentResource
- **Tenant model $hidden** — `stripe_id`, `pm_type`, `pm_last_four` hidden from serialization
- **Tests** — 211/211 pass (+40 new tests: TenantBillingPolicyTest × 16, UserPolicyTest × 9, LandlordTenantTest +7, TenantBillingActionsTest +1, TenantUrlTest × 6, TenantBankDetailsPolicyTest × 3)
- **Bank Details in TenantForm + TenantInfolist** — `bank_name`, `iban`, `bic` fields visible only on the landlord tenant's edit/view pages; stored transparently in stancl `data` JSON column (no migration); `TenantPolicy::updateBankDetails()` guards the operation
- **Free-plan billing guard** — `recordPayment` and `sendProformaInvoice` hidden and policy-denied when the tenant is on a €0 plan or has no plan assigned
