# Business Logic: Services, RBAC & Automation

This document details the core business logic systems built in Phase 1: role-based access control, financial services, tenant lifecycle management, plan/subscription handling, automated commands, and mail notifications.

---

## 1. RBAC System (Role-Based Access Control)

### 10 Roles

All roles are registered in tenant databases via `RolesAndPermissionsSeeder`:

1. **super-admin** — Bypasses all permission gates (via `Gate::before()` in `AppServiceProvider`). No specific permissions assigned.
2. **admin** — Full CRUD on all Phase 1 models.
3. **sales-manager** — CRUD partners, view contracts, CRUD tags.
4. **sales-agent** — Create/update partners (no delete), view contracts, view tags.
5. **accountant** — View partners/contracts, CRUD currencies/VAT rates, view document series.
6. **viewer** — View-only access to all Phase 1 models.
7. **warehouse-manager** — Minimal Phase 1 access (reserved for later phases).
8. **field-technician** — Minimal Phase 1 access (reserved for later phases).
9. **finance-manager** — View-only on all Phase 1 models (expanded in Phase 2).
10. **purchasing-manager** — Minimal Phase 1 access (reserved for later phases).
11. **report-viewer** — View list (`view_any_*`) permissions only.

### Permission Naming Convention

All permissions follow the pattern: `{action}_{model}`

**Actions** (5 per model):
- `view_any_{model}` — List/browse multiple records
- `view_{model}` — View a single record
- `create_{model}` — Create a new record
- `update_{model}` — Edit a record
- `delete_{model}` — Delete a record

**Models** (10 Phase 1 models):
- `partner`, `contract`, `currency`, `exchange_rate`, `vat_rate`, `document_series`, `tenant_user`, `tag`, `company_settings`, `role`

Total: 50 permissions seeded per tenant.

### Gate::before Super-Admin Bypass

Location: `/app/Providers/AppServiceProvider.php` lines 27-32

```php
Gate::before(function ($user, $ability) {
    if (method_exists($user, 'hasRole') && $user->hasRole('super-admin')) {
        return true;
    }
});
```

When a policy is checked, if the authenticated user has the `super-admin` role, all abilities return `true` immediately, bypassing all individual permission checks.

### 9 Policies

Each policy is located in `/app/Policies/` and guards a specific resource:

| Policy | Model | Methods | Guard |
|--------|-------|---------|-------|
| `CompanySettingsPolicy` | CompanySettings | viewAny, view, create, update, delete | Permission-based (spatie/permission) |
| `ContractPolicy` | Contract | viewAny, view, create, update, delete, restore, forceDelete | Permission-based |
| `CurrencyPolicy` | Currency | viewAny, view, create, update, delete, restore, forceDelete | Permission-based |
| `DocumentSeriesPolicy` | DocumentSeries | viewAny, view, create, update, delete, restore, forceDelete | Permission-based |
| `PartnerPolicy` | Partner | viewAny, view, create, update, delete, restore, forceDelete | Permission-based |
| `RolePolicy` | Role (Spatie Role) | viewAny, view, create, update, delete | Permission-based |
| `TagPolicy` | Tag | viewAny, view, create, update, delete, restore, forceDelete | Permission-based |
| `TenantUserPolicy` | TenantUser | viewAny, view, create, update, delete, restore, forceDelete | Permission-based |
| `VatRatePolicy` | VatRate | viewAny, view, create, update, delete, restore, forceDelete | Permission-based |
| `TenantPolicy` | Tenant (landlord DB) | viewAny, view, create, update, delete, suspend, markForDeletion, scheduleForDeletion, reactivate | is_landlord flag (NOT permission-based) |

#### TenantPolicy (Landlord-Only)

Location: `/app/Policies/TenantPolicy.php`

Unique to the central/landlord database — checks `is_landlord` flag directly:

