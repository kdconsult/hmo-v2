# Architecture: Foundation & Data Layer

HMO ERP system built on Laravel 13, Filament v5, and stancl/tenancy v3 for multi-tenant architecture. This document describes the foundation, configuration, database schema, models, and enums that power the system.

---

## 1. Multi-Tenancy Architecture

### stancl/tenancy Configuration

The application uses **stancl/tenancy v3** for complete multi-tenancy separation:

#### Central Database vs. Tenant Database
- **Central DB** (connection: `central`): Hosts `tenants`, `domains`, `users`, `tenant_user`, and `plans` tables
  - Single shared database for all landlord data and user accounts
  - Accessed via `CentralConnection` trait on `User` model
  
- **Tenant DB**: Separate database per tenant (named `tenant{tenant_id}`)
  - Hosts all business data: partners, contracts, currencies, VAT rates, documents, etc.
  - Created/destroyed dynamically via `PostgreSQLDatabaseManager`

#### Bootstrappers (Automatic Tenancy Initialization)
When a request enters a tenant context, stancl/tenancy runs:
1. **DatabaseTenancyBootstrapper**: Routes queries to tenant DB using `Tenant::current()`
2. **CacheTenancyBootstrapper**: Tags cache keys with `tenant_{tenant_id}` prefix
3. **FilesystemTenancyBootstrapper**: Suffixes storage paths (local, public) with tenant ID
4. **QueueTenancyBootstrapper**: Carries tenant context into queued jobs

#### Identification
- Middleware: Subdomain-based identification (`InitializeTenancyBySubdomain`) — extracts subdomain from hostname and looks it up in the `domains` table
- Central domains config (`central_domains`): localhost, 127.0.0.1, env `APP_DOMAIN` (e.g. `hmo.localhost`)
- Tenant lookup: `DomainTenantResolver` → `Domain::where('domain', $subdomain)->tenant()`

#### Database Manager
- PostgreSQL mode: Creates separate `tenant{id}` database per tenant
- Alternative: PostgreSQL schema mode (disabled; commented in config)
- Atomic transactions: Managed by `DatabaseTenancyBootstrapper` on each request

---

## 2. Central Database Schema

### Tenant Model (`App\Models\Tenant`)
Extends `Stancl\Tenancy\Database\Models\Tenant` with business columns.

**Columns:**
```
id (string, primary)
  name                    – Tenant company name
  slug                    – Unique slug for subdomain (generated via TenantSlugGenerator)
  email, phone            – Contact info
  address_line_1, city, postal_code, country_code – Address
  vat_number, eik, mol   – Bulgarian identifiers (VAT, EIK/BULSTAT, Materially Responsible Person)
  logo_path              – Branding image
  locale, timezone       – Localization (defaults: 'bg', 'Europe/Sofia')
  default_currency_code  – Default currency (default: 'BGN')
  
  [Subscription fields]
  plan_id (FK → plans.id, nullable)
  subscription_status    – Enum: Trial|Active|PastDue|Suspended|Cancelled (default: 'trial')
  trial_ends_at          – Trial expiry timestamp
  subscription_ends_at   – Paid subscription expiry
  
  [Lifecycle fields]
  status                 – Enum: Active|Suspended|MarkedForDeletion|ScheduledForDeletion (default: 'active')
  deactivated_at         – When suspended
  deactivated_by (FK → users.id, nullable) – Admin who suspended
  deactivation_reason    – 'non_payment'|'tenant_request'|'other'
  marked_for_deletion_at – When moved to MarkedForDeletion
  scheduled_for_deletion_at – When moved to ScheduledForDeletion
  deletion_scheduled_for – Timestamp of actual deletion (30 days post-schedule by default)
  
  [Standard timestamps]
  created_at, updated_at
  data (JSON)             – stancl/tenancy metadata
```

**Traits:**
- `HasDatabase`: Manages tenant database lifecycle (create, drop, migrate)
- `HasDomains`: Relationship to associated domains
- `HasFactory`: For testing