- `viewAny()`, `view()`, `create()`, `update()` → `$user->is_landlord`
- `delete()` → **always `false`** (direct deletion forbidden; only automated script can delete)
- `suspend()` → `is_landlord` AND `tenant->isActive()`
- `markForDeletion()` → `is_landlord` AND `tenant->isSuspended()`
- `scheduleForDeletion()` → `is_landlord` AND `tenant->status === TenantStatus::MarkedForDeletion`
- `reactivate()` → `is_landlord` AND `!tenant->isActive()`

#### Permission-Based Policies

All other policies follow this pattern:

```php
public function viewAny(User $user): bool
{
    return $user->hasPermissionTo('view_any_{model}');
}
```

Methods like `restore()` and `forceDelete()` reuse the `delete_{model}` permission.

---

## 2. Services

### VatCalculationService

Location: `/app/Services/VatCalculationService.php`

Handles VAT calculations for Bulgarian (and EU) fiscal compliance. All methods return an associative array:

```
['net' => float, 'vat' => float, 'gross' => float, 'rate' => float]
```

**Methods:**

- `fromNet(float $net, float $rate): array`
  - Calculates VAT from an exclusive (pre-tax) amount.
  - Formula: `vat = net × (rate / 100)`
  - Returns net, vat, gross, rate.

- `fromGross(float $gross, float $rate): array`
  - Calculates VAT from an inclusive (post-tax) amount.
  - Formula: `net = gross / (1 + rate / 100)`
  - Returns net, vat, gross, rate.

- `calculate(float $amount, float $rate, PricingMode $mode): array`
  - Routes to `fromNet()` or `fromGross()` based on `PricingMode` enum.
  - `PricingMode::VatExclusive` → `fromNet()`
  - `PricingMode::VatInclusive` → `fromGross()`

- `calculateDocument(array $lines): array`
  - Multi-line document calculation.
  - Input: `[['amount' => float, 'rate' => float, 'mode' => PricingMode], ...]`
  - Sums all line VATs and groups by rate percentage.
  - Returns: `['net' => float, 'vat' => float, 'gross' => float, 'vat_breakdown' => ['20.00%' => 100.00, ...]]`

All monetary values are rounded to 2 decimal places.

### ViesValidationService

Location: `/app/Services/ViesValidationService.php`

Validates EU VAT numbers via the official VIES (VAT Information Exchange System) SOAP service.

**Cache:**
- 24-hour TTL per country+VAT number combination.
- Key format: `vies_validation_{countryCode}_{vatNumber}`

**Method:**

- `validate(string $countryCode, string $vatNumber): array`
  - Returns cached result if available, otherwise calls VIES.
  - Input: ISO 2-letter country code (e.g., `BG`, `DE`), VAT number (cleaned of non-alphanumeric).
  - Returns: `['valid' => bool, 'name' => ?string, 'address' => ?string, 'country_code' => string, 'vat_number' => string]`
  - On SOAP failure: logs warning, returns `valid: false` with nulled name/address.

**SOAP Configuration:**
- WSDL: `https://ec.europa.eu/taxation_customs/vies/checkVatService.wsdl`
- 10-second connection timeout.

### PlanLimitService

Location: `/app/Services/PlanLimitService.php`

Enforces tenant plan limits for users and documents.

**Methods:**

- `canAddUser(Tenant $tenant): bool`
  - Checks if tenant can create another user based on plan's `max_users`.
  - Runs query in tenant database context.
  - Returns `true` if `max_users` is null (unlimited) or if current count < limit.

- `canCreateDocument(Tenant $tenant, int $currentMonthCount): bool`
  - Checks if tenant can create a document this month.
  - Parameter: current month's document count for the tenant.
  - Returns `true` if `max_documents` is null (unlimited) or if passed count < limit.
  - Caller must query and pass the month's document count; service only compares.

### TenantDeletionGuard

Location: `/app/Services/TenantDeletionGuard.php`

Enforces lifecycle preconditions before a tenant database is permanently deleted. Called by the `DeletingTenant` event listener in `TenancyServiceProvider` (skip in testing environment).

**Method:**

- `static check(Tenant $tenant): void`
  - Throws `RuntimeException` if:
    1. Tenant status is NOT `ScheduledForDeletion`
    2. `deletion_scheduled_for` timestamp is in the future
  - Used to prevent accidental/early deletion via the UI; only the `DeleteScheduledTenantsCommand` scheduled job can delete.

### TenantOnboardingService

Location: `/app/Services/TenantOnboardingService.php`

Sets up a newly created tenant's database and owner user record.

**Method:**

- `onboard(Tenant $tenant, User $ownerUser): void`
  - Runs inside the tenant's database context.
  - Executes seeders in order: `RolesAndPermissionsSeeder`, `CurrencySeeder`, `VatRateSeeder`.
  - Creates or retrieves a `TenantUser` record linking the owner.
  - Assigns the `admin` role to the owner if not already assigned.

---

## 3. Tenant Lifecycle Management

### TenantStatus State Machine

Location: `/app/Enums/TenantStatus.php`

Four states with strict transition rules:

| State | Meaning |
|-------|---------|
| **Active** | Tenant can use the system normally. |
| **Suspended** | Access blocked (due to non-payment or admin action). |
| **MarkedForDeletion** | Suspended for ~3+ months; final grace period before auto-delete. |
| **ScheduledForDeletion** | Ready for automated permanent deletion. |

**Valid Transitions** (enforced by `canTransitionTo()` method):

```
Active
  ├─→ Suspended (admin deactivation or non-payment)
  └─→ ScheduledForDeletion (tenant-initiated deletion, skips mark step)

Suspended
  ├─→ Active (reactivation after payment)
  └─→ MarkedForDeletion (3+ months unpaid)

MarkedForDeletion
  ├─→ Active (reactivation during grace period)
  └─→ ScheduledForDeletion (5+ months unpaid)

ScheduledForDeletion
  └─→ Active (emergency reactivation before auto-delete runs)
```

Attempting an invalid transition throws `RuntimeException`.

### Tenant Lifecycle Methods

Location: `/app/Models/Tenant.php` lines 174–245

- `suspend(User $by, string $reason): void`
  - Sets status to `Suspended`, records `deactivated_at`, `deactivated_by`, `deactivation_reason`.
  - Reason: `'non_payment'` | `'tenant_request'` | `'other'`

- `markForDeletion(): void`
  - Sets status to `MarkedForDeletion`, records `marked_for_deletion_at`.

- `scheduleForDeletion(?Carbon $deleteOn = null): void`
  - Sets status to `ScheduledForDeletion`, records `scheduled_for_deletion_at`, `deletion_scheduled_for`.
  - Default: 30 days from now if `$deleteOn` not provided.

- `reactivate(): void`
  - Sets status back to `Active`, clears all deactivation/deletion fields.

- **Helper methods:**
  - `isActive(): bool` — `status === Active`
  - `isSuspended(): bool` — `status === Suspended`
  - `isPendingDeletion(): bool` — `status === MarkedForDeletion || ScheduledForDeletion`

- **Query scopes:**
  - `scopeActive()`, `scopeSuspended()`, `scopeMarkedForDeletion()`, `scopeScheduledForDeletion()`, `scopeDueForDeletion()`

### TenancyServiceProvider Safety Guard

Location: `/app/Providers/TenancyServiceProvider.php` lines 45–54

The `DeletingTenant` event listener invokes `TenantDeletionGuard::check()` unless in testing environment:

```php
Events\DeletingTenant::class => [
    function (Events\DeletingTenant $event) {
        if (app()->environment('testing')) {
            return;
        }
        TenantDeletionGuard::check($event->tenant);
    },
],
```

This ensures:
- No accidental deletions via `$tenant->delete()` in the UI.
- Only tenants with `status = ScheduledForDeletion` and `deletion_scheduled_for ≤ now()` can be deleted.
- Tests can delete freely for cleanup.

### DeleteScheduledTenantsCommand