**Key Methods:**
- `generateUniqueSlug()`: Generates random "adjective-noun" slug via `TenantSlugGenerator` (with fallback: adjective-noun-{3-digit number})
- `suspend(User $by, string $reason = 'non_payment')`: Transition to Suspended (calls `assertCanTransitionTo()`)
- `markForDeletion()`: Transition to MarkedForDeletion (invoked at ~3 months unpaid)
- `scheduleForDeletion(?Carbon $deleteOn = null)`: Transition to ScheduledForDeletion (invoked at ~5 months unpaid; defaults to 30 days out)
- `reactivate()`: Clears all lifecycle fields, returns to Active (can be called from any state)
- `onTrial()`: Boolean check (subscription_status === Trial AND trial_ends_at is future)
- `hasActiveSubscription()`: Boolean check (subscription_status === Active)
- `isSubscriptionAccessible()`: Boolean (Trial or Active subscription allows app access)
- `isActive()`, `isSuspended()`, `isPendingDeletion()`: Status helpers

**Scopes:**
- `active()`, `suspended()`, `markedForDeletion()`, `scheduledForDeletion()`: Filter by status
- `dueForDeletion()`: Returns tenants where deletion_scheduled_for ≤ now()

**Relationships:**
- `domains()`: HasMany → Domain
- `users()`: BelongsToMany → User (via `tenant_user` pivot)
- `plan()`: BelongsTo → Plan
- `deactivatedBy()`: BelongsTo → User

---

### Domain Model (`App\Models\Domain`)
Extends `Stancl\Tenancy\Database\Models\Domain` (inherits stancl fields).

**Columns** (stancl base):
```
id (int, primary)
domain (string, unique) – Subdomain only (e.g., "acme") — NOT the full hostname
tenant_id (string, FK)  – References tenants.id
created_at, updated_at
```

**Relationships:**
- `tenant()`: BelongsTo → Tenant

**Purpose:** Routes incoming requests to correct tenant via domain matching.

---

### User Model (`App\Models\User`)
Central database user (Authenticatable, FilamentUser).

**Columns:**
```
id (bigint, primary)
name, email (unique), password
avatar_path             – Profile image
locale                  – User language preference
is_landlord             – Boolean; grants access to landlord panel
last_login_at           – Timestamp tracking
email_verified_at       – Nullable Laravel default
remember_token          – Laravel session default
created_at, updated_at
```

**Traits:**
- `CentralConnection`: Forces queries to central DB (never tenant-scoped)
- `HasFactory`
- `Notifiable`

**Key Methods:**
- `canAccessPanel(Panel $panel): bool`
  - **Landlord panel**: Requires `is_landlord === true`
  - **Admin panel** (tenant): Requires existence of `TenantUser` record in current tenant DB (wrapped in try/catch for safety)
  
- `hasPermissionTo(string|\BackedEnum $permission, ?string $guardName = null): bool`
  - Delegates to `TenantUser::where('user_id', $this->id)->first()?->hasPermissionTo(...)`
  - Bridges central User with tenant-scoped permissions via spatie/permission
  
- `hasRole(string|\BackedEnum|array $roles, ?string $guard = null): bool`
  - Delegates to TenantUser roles (via spatie/permission)

**Relationships:**
- `tenants()`: BelongsToMany → Tenant (via `tenant_user` pivot)

**Design Note:** Central `User` model has no direct roles/permissions. Authorization is delegated to the tenant-scoped `TenantUser` (which has `HasRoles` trait). This avoids querying the wrong database.

---

### Plan Model (`App\Models\Plan`)
Defines subscription tiers (central database).

**Columns:**
```
id (bigint, primary)
name, slug (unique)
price (decimal:2)        – Cost per billing period (0 = free)
billing_period           – 'monthly'|'yearly'|'lifetime'|null (null = free)
max_users                – Null = unlimited
max_documents            – Per month; null = unlimited
features (JSON)          – Feature flags (e.g., ['fiscal_printer', 'reports'])
is_active (boolean)      – Default: true
sort_order (int)         – Display order
created_at, updated_at
```

**Key Methods:**
- `isFree(): bool` – Returns true if price == 0.0

**Relationships:**
- `tenants()`: HasMany → Tenant

---

### Pivot Table: `tenant_user` (Central DB)

Joins tenants and users (many-to-many access control).

**Columns:**
```
id, tenant_id (FK), user_id (FK)
created_at, updated_at
unique: [tenant_id, user_id]
```

---

## 3. Tenant Database Schema

All tables below live in `tenant{tenant_id}` database. They are **tenant-scoped** and accessed automatically via `DatabaseTenancyBootstrapper`.

### TenantUser Model (`App\Models\TenantUser`)
Tenant-scoped user record (not Authenticatable; bridges central User to tenant roles).

**Columns:**
```
id (bigint)
user_id (bigint, indexed)      – FK to central users.id (no DB constraint; cross-DB)
display_name, job_title         – Tenant-specific metadata
phone
is_active (boolean)             – Default: true
settings (JSON)                 – Arbitrary user preferences
created_at, updated_at
deleted_at (soft delete)
```

**Traits:**
- `HasRoles` (spatie/permission): Enables role/permission checks
- `HasFactory`
- `SoftDeletes`

**Key Methods:**
- `centralUser(): ?User` – Returns the central User via `User::on('central')->find($user_id)`
- Inherits `hasPermissionTo()`, `hasRole()` from `HasRoles`

**Design Note:** No direct FK to users; uses `on('central')` to query across databases.

---

### Currency Model (`App\Models\Currency`)
Available currencies in this tenant.

**Columns:**
```
id
code (string:3, unique)  – ISO 4217 (e.g., 'BGN', 'USD', 'EUR')
name, symbol
decimal_places (int)     – Precision for calculations
is_default (boolean)     – One per tenant
is_active (boolean)
created_at, updated_at
```

**Relationships:**
- `exchangeRates()`: HasMany → ExchangeRate

---

### ExchangeRate Model (`App\Models\ExchangeRate`)
Historical exchange rate records.

**Columns:**
```
id
currency_id (FK → currencies.id)
base_currency_code (string:3) – Reference currency
rate (decimal:6)              – Conversion rate
source (string)               – Where rate came from (BCB, manual, etc.)
date (date)                   – Rate effective date
created_at, updated_at
```

**Relationships:**
- `currency()`: BelongsTo → Currency

---

### CompanySettings Model (`App\Models\CompanySettings`)
Key-value settings store per tenant.

**Columns:**
```
id
group (string)           – Setting category (e.g., 'fiscal', 'invoice')
key (string)             – Setting name (e.g., 'default_device')
value (text, nullable)   – JSON-encoded value
created_at, updated_at
unique: [group, key]
```

**Key Methods:**
- `static get(string $group, string $key, mixed $default = null): mixed`
- `static set(string $group, string $key, mixed $value): void`
- `static getGroup(string $group): array` – Returns all settings in a group as assoc array

---

### VatRate Model (`App\Models\VatRate`)
Tax rates (VAT, GST, etc.) per country.

**Columns:**
```
id
country_code (string:2, default: 'BG')
name (e.g., 'Standard', 'Reduced', 'Zero')
rate (decimal:5,2)                    – Percentage (e.g., 20.00)
type (string, default: 'standard')    – standard|reduced|super_reduced|zero|exempt
is_default (boolean)                  – Default for new documents
is_active (boolean)
sort_order (int)
effective_from, effective_to (date)   – Validity period
created_at, updated_at
deleted_at (soft delete)
index: [country_code, type]
```

**Scopes:**
- `active()`: Filter by is_active = true
- `forCountry(string $countryCode)`: Filter by country_code

---

### DocumentSeries Model (`App\Models\DocumentSeries`)
Numbering sequences for document generation (invoices, quotes, etc.).

**Columns:**
```
id
document_type (string)    – DocumentType enum value (invoice, quote, etc.)
name                      – Display name
prefix (string, nullable) – E.g., 'INV'
separator (string)        – Default: '-'
include_year (boolean)    – Add year to number? Default: true
year_format (string:4)    – Default: 'Y' (2026, 2027, ...)
padding (int)             – Zero-pad number width (default: 5)
next_number (int)         – Counter (default: 1)
reset_yearly (boolean)    – Reset counter on Jan 1
is_default (boolean)      – Default for document_type
is_active (boolean)
created_at, updated_at
deleted_at (soft delete)
index: [document_type, is_default]
```

**Key Methods:**
- `generateNumber(): string` – Atomically increments `next_number` and returns formatted number
  - Uses `DB::transaction()` + `lockForUpdate()` for race condition prevention
  - Format: `{prefix}{separator}{year}{separator}{padded_number}`
  - Example: 'INV-2026-00042'
  