Location: `/app/Console/Commands/DeleteScheduledTenantsCommand.php`

**Signature:** `hmo:delete-scheduled-tenants`

**Description:** Permanently delete tenants whose deletion date has passed.

**Behavior:**
1. Queries: `Tenant::scheduledForDeletion()->dueForDeletion()->get()`
2. Attempts to delete each tenant via `$tenant->delete()`.
3. If `TenantDeletionGuard` check passes, the tenant database and row are deleted.
4. Logs success/failure per tenant; outputs summary.

**Scheduled:** Daily (any time).

---

## 4. Plans & Subscriptions

### Plan Model

Location: `/app/Models/Plan.php`

Central (landlord) database model. Fields:

| Field | Type | Notes |
|-------|------|-------|
| `id` | int | PK |
| `name` | string | Display name (e.g., "Professional") |
| `slug` | string | URL-safe identifier (e.g., "professional") |
| `price` | decimal(10,2) | Monthly price in EUR |
| `billing_period` | string nullable | Billing cadence (e.g., "monthly") |
| `max_users` | int nullable | User limit; null = unlimited |
| `max_documents` | int nullable | Monthly document limit; null = unlimited |
| `features` | json | Feature flags (e.g., `{"invoicing": "true", "api_access": "true"}`) |
| `is_active` | boolean | Soft-disable without deletion |
| `sort_order` | int | Display order in UI |

**Methods:**

- `tenants(): HasMany` — Tenants on this plan.
- `isFree(): bool` — True if `price === 0.0`.

**Seeded Plans:**

1. **Free** — $0, max_users=2, max_documents=100, basic features, inactive by default.
2. **Professional** — €49/month, unlimited users/documents, all features including fiscal + API access.

### SubscriptionStatus Enum

Location: `/app/Enums/SubscriptionStatus.php`

Tenant subscription state. Values:

| Status | Meaning |
|--------|---------|
| **Trial** | 14-day free trial (default for new tenants) |
| **Active** | Paid subscription; access granted |
| **PastDue** | Subscription expired or trial ended; access blocked |
| **Suspended** | Administratively suspended (reserved for future) |
| **Cancelled** | Subscription cancelled by tenant (reserved for future) |

**Method:**

- `isAccessible(): bool`
  - Returns `true` only for `Trial` and `Active` states.
  - Used by `Tenant->isSubscriptionAccessible()` to gate access.

Each status has Filament UI metadata:
- `getLabel()` — Localized label
- `getColor()` — Color name for Filament badge (info, success, warning, danger, gray)
- `getIcon()` — Heroicon (Clock, CheckCircle, ExclamationCircle, Pause, XCircle)

### Tenant Subscription Fields

Location: `/app/Models/Tenant.php` lines 23–32

| Field | Type | Notes |
|-------|------|-------|
| `plan_id` | int | FK to plans table |
| `subscription_status` | string (enum) | SubscriptionStatus |
| `trial_ends_at` | datetime | When trial expires (14 days from created_at) |
| `subscription_ends_at` | datetime | When paid subscription expires |

**Subscription Helper Methods** (lines 138–154):

- `onTrial(): bool` — True if status is Trial AND trial_ends_at is in future.
- `hasActiveSubscription(): bool` — True if status is Active.
- `isSubscriptionAccessible(): bool` — True if subscription_status->isAccessible() (i.e., Trial or Active).

### EnsureActiveSubscription Middleware

Location: `/app/Http/Middleware/EnsureActiveSubscription.php`

Blocks access to the application for tenants with inaccessible subscriptions.

**Behavior:**
1. Get current tenant from tenancy.
2. If tenant is null (central request), allow through.
3. If `!tenant->isSubscriptionAccessible()`, redirect to `subscription.expired` route (except for logout and the expired page itself).
4. Otherwise, allow request through.

**Exempted Routes:**
- `filament.admin.auth.logout`
- `subscription.expired`

### CheckTrialExpirations Command