- `static getDefault(DocumentType $type): ?self` – Returns active default series for type

**Design Note:** Database-level lock (`SELECT ... FOR UPDATE`) ensures no duplicate numbers across concurrent requests.

---

### Partner Model (`App\Models\Partner`)
Customers and suppliers (unified model for both).

**Columns:**
```
id
type (string)                      – PartnerType enum: Individual|Company
name                               – Display name
company_name (nullable)            – Legal entity name
eik (nullable)                     – Bulgarian EIK/BULSTAT
vat_number (nullable)              – VAT ID (EU)
mol (nullable)                     – Materially Responsible Person (Bulgaria)
email, phone, secondary_phone      – Contact
website (nullable)
is_customer, is_supplier (boolean) – Role flags
default_currency_code (nullable)   – Preferred currency
default_payment_term_days          – Invoice payment terms
default_payment_method             – PaymentMethod enum
default_vat_rate_id (FK)          – VAT applied to sales
credit_limit (decimal:15,2)        – For risk management
discount_percent (decimal:5,2)     – Default discount
notes (text)
is_active (boolean)
created_at, updated_at
deleted_at (soft delete)
index: [is_customer, is_supplier], [is_active]
```

**Traits:**
- `HasFactory`
- `LogsActivity` (spatie/laravel-activitylog): Logs name, email, phone, is_active changes
- `SoftDeletes`

**Key Methods:**
- `scopeActive($query)`: Filter by is_active = true

**Relationships:**
- `defaultVatRate()`: BelongsTo → VatRate
- `addresses()`: HasMany → PartnerAddress
- `contacts()`: HasMany → PartnerContact
- `bankAccounts()`: HasMany → PartnerBankAccount
- `contracts()`: HasMany → Contract
- `tags()`: MorphToMany → Tag (via `taggable` pivot)

**Design Note:** Single model for both customers/suppliers allows flexible querying: `Partner::where('is_customer', true)`, `Partner::where('is_supplier', true)`, or both.

---

### PartnerAddress Model (`App\Models\PartnerAddress`)
Multiple addresses per partner (billing, shipping, etc.).

**Columns:**
```
id
partner_id (FK)
label (e.g., 'Headquarters', 'Warehouse')
address_line_1, address_line_2, city, region, postal_code, country_code
is_billing, is_shipping, is_default (boolean)
created_at, updated_at
```

**Relationships:**
- `partner()`: BelongsTo → Partner

---

### PartnerContact Model (`App\Models\PartnerContact`)
Named contacts within a partner.

**Columns:**
```
id
partner_id (FK)
name, position, email, phone
is_primary (boolean)
notes (text)
created_at, updated_at
```

**Relationships:**
- `partner()`: BelongsTo → Partner

---

### PartnerBankAccount Model (`App\Models\PartnerBankAccount`)
Bank details for payments.

**Columns:**
```
id
partner_id (FK)
bank_name, account_number, iban, swift_code
currency_code (nullable)
is_default (boolean)
created_at, updated_at
```

**Relationships:**
- `partner()`: BelongsTo → Partner

---

### Contract Model (`App\Models\Contract`)
Service/maintenance agreements with partners.

**Columns:**
```
id
contract_number (string)           – Generated via DocumentSeries
document_series_id (FK)            – References series for numbering
partner_id (FK)
status (string)                    – ContractStatus enum
type (string)                      – Contract type (TBD in phase 2)
start_date, end_date (date)
auto_renew (boolean)               – Auto-extend on expiry
monthly_fee (decimal:2)            – Recurring charge
currency_code (string:3)
included_hours (decimal:2)         – Service hours budget
included_materials_budget (decimal:2) – Materials budget
used_hours (decimal:2)             – Hours consumed (tracking)
used_materials (decimal:2)         – Materials consumed
billing_day (int)                  – Day of month to invoice
notes (text)
created_by (FK → users.id)         – Creator (central user, tracked at creation)
created_at, updated_at
deleted_at (soft delete)
```

**Traits:**
- `HasFactory`
- `LogsActivity`: Logs status, contract_number, partner_id changes
- `SoftDeletes`