Location: `/app/Console/Commands/CheckTrialExpirations.php`

**Signature:** `app:check-trial-expirations`

**Description:** Mark expired trials as past_due and send warning emails for trials expiring soon.

**Behavior:**

1. **Warning emails** (3 days before expiry):
   - Queries: `subscription_status = Trial` AND `trial_ends_at` between now+2d to now+3d.
   - Sends `TrialExpiringSoon` mail to tenant owner.

2. **Expiration**:
   - Queries: `subscription_status = Trial` AND `trial_ends_at ≤ now`.
   - Updates status to `PastDue`.
   - Sends `TrialExpired` mail to tenant owner.

**Scheduled:** Daily at 06:00.

### CheckSubscriptionExpirations Command

Location: `/app/Console/Commands/CheckSubscriptionExpirations.php`

**Signature:** `app:check-subscription-expirations`

**Description:** Mark paid subscriptions as past_due when their subscription_ends_at has passed.

**Behavior:**
- Queries: `subscription_status = Active` AND `subscription_ends_at ≤ now`.
- Updates status to `PastDue` for each expired subscription.
- Logs each transition.

**Scheduled:** Daily at 06:05 (5 minutes after trial checks).

---

## 5. Mail Classes

All mail classes implement Filament's `Mailable` pattern. Queued (ShouldQueue) mail is processed asynchronously.

### Subscription/Trial Emails

| Class | Queueable | Trigger | Recipients | Template |
|-------|-----------|---------|------------|----------|
| `WelcomeTenant` | No | New tenant created, trial starts | Tenant owner | `mail.tenant.welcome` (markdown) |
| `TrialExpiringSoon` | No | Trial expires in 3 days | Tenant owner | `mail.tenant.trial-expiring` (markdown) |
| `TrialExpired` | No | Trial expired | Tenant owner | `mail.tenant.trial-expired` (markdown) |
| `ProformaInvoice` | No | Plan purchase initiated | Tenant owner | `mail.tenant.proforma-invoice` (markdown) |

### Tenant Lifecycle Emails

| Class | Queueable | Trigger | Recipients | Template |
|-------|-----------|---------|------------|----------|
| `TenantSuspendedMail` | Yes | Tenant suspended | N/A | `mail.tenant.suspended` (Blade) |
| `TenantMarkedForDeletionMail` | Yes | Tenant marked for deletion | N/A | `mail.tenant.marked-for-deletion` (Blade) |
| `TenantScheduledForDeletionMail` | Yes | Tenant scheduled for deletion | N/A | `mail.tenant.scheduled-for-deletion` (Blade) |
| `TenantReactivatedMail` | Yes | Tenant reactivated | N/A | `mail.tenant.reactivated` (Blade) |
| `TenantDeletedMail` | Yes | Tenant permanently deleted | N/A | `mail.tenant.deleted` (Blade) |

**Note:** Lifecycle emails have TODO stubs for view templates. Only subscription/trial emails have implemented markdown templates.

### Mail Method Signatures

**Subscription Emails:**
```php
// WelcomeTenant, TrialExpiringSoon, TrialExpired
__construct(Tenant $tenant, User $user)

// ProformaInvoice
__construct(Tenant $tenant, User $user, Plan $plan)
```

**Lifecycle Emails:**
```php
// All tenant lifecycle emails
__construct(Tenant $tenant)
```

---

## 6. Scheduled Commands

Location: `/routes/console.php`

Three commands run on a daily schedule:

| Command | Signature | Time | Frequency | Purpose |
|---------|-----------|------|-----------|---------|
| `DeleteScheduledTenantsCommand` | `hmo:delete-scheduled-tenants` | Any (daily) | Daily | Permanently delete tenants due for deletion |
| `CheckTrialExpirations` | `app:check-trial-expirations` | 06:00 | Daily | Warn/expire trials, send emails |
| `CheckSubscriptionExpirations` | `app:check-subscription-expirations` | 06:05 | Daily | Expire paid subscriptions, mark past_due |

**Execution:**
```php
Schedule::command(DeleteScheduledTenantsCommand::class)->daily();
Schedule::command(CheckTrialExpirations::class)->dailyAt('06:00');
Schedule::command(CheckSubscriptionExpirations::class)->dailyAt('06:05');
```

Use `php artisan schedule:run` to execute all scheduled commands (typically called by a cron job every minute).

---

## 7. Seeders

All seeders are located in `/database/seeders/`. Seeds run in tenant context when onboarding a new tenant.

### DatabaseSeeder (Central DB)

Location: `/database/seeders/DatabaseSeeder.php`

Runs on `php artisan migrate:fresh --seed` or `php artisan db:seed`. Creates:

1. **Plans** (via PlanSeeder):
   - Free plan (2 users, 100 docs/month)
   - Professional plan (unlimited users/docs, €49/month)

2. **Landlord admin user:**
   - Email: `admin@hmo.localhost`
   - Password: `password`
   - Flag: `is_landlord = true`

3. **Tenant admin user (central DB):**
   - Email: `tenant-admin@hmo.localhost`
   - Password: `password`

4. **Demo tenant:**
   - Slug: `demo`
   - Name: `Demo Company`
   - Country: `BG` (Bulgaria)
   - Locale: `bg_BG`
   - Timezone: `Europe/Sofia`
   - Currency: EUR (default)
   - Plan: Free
   - Subscription: Trial, expires 14 days from now
   - Domain: `demo.{app_domain}` (e.g., `demo.hmo.localhost`)

5. **Tenant onboarding:**
   - Calls `TenantOnboardingService::onboard()` to seed tenant DB and create TenantUser.

### RolesAndPermissionsSeeder (Tenant DB)

Location: `/database/seeders/RolesAndPermissionsSeeder.php`

Creates all 10 roles and 50 permissions (5 actions × 10 models). Permission assignment:

- **super-admin**: No permissions (bypasses all via Gate::before).
- **admin**: All 50 permissions.
- **sales-manager**: CRUD partners, view contracts, CRUD tags.
- **sales-agent**: Create/update partners, view contracts, view tags.
- **accountant**: View partners/contracts, CRUD currencies/exchange rates/VAT rates, view document series.
- **viewer**: All `view_*` and `view_any_*` permissions.
- **warehouse-manager**: No permissions (Phase 1).
- **field-technician**: No permissions (Phase 1).
- **finance-manager**: All `view_*` and `view_any_*` permissions (Phase 2 expansion planned).
- **purchasing-manager**: No permissions (Phase 1).
- **report-viewer**: All `view_any_*` permissions only.

### CurrencySeeder (Tenant DB)

Location: `/database/seeders/CurrencySeeder.php`

Creates 7 currencies for EU operations:

| Code | Name | Symbol | Default |
|------|------|--------|---------|
| EUR | Euro | € | ✓ |
| USD | US Dollar | $ | |
| GBP | British Pound | £ | |
| RON | Romanian Leu | lei | |
| CZK | Czech Koruna | Kč | |
| PLN | Polish Zloty | zł | |
| HUF | Hungarian Forint | Ft | |

All marked `is_active = true`.

### VatRateSeeder (Tenant DB)

Location: `/database/seeders/VatRateSeeder.php`

Creates Bulgarian VAT rates (country_code = BG):

| Rate | Type | Name | Default |
|------|------|------|---------|
| 20% | standard | Standard Rate | ✓ |
| 9% | reduced | Reduced Rate (Accommodation) | |
| 0% | zero | Zero Rate | |

All marked `is_active = true`. Can be synced with EU rates via `SyncEuVatRatesCommand`.

### SyncEuVatRatesCommand

Location: `/app/Console/Commands/SyncEuVatRatesCommand.php`

**Signature:** `hmo:sync-eu-vat-rates`

**Description:** Sync EU VAT rates from ibericode/vat-rates JSON.