**Relationships:**
- `partner()`: BelongsTo → Partner
- `documentSeries()`: BelongsTo → DocumentSeries

**Design Note:** `created_by` stores central user ID (not FK constraint) for audit trail.

---

### Tag Model (`App\Models\Tag`)
Tagging system for partners and other entities (morphable).

**Columns:**
```
id
name (string, unique)
color (string, nullable)  – CSS color for UI
created_at, updated_at
```

**Relationships:**
- `partners()`: MorphedByMany → Partner (via `taggable` pivot)

---

### Taggable Pivot Table (Tenant DB)

Polymorphic many-to-many for tags.

**Columns:**
```
id
tag_id (FK → tags.id)
taggable_id (bigint)          – Partner.id
taggable_type (string)        – 'App\Models\Partner'
created_at
```

---

## 4. Enums (30 Total)

All enums located in `app/Enums/` directory. Most implement Filament contracts for automatic label/color/icon rendering in tables and forms.

### Status Lifecycle Enums

#### TenantStatus (HasColor, HasIcon, HasLabel, canTransitionTo)
Tenant account lifecycle.

**Cases:**
- `Active` (success) – Operational, subscription accessible
- `Suspended` (warning) – Non-payment or admin action (no app access)
- `MarkedForDeletion` (danger) – Grace period before deletion (~3 months unpaid)
- `ScheduledForDeletion` (danger) – Queued for auto-delete (30 days before execution)

**Transition Rules** (via `canTransitionTo(self $target): bool`):
- Active → {Suspended, ScheduledForDeletion}
- Suspended → {Active, MarkedForDeletion}
- MarkedForDeletion → {Active, ScheduledForDeletion}
- ScheduledForDeletion → {Active}

**Icons:** CheckCircle, Pause, ExclamationTriangle, XCircle

---

#### SubscriptionStatus (HasColor, HasIcon, HasLabel, isAccessible)
Payment/subscription state.

**Cases:**
- `Trial` (info) – Free trial period
- `Active` (success) – Paid subscription (billing current)
- `PastDue` (warning) – Invoice unpaid; access remains
- `Suspended` (danger) – Subscription halted (no app access)
- `Cancelled` (gray) – Subscription terminated

**Key Method:**
- `isAccessible(): bool` – Returns true for Trial or Active only (gates feature access)

**Icons:** Clock, CheckCircle, ExclamationCircle, Pause, XCircle

---

#### ContractStatus (HasColor, HasIcon, HasLabel)
Service agreement state.

**Cases:**
- `Draft` (gray) – Not yet signed
- `Active` (success) – In effect
- `Suspended` (warning) – Temporarily paused
- `Expired` (danger) – End date passed
- `Cancelled` (gray) – Terminated early

**Icons:** Pencil, CheckCircle, Pause, ExclamationTriangle, XCircle

---

#### DocumentStatus (HasColor, HasIcon, HasLabel)
Invoice/order lifecycle.

**Cases:**
- `Draft` (gray) – Work-in-progress
- `Confirmed` (info) – Finalized, sent to customer
- `Sent` (primary) – Delivery confirmed
- `PartiallyPaid` (warning) – Installment payment
- `Paid` (success) – Settled in full
- `Overdue` (danger) – Past due date
- `Cancelled` (gray) – Voided

**Icons:** Pencil, CheckCircle, PaperAirplane, Banknotes, CheckBadge, ExclamationCircle, XCircle

---

### Document & Order Status Enums

#### DocumentType (HasLabel)
Types of documents that generate numbers via DocumentSeries.

**Cases:**
- Quote, SalesOrder, Invoice, CreditNote, DebitNote
- ProformaInvoice, DeliveryNote
- PurchaseOrder, SupplierInvoice, SupplierCreditNote, GoodsReceivedNote
- InternalConsumptionNote

---

#### OrderStatus (HasColor, HasIcon, HasLabel)
Sales order fulfillment state.

**Cases:**
- `Draft` (gray) – Not confirmed
- `Confirmed` (info) – Customer accepted
- `InProgress` (primary) – Being fulfilled
- `PartiallyFulfilled` (warning) – Partial shipment
- `Fulfilled` (success) – Complete
- `Cancelled` (gray) – Aborted

---

#### QuoteStatus (HasColor, HasIcon, HasLabel)
Quote lifecycle.

**Cases:**
- `Draft`, `Sent`, `Accepted`, `Rejected`, `Expired`, `Converted`

---

#### PurchaseOrderStatus (HasColor, HasIcon, HasLabel)
Inbound purchase request state.

**Cases:**
- `Draft`, `Sent`, `Confirmed`
- `PartiallyReceived` (warning), `Received` (success)
- `Cancelled` (gray)

---

#### TransferStatus (HasColor, HasIcon, HasLabel)
Inventory transfer between locations.

**Cases:**
- `Draft`, `InTransit`, `PartiallyReceived`, `Received`, `Cancelled`

---

### Financial & Accounting Enums

#### PaymentMethod (HasIcon, HasLabel)
Payment modes.

**Cases:**
- `Cash` (Banknotes icon)
- `BankTransfer` (BuildingLibrary icon)
- `Card`, `DirectDebit`

---

#### PaymentDirection (HasLabel)
Cash flow direction.

**Cases:**
- `Incoming` – Receivable/deposit
- `Outgoing` – Payable/withdrawal

---

#### BankTransactionType (HasLabel)
Bank statement entry type.

**Cases:**
- `Credit` – Deposit
- `Debit` – Withdrawal

---

#### BankImportSource (HasLabel)
Source of bank data.

**Cases:**
- `Csv` – CSV upload
- `Camt053` – CAMT.053 XML (ISO 20022)
- `Api` – Bank API
- `Manual` – User entry

---

#### ReconciliationStatus (HasColor, HasLabel)
Bank reconciliation state.

**Cases:**
- `Unmatched` (danger) – No matching invoice/check
- `Matched` (success) – Reconciled
- `PartiallyMatched` (warning) – Partial match
- `Ignored` (gray) – User-ignored

---

#### InstallmentStatus (HasColor, HasLabel)
Partial payment tracking.

**Cases:**
- `Pending` (gray), `PartiallyPaid` (warning), `Paid` (success), `Overdue` (danger)

---

#### CreditNoteReason (HasLabel)
Why a credit note was issued.

**Cases:**
- `Return`, `Discount`, `Error`, `Damaged`, `Other`

---

#### DebitNoteReason (HasLabel)
Why a debit note was issued.

**Cases:**
- `PriceIncrease`, `AdditionalCharge`, `Error`, `Other`

---

#### FiscalReceiptStatus (HasColor, HasLabel)
Fiscal printer receipt state.

**Cases:**
- `Pending` (warning) – Queued for print
- `Printed` (success) – Printed by fiscal device
- `Failed` (danger) – Print failure
- `Annulled` (gray) – Voided receipt

---

### Inventory & Warehouse Enums

#### NomenclatureType (HasColor, HasLabel)
Item classification.

**Cases:**
- `Stock` (primary) – Physical inventory
- `Service` (success) – Non-stocked service
- `Virtual` (info) – Digital/abstract
- `Bundle` (secondary) – Kit of items

---

#### MovementType (HasColor, HasLabel)
Inventory transaction reason.

**Cases:**
- `Purchase`, `Sale`, `TransferOut`, `TransferIn`, `Adjustment`
- `Return`, `InternalConsumption`, `Production`, `InitialStock`

**Design:** Allows tracking all inbound/outbound flows.

---

#### TrackingType (HasLabel)
Item traceability method.

**Cases:**
- `None` – Bulk stock
- `Serial` – Unique serial number per unit
- `Batch` – Batch/lot tracking

---

#### InventoryCountStatus (HasColor, HasLabel)
Physical count workflow.

**Cases:**
- `Draft` (gray) – Planning phase
- `InProgress` (primary) – Counting underway
- `Completed` (warning) – Count finished, variance calculated
- `Approved` (success) – Variance approved, stock adjusted

---

#### CountType (HasLabel)
Count method.

**Cases:**
- `Full` – Full physical count of all items
- `Cycle` – Rolling/sample count

---

### Field Service & Operations Enums

#### JobSheetStatus (HasColor, HasIcon, HasLabel)
Service job lifecycle.

**Cases:**
- `Draft` (gray), `Scheduled` (primary), `InProgress` (primary)
- `OnHold` (warning), `Completed` (success), `Invoiced` (success)