**Source:** `https://raw.githubusercontent.com/ibericode/vat-rates/master/vat-rates.json`

**Behavior:**
1. Fetches JSON of all EU country VAT rates.
2. For each country, upserts standard rate and reduced rates.
3. Rate type: `standard` or `reduced`.
4. Skipped fields in JSON (e.g., super-reduced) are not synced.

**Usage:** `php artisan hmo:sync-eu-vat-rates` (manual, not scheduled).

---

## Summary Table: Business Logic Artifacts

| Artifact | Location | Purpose |
|----------|----------|---------|
| **VatCalculationService** | `/app/Services/VatCalculationService.php` | VAT math (net/gross) |
| **ViesValidationService** | `/app/Services/ViesValidationService.php` | EU VAT number validation + 24h cache |
| **PlanLimitService** | `/app/Services/PlanLimitService.php` | User/document limit enforcement |
| **TenantDeletionGuard** | `/app/Services/TenantDeletionGuard.php` | Lifecycle precondition checks |
| **TenantOnboardingService** | `/app/Services/TenantOnboardingService.php` | Tenant DB setup + seeders |
| **10 Policies** | `/app/Policies/*.php` | Resource authorization |
| **TenantStatus Enum** | `/app/Enums/TenantStatus.php` | Tenant lifecycle state machine |
| **SubscriptionStatus Enum** | `/app/Enums/SubscriptionStatus.php` | Subscription states + `isAccessible()` |
| **Plan Model** | `/app/Models/Plan.php` | Plan records (free/pro) |
| **Tenant Model** | `/app/Models/Tenant.php` | Extended with subscription + lifecycle methods |
| **EnsureActiveSubscription Middleware** | `/app/Http/Middleware/EnsureActiveSubscription.php` | Blocks inaccessible tenants |
| **CheckTrialExpirations** | `/app/Console/Commands/CheckTrialExpirations.php` | Daily trial expiry + warnings (06:00) |
| **CheckSubscriptionExpirations** | `/app/Console/Commands/CheckSubscriptionExpirations.php` | Daily subscription expiry (06:05) |
| **DeleteScheduledTenantsCommand** | `/app/Console/Commands/DeleteScheduledTenantsCommand.php` | Daily tenant deletion |
| **SyncEuVatRatesCommand** | `/app/Console/Commands/SyncEuVatRatesCommand.php` | Manual EU VAT rate sync |
| **RolesAndPermissionsSeeder** | `/database/seeders/RolesAndPermissionsSeeder.php` | 10 roles + 50 permissions per tenant |
| **PlanSeeder** | `/database/seeders/PlanSeeder.php` | 2 plans (Free, Professional) |
| **CurrencySeeder** | `/database/seeders/CurrencySeeder.php` | 7 EU currencies |
| **VatRateSeeder** | `/database/seeders/VatRateSeeder.php` | 3 BG VAT rates |
| **DatabaseSeeder** | `/database/seeders/DatabaseSeeder.php` | Central DB setup + demo tenant |
| **TenancyServiceProvider** | `/app/Providers/TenancyServiceProvider.php` | Lifecycle events + deletion guard |
| **AppServiceProvider** | `/app/Providers/AppServiceProvider.php` | `Gate::before()` super-admin bypass |
| **Scheduled Commands** | `/routes/console.php` | Daily schedule configuration |

---

## Related Files

- **Models:** `/app/Models/Tenant.php`, `/app/Models/Plan.php`, `/app/Models/User.php`
- **Enums:** `/app/Enums/TenantStatus.php`, `/app/Enums/SubscriptionStatus.php`, `/app/Enums/PricingMode.php`
- **Mail Views:** `/resources/views/mail/tenant/*.blade.php` (stubs exist for lifecycle, markdown for subscriptions)
- **Configuration:** `/config/tenancy.php` (multi-tenancy setup)
- **Tests:** `/tests/Feature/` and `/tests/Unit/` (RBAC, lifecycle, subscription tests)