---

#### TimeEntryType (HasLabel)
How time was recorded.

**Cases:**
- `Manual` – User entered hours
- `Timer` – Automated timer tracking

---

### Configuration & Settings Enums

#### NavigationGroup (HasIcon, HasLabel)
Filament admin panel sections (used in resource `$navigationGroup` property).

**Cases:**
- Dashboard (Home), CRM (Users), Catalog (Cube), Sales (ShoppingCart)
- Purchases (InboxArrowDown), Warehouse (BuildingStorefront)
- FieldService (WrenchScrewdriver), Finance (Banknotes)
- Fiscal (ShieldCheck), Reports (ChartBarSquare), Settings (Cog6Tooth)

**Icons:** All use Heroicon outlines (e.g., `Heroicon::OutlinedHome`)

**Design Note:** Child resources use this as `$navigationGroup` string (not enum in Filament context due to type quirks); resource declarations instantiate the enum value string.

---

#### PricingMode (HasLabel)
Pricing display method.

**Cases:**
- `VatExclusive` – Show price before tax
- `VatInclusive` – Show price with tax included

---

#### KpiPeriod (HasLabel)
Reporting aggregation period.

**Cases:**
- Daily, Weekly, Monthly, Quarterly, Yearly

---

#### PartnerType (HasColor, HasLabel)
Partner entity classification.

**Cases:**
- `Individual` (info) – Person
- `Company` (primary) – Organization

---

#### CashRegisterShiftStatus (HasColor, HasLabel)
POS terminal shift state.

**Cases:**
- `Open` (success) – Accepting transactions
- `Closed` (gray) – Shift ended, reconciled

---

---

## 5. Key Design Decisions

### 5.1 Central DB + Tenant DB Separation

**Why:** Multi-tenancy isolation and scalability
- Users and plans live centrally (one user can access multiple tenants)
- Each tenant's data isolated in separate DB (schema isolation, simpler backups, per-tenant resource control)
- `Tenant::current()` automatically routes queries via `DatabaseTenancyBootstrapper`

**Trade-off:** Cross-database relationships handled carefully:
- `TenantUser::centralUser()` explicitly queries `User::on('central')`
- `tenant_user` pivot has no FK to users (prevents cross-DB constraint issues)
- Queries must specify connection when needed (`->on('central')`)

---

### 5.2 CentralConnection Trait on User

**Why:** Prevent accidental tenant-scoped queries on central User model
- `User` extends Laravel's `Authenticatable` (central auth model)
- Without `CentralConnection`, queries would inherit tenant context and fail
- Trait forces `on('central')` for all User queries

---

### 5.3 TenantUser as Permission Bridge

**Why:** Avoid adding `HasRoles` to central User (would query wrong DB)
- Permissions live in tenant DB (via `TenantUser::HasRoles`)
- Central `User::hasPermissionTo()` delegates to TenantUser
- Filament policies call `hasPermissionTo()` on auth user; delegation makes this work without code changes

---

### 5.4 DocumentSeries with DB-Level Lock

**Why:** Prevent duplicate document numbers in concurrent requests
- `generateNumber()` uses `DB::transaction()` + `lockForUpdate()` (SELECT FOR UPDATE)
- Ensures atomic increment: lock → read next_number → increment → unlock
- No race conditions; safe for high-concurrency scenarios (e.g., POS terminal)

---

### 5.5 Tenant Lifecycle: Multi-Step Deactivation

**Why:** Grace period for unpaid tenants before data deletion
- **Active → Suspended:** Admin action or non-payment (tenant cannot access app)
- **Suspended → MarkedForDeletion:** ~3 months unpaid (warning signal)
- **MarkedForDeletion → ScheduledForDeletion:** ~5 months unpaid (final notice, sets deletion_scheduled_for)
- **ScheduledForDeletion:** Queued for automated deletion job at specified timestamp

**Benefits:**
- Data retention for legal/tax compliance (~5 months grace)
- Chance to reactivate at any stage (via `reactivate()` method)
- Clear audit trail: `deactivated_at`, `deactivated_by`, `deactivation_reason`
- Automated cleanup via scheduled command (future implementation)

---

### 5.6 Morphable Tags (Polymorphic)

**Why:** Reusable tagging across multiple models
- `Tag` uses `MorphToMany` relation (via `taggable` pivot table)
- Currently used on Partner; can extend to other entities
- Single tag table for all entity types; reduces duplication

---

### 5.7 Partner as Unified Customer+Supplier Model

**Why:** Flexibility and reduced duplication
- Many businesses are both customers (buying) and suppliers (selling)
- Single model with `is_customer` and `is_supplier` flags
- Queries naturally filter: `Partner::where('is_customer', true)`
- Shared attributes (addresses, contacts, bank accounts) reused

---

### 5.8 CompanySettings as Key-Value Store

**Why:** Flexible per-tenant configuration without migrations
- No schema changes needed for new settings
- Group/key structure allows logical grouping (e.g., 'fiscal', 'invoice')
- Static helpers (`get()`, `set()`, `getGroup()`) for ergonomic access
- Values JSON-stored (can be complex objects)

**Use Cases:**
- Fiscal printer device settings
- Default invoice templates
- Feature toggles
- Workflow preferences

---

### 5.9 Cache Migration Before Permission Tables

**Why:** Prevent "table not found" errors during Laravel feature operations
- `cache` table (from `cache_table` migration) must exist before `spatie/permission` tables
- Laravel's caching system (used by Filament, sessions, etc.) assumes cache table exists
- Migration order in `database/migrations/tenant/`: cache (2026_04_08_072630) → permissions (2026_04_08_072639)

---

### 5.10 Soft Deletes on Key Models

**Why:** Audit trail and recovery
- `Partner`, `PartnerAddress`, `PartnerContact`, `PartnerBankAccount` (CRM entities)
- `Contract`, `DocumentSeries`, `VatRate` (Business-critical data)
- `TenantUser` (User lifecycle tracking)

**Policy:** Soft-deleted records excluded from normal queries unless explicitly included (`.withTrashed()`).

---

### 5.11 Spatie Activity Log on Partner and Contract

**Why:** Compliance and audit trail
- Logs changes to key customer/partner fields
- Configured via `getActivitylogOptions()`: only logs specific columns + dirty fields
- Partner: logs `name`, `email`, `phone`, `is_active`
- Contract: logs `status`, `contract_number`, `partner_id`

---

### 5.12 Enum Casting in Models

**Why:** Type safety and Filament integration
- All status/type columns cast to enums (e.g., `'status' => TenantStatus::class`)
- Enums implement Filament contracts (HasLabel, HasColor, HasIcon)
- Automatic rendering in tables/forms without custom logic
- Example: `$tenant->status->getColor()` returns 'success', 'warning', etc. for badges

---

### 5.13 PostgreSQL as Primary Database

**Why:** Advanced features, performance, compliance
- Stancl/tenancy configured for PostgreSQL (via `PostgreSQLDatabaseManager`)
- Supports concurrent tenant databases with proper isolation
- JSON columns (used in Plan features, CompanySettings value, TenantUser settings)
- ACID transactions with `FOR UPDATE` locking (DocumentSeries.generateNumber)
- Better for multi-tenant at scale

---

## 6. Summary Table

| Entity | Scope | Purpose | Key Trait |
|--------|-------|---------|-----------|
| Tenant | Central | Account & subscription | HasDatabase, HasDomains |
| Domain | Central | Subdomain routing | – |
| User | Central | Authentication | CentralConnection |
| Plan | Central | Subscription tiers | – |
| TenantUser | Tenant | Roles & permissions | HasRoles |
| Currency | Tenant | Exchange tracking | – |
| CompanySettings | Tenant | Key-value config | – |
| VatRate | Tenant | Tax rates | – |
| DocumentSeries | Tenant | Number generation | – |
| Partner | Tenant | Customers/Suppliers | LogsActivity, SoftDeletes |
| Contract | Tenant | Service agreements | LogsActivity, SoftDeletes |
| Tag | Tenant | Labeling system | – |

---

## 7. Next Phases (Out of Scope)

**Phase 2:** Inventory, Warehouse, CRM advanced features
**Phase 3:** Sales orders, Purchasing, Financial modules
**Phase 4:** Fiscal compliance, Reporting
**Phase 5:** Field Service, Time tracking

This foundation supports all planned phases via extensible enum, model, and schema patterns.
