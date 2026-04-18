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

## 3b. Tenant DB — Phase 2: Catalog & Warehouse Models

### Category Model (`App\Models\Category`)
Hierarchical product category tree. Max 3 levels enforced in `boot()`.

**Columns:** `id`, `parent_id (FK nullable→categories, nullOnDelete)`, `name (JSON translatable)`, `description (JSON translatable)`, `sort_order`, `is_active`, `created_at`, `updated_at`, `deleted_at`

**Key constraint:** `parent_id` self-referential FK; boot prevents depth > 3 (checks `parent->parent_id`).

**Traits:** HasFactory, SoftDeletes, Translatable (name, description), LogsActivity

**Relationships:** `parent()` BelongsTo, `children()` HasMany, `products()` HasMany

---

### Unit Model (`App\Models\Unit`)
Units of measure for product quantities.

**Columns:** `id`, `name (JSON translatable)`, `symbol`, `type (UnitType enum)`, `is_default`, `is_active`, `created_at`, `updated_at`

**Traits:** HasFactory, Translatable (name)

**Seeded:** 13 standard units at tenant onboarding — pcs, kg, g, t, l, ml, m, cm, mm, m², h, day, month.

---

### Product Model (`App\Models\Product`)
Goods and services. Always creates a default hidden `ProductVariant` on the `created` event.

**Columns:** `id`, `category_id (FK nullable)`, `unit_id (FK nullable)`, `name (JSON translatable)`, `description (JSON translatable)`, `type (ProductType enum: Stock/Service/Bundle)`, `status (ProductStatus enum: Draft/Active/Discontinued)`, `sku`, `barcode`, `purchase_price (decimal 15,4)`, `sale_price (decimal 15,4)`, `is_stockable (boolean)`, `notes`, `created_at`, `updated_at`, `deleted_at`

**Traits:** HasFactory, SoftDeletes, Translatable (name, description), LogsActivity

**Boot event:** `created` → creates one `ProductVariant` with `is_default=true`, `is_visible=false`, copying SKU and prices.

**Relationships:** `category()`, `unit()`, `variants()` HasMany, `defaultVariant()` HasOne where `is_default=true`

---

### ProductVariant Model (`App\Models\ProductVariant`)
Named variant of a product (size, color, etc.). All stock tracked at this level.

**Columns:** `id`, `product_id (FK cascade)`, `name (JSON translatable, nullable)`, `sku`, `barcode`, `purchase_price (decimal 15,4)`, `sale_price (decimal 15,4)`, `is_default`, `is_visible`, `sort_order`, `created_at`, `updated_at`, `deleted_at`

**Fallback:** If variant `purchase_price`/`sale_price` is null, UI falls back to parent product price.

**Relationships:** `product()`, `stockItems()` HasMany → StockItem

---

### Warehouse Model (`App\Models\Warehouse`)
Physical stock location. Single `is_default` enforced in `boot()`.

**Columns:** `id`, `code (unique)`, `name`, `address (JSON)`, `is_default`, `is_active`, `created_at`, `updated_at`, `deleted_at`

**Boot:** Clearing `is_default` on save checks no other warehouse is already default.

**Relationships:** `stockLocations()` HasMany, `stockItems()` HasMany

---

### StockLocation Model (`App\Models\StockLocation`)
Bin/shelf within a warehouse.

**Columns:** `id`, `warehouse_id (FK cascade)`, `code`, `name`, `is_active`, `created_at`, `updated_at`, `deleted_at`

**Relationships:** `warehouse()`, `stockItems()` HasMany

---

### StockItem Model (`App\Models\StockItem`)
Current stock level per variant per warehouse (+ optional location).

**Columns:** `id`, `product_variant_id (FK restrict)`, `warehouse_id (FK restrict)`, `stock_location_id (FK nullable, nullOnDelete)`, `quantity (decimal 15,4)`, `reserved_quantity (decimal 15,4)`, `created_at`, `updated_at`

**Computed:** `available_quantity = quantity - reserved_quantity`

**Constraint:** Unique on `(product_variant_id, warehouse_id, stock_location_id)`.

**Design:** Only `StockService` methods write to this table. Direct writes are discouraged.

---

### StockMovement Model (`App\Models\StockMovement`)
Immutable audit log of every stock change.

**Columns:** `id`, `product_variant_id (FK)`, `warehouse_id (FK)`, `stock_location_id (FK nullable)`, `type (MovementType enum)`, `quantity (decimal 15,4, signed)`, `reference_type (string nullable)`, `reference_id (bigint nullable)`, `notes`, `moved_by (FK nullable → users.id, no DB constraint)`, `created_at`

**Immutability:** `boot()` throws `RuntimeException` on `updating` and `deleting` events.

**Morph reference:** `reference_type` / `reference_id` link the movement to its source document (e.g., `goods_received_note` → GoodsReceivedNote). Resolved via morph map in `AppServiceProvider`.

**Design:** No `updated_at` column — movements are append-only.

---

## 3c. Tenant DB — Phase 3.1: Purchases Models

### PurchaseOrder Model (`App\Models\PurchaseOrder`)
Order sent to a supplier. Status pipeline: Draft → Sent → Confirmed → PartiallyReceived → Received (Cancelled exit at any stage except terminal states).

**Columns:** `id`, `po_number (unique)`, `partner_id (FK restrict→partners)`, `warehouse_id (FK nullOnDelete, nullable)`, `document_series_id (FK nullOnDelete, nullable)`, `status (PurchaseOrderStatus enum)`, `currency_code`, `exchange_rate (decimal 16,6)`, `pricing_mode (PricingMode enum)`, `subtotal (decimal 15,2)`, `discount_amount (decimal 15,2)`, `tax_amount (decimal 15,2)`, `total (decimal 15,2)`, `expected_delivery_date (date nullable)`, `ordered_at (date)`, `notes`, `internal_notes`, `created_by (bigint nullable)`, `created_at`, `updated_at`, `deleted_at`

**Key methods:** `isEditable(): bool` (Draft/Sent only), `isFullyReceived(): bool`, `recalculateTotals(): void`

**Traits:** HasFactory, SoftDeletes, LogsActivity

---

### PurchaseOrderItem Model (`App\Models\PurchaseOrderItem`)
Line item on a purchase order. Tracks received quantities.

**Columns:** `id`, `purchase_order_id (FK cascade)`, `product_variant_id (FK restrict)`, `description`, `quantity (decimal 15,4)`, `quantity_received (decimal 15,4, default 0)`, `unit_price (decimal 15,4)`, `discount_percent (decimal 5,2)`, `discount_amount (decimal 15,2)`, `vat_rate_id (FK)`, `vat_amount (decimal 15,2)`, `line_total (decimal 15,2)`, `line_total_with_vat (decimal 15,2)`, `sort_order`, `created_at`, `updated_at`

**Key methods:** `remainingQuantity(): string` (quantity − quantity_received), `isFullyReceived(): bool`

---

### GoodsReceivedNote Model (`App\Models\GoodsReceivedNote`)
Physical receipt of goods into a warehouse. Confirming triggers `StockService::receive()` for each line.

**Columns:** `id`, `grn_number (unique)`, `purchase_order_id (FK nullOnDelete, nullable)`, `partner_id (FK restrict)`, `warehouse_id (FK restrict — required)`, `document_series_id (FK nullOnDelete, nullable)`, `status (GoodsReceivedNoteStatus enum: Draft/Confirmed/Cancelled)`, `received_at (date nullable)`, `notes`, `created_by (bigint nullable)`, `created_at`, `updated_at`, `deleted_at`

**Key methods:** `isEditable(): bool` (Draft only), `isConfirmed(): bool`

**Morph alias:** `goods_received_note` (registered in AppServiceProvider morph map)

**Traits:** HasFactory, SoftDeletes, LogsActivity

---

### GoodsReceivedNoteItem Model (`App\Models\GoodsReceivedNoteItem`)
Line on a GRN. Optionally links back to the PO item it fulfils.

**Columns:** `id`, `goods_received_note_id (FK cascade)`, `purchase_order_item_id (FK nullOnDelete, nullable)`, `product_variant_id (FK restrict)`, `quantity (decimal 15,4)`, `unit_cost (decimal 15,4)`, `created_at`, `updated_at`

---

### SupplierInvoice Model (`App\Models\SupplierInvoice`)
Billing document received from a supplier. `internal_number` is auto-generated from `NumberSeries` at creation.

**Columns:** `id`, `supplier_invoice_number (supplier's own reference)`, `internal_number (unique)`, `document_series_id (FK nullable)`, `purchase_order_id (FK nullOnDelete, nullable)`, `partner_id (FK restrict)`, `status (DocumentStatus)`, `currency_code`, `exchange_rate (decimal 16,6)`, `pricing_mode (PricingMode)`, `subtotal`, `discount_amount`, `tax_amount`, `total`, `amount_paid`, `amount_due (decimal 15,2)`, `issued_at (date)`, `received_at (date nullable)`, `due_date (date nullable)`, `payment_method (nullable)`, `notes`, `internal_notes`, `created_by`, `created_at`, `updated_at`, `deleted_at`

**Constraint:** Composite unique on `(partner_id, supplier_invoice_number)` — same supplier can't have duplicate invoice numbers.

**Key methods:** `isEditable(): bool` (Draft only), `isOverdue(): bool` (due_date < today AND amount_due > 0), `recalculateTotals(): void`

**Traits:** HasFactory, SoftDeletes, LogsActivity

---

### SupplierInvoiceItem Model (`App\Models\SupplierInvoiceItem`)
Line on a supplier invoice. `product_variant_id` is nullable — free-text lines are allowed.

**Columns:** `id`, `supplier_invoice_id (FK cascade)`, `purchase_order_item_id (FK nullOnDelete, nullable)`, `product_variant_id (FK nullOnDelete, nullable)`, `description (not null)`, `quantity (decimal 15,4)`, `unit_price (decimal 15,4)`, `discount_percent`, `discount_amount`, `vat_rate_id (FK)`, `vat_amount`, `line_total`, `line_total_with_vat`, `sort_order`, `created_at`, `updated_at`

**Key methods:** `creditedQuantity(): string` — sum of all credit note items (Draft + Confirmed); `remainingCreditableQuantity(): string` — quantity − creditedQuantity

---

### SupplierCreditNote Model (`App\Models\SupplierCreditNote`)
Partial or full credit issued against a confirmed supplier invoice.

**Columns:** `id`, `credit_note_number (unique)`, `supplier_invoice_id (FK restrict)`, `partner_id (FK restrict)`, `document_series_id (FK nullable)`, `status (DocumentStatus)`, `reason (CreditNoteReason enum)`, `reason_description (nullable)`, `currency_code`, `exchange_rate`, `subtotal`, `tax_amount`, `total`, `issued_at (date)`, `notes`, `created_by`, `created_at`, `updated_at`, `deleted_at`

**Key methods:** `isEditable(): bool` (Draft only), `recalculateTotals(): void`

**Traits:** HasFactory, SoftDeletes, LogsActivity

---

### SupplierCreditNoteItem Model (`App\Models\SupplierCreditNoteItem`)
Line on a supplier credit note. Quantity validated against `SupplierInvoiceItem::remainingCreditableQuantity()` using `lockForUpdate()`.

**Columns:** `id`, `supplier_credit_note_id (FK cascade)`, `supplier_invoice_item_id (FK restrict)`, `product_variant_id (FK nullOnDelete, nullable)`, `description (not null)`, `quantity (decimal 15,4)`, `unit_price (decimal 15,4)`, `vat_rate_id (FK)`, `vat_amount`, `line_total`, `line_total_with_vat`, `created_at`, `updated_at`

---

### Morph Map (AppServiceProvider)

All polymorphic `reference_type` / `reference_id` columns resolve aliases via:

```php
Relation::morphMap([
    'product'                => Product::class,
    'product_variant'        => ProductVariant::class,
    'warehouse'              => Warehouse::class,
    'stock_movement'         => StockMovement::class,
    'purchase_order'         => PurchaseOrder::class,
    'goods_received_note'    => GoodsReceivedNote::class,
    'supplier_invoice'       => SupplierInvoice::class,
    'supplier_credit_note'   => SupplierCreditNote::class,
    'purchase_return'        => PurchaseReturn::class,
    // Phase 3.2 — outbound sales pipeline
    'quotation'              => Quotation::class,
    'sales_order'            => SalesOrder::class,
    'delivery_note'          => DeliveryNote::class,
    'customer_invoice'       => CustomerInvoice::class,
    'customer_credit_note'   => CustomerCreditNote::class,
    'customer_debit_note'    => CustomerDebitNote::class,
    'sales_return'           => SalesReturn::class,
    'advance_payment'        => AdvancePayment::class,
]);
```

**Why:** Stored string aliases decouple the DB from PHP class names. Renames don't break existing rows.

---

## 3d. Tenant DB — Phase 3.2: Sales / Invoicing Models

Phase 3.2 implements the full outbound sales pipeline: Quotation → SalesOrder → DeliveryNote → CustomerInvoice → CreditNote/DebitNote → SalesReturn → AdvancePayment. Mirrors Phase 3.1 structure on the sales side.

### Quotation Model (`App\Models\Quotation`)
Commercial offer sent to a customer. Status pipeline: Draft → Sent → Accepted / Rejected / Expired / Cancelled.

**Columns:** `id`, `quotation_number (unique)`, `document_series_id (nullable)`, `partner_id (FK restrict)`, `status (QuotationStatus)`, `currency_code`, `exchange_rate (decimal 16,6)`, `pricing_mode`, `subtotal`, `discount_amount`, `tax_amount`, `total (all decimal 15,2)`, `valid_until (date nullable)`, `issued_at (date nullable)`, `notes`, `internal_notes`, `created_by`, `created_at`, `updated_at`, `deleted_at`

**Key methods:** `isEditable(): bool` (Draft only), `isExpired(): bool` (valid_until set and in the past), `recalculateTotals(): void`

**Traits:** HasFactory, SoftDeletes, LogsActivity

---

### QuotationItem Model (`App\Models\QuotationItem`)
Line item on a quotation. No tracking columns.

**Columns:** `id`, `quotation_id (FK cascade)`, `product_variant_id (FK restrict)`, `description (nullable)`, `quantity (decimal 15,4)`, `unit_price (decimal 15,4)`, `discount_percent`, `discount_amount`, `vat_rate_id (FK)`, `vat_amount`, `line_total`, `line_total_with_vat`, `sort_order`, `created_at`, `updated_at`

---

### SalesOrder Model (`App\Models\SalesOrder`)
Confirmed customer order. Status pipeline: Draft → Confirmed → PartiallyDelivered → Delivered → Invoiced (Cancelled exit at any non-terminal stage).

**Columns:** `id`, `so_number (unique)`, `document_series_id (nullable)`, `partner_id (FK restrict)`, `quotation_id (FK nullOnDelete, nullable)`, `warehouse_id (FK restrict — NOT nullable)`, `status (SalesOrderStatus)`, `currency_code`, `exchange_rate`, `pricing_mode`, `subtotal`, `discount_amount`, `tax_amount`, `total`, `expected_delivery_date (date nullable)`, `issued_at (date nullable)`, `notes`, `internal_notes`, `created_by`, `created_at`, `updated_at`, `deleted_at`

**Key methods:** `isEditable(): bool` (Draft only), `isFullyDelivered(): bool`, `isFullyInvoiced(): bool`

**Traits:** HasFactory, SoftDeletes, LogsActivity

---

### SalesOrderItem Model (`App\Models\SalesOrderItem`)
Line item on a sales order. Tracks two quantities: delivered and invoiced.

**Columns:** `id`, `sales_order_id (FK cascade)`, `quotation_item_id (FK nullOnDelete, nullable)`, `product_variant_id (FK restrict)`, `description (nullable)`, `quantity (decimal 15,4)`, `qty_delivered (decimal 15,4, default 0)`, `qty_invoiced (decimal 15,4, default 0)`, `unit_price (decimal 15,4)`, `discount_percent`, `discount_amount`, `vat_rate_id (FK)`, `vat_amount`, `line_total`, `line_total_with_vat`, `sort_order`, `created_at`, `updated_at`

**Key methods:** `remainingDeliverableQuantity(): string`, `remainingInvoiceableQuantity(): string`, `isFullyDelivered(): bool`, `isFullyInvoiced(): bool`

---

### DeliveryNote Model (`App\Models\DeliveryNote`)
Physical dispatch of goods from the warehouse to a customer. Confirming calls `StockService::issueReserved()` for each stock-type line.

**Columns:** `id`, `dn_number (unique)`, `document_series_id (nullable)`, `sales_order_id (FK nullOnDelete, nullable)`, `partner_id (FK restrict)`, `warehouse_id (FK restrict)`, `status (DeliveryNoteStatus: Draft/Confirmed/Cancelled)`, `delivered_at (date nullable)`, `notes`, `created_by`, `created_at`, `updated_at`, `deleted_at`

**Key methods:** `isEditable(): bool` (Draft only), `isConfirmed(): bool`

**Morph alias:** `delivery_note`

**Traits:** HasFactory, SoftDeletes, LogsActivity

---

### DeliveryNoteItem Model (`App\Models\DeliveryNoteItem`)
Line on a delivery note. Optionally links back to the SO item it fulfils.

**Columns:** `id`, `delivery_note_id (FK cascade)`, `sales_order_item_id (FK nullOnDelete, nullable)`, `product_variant_id (FK restrict)`, `quantity (decimal 15,4)`, `unit_cost (decimal 15,4)`, `notes (nullable)`, `created_at`, `updated_at`

**Key methods:** `returnedQuantity(): string`, `remainingReturnableQuantity(): string`

---

### CustomerInvoice, CustomerCreditNote, CustomerDebitNote, SalesReturn, AdvancePayment

These models mirror their purchase-side equivalents with the following key differences:
- `CustomerInvoice` — mirrors `SupplierInvoice`; adds `sales_order_id`, `invoice_type (InvoiceType enum)`, `is_reverse_charge (bool)`; drops `supplier_invoice_number`, `received_at`, `purchase_order_id`
- `CustomerInvoiceItem` — mirrors `SupplierInvoiceItem`; adds `sales_order_item_id`; has `creditedQuantity()` + `remainingCreditableQuantity()` methods
- `CustomerCreditNote` — mirrors `SupplierCreditNote`; adds `vat_scenario`, `vat_scenario_sub_code`, `is_reverse_charge`, `triggering_event_date`; immutability guard (FROZEN_FIELDS + booted) identical to `CustomerInvoice` — throws `RuntimeException` on update/delete of frozen fields once Confirmed (Art. 219 / чл. 115 ЗДДС)
- `CustomerDebitNote` — same added columns as `CustomerCreditNote`; uses `DebitNoteReason` enum; `customer_invoice_id` is nullable (standalone debit notes have no parent); same immutability guard
- `SalesReturn` — mirrors `PurchaseReturn`; links to `delivery_note_id` instead of `goods_received_note_id`
- `AdvancePayment` — no purchase-side mirror; tracks advance/prepayment received from customer; status pipeline: Open → PartiallyApplied → FullyApplied / Refunded; `remainingAmount()` method
- `AdvancePaymentApplication` — pivot linking `AdvancePayment` to `CustomerInvoice`; composite unique on `(advance_payment_id, customer_invoice_id)`

All models use `HasFactory`, `SoftDeletes` (except `AdvancePaymentApplication`), and `LogsActivity` (except items and applications).

---

## 4. Enums (40 Total)

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

#### ProductType (HasColor, HasLabel)
Product classification. Replaces `NomenclatureType` (removed in Phase 2).

**Cases:**
- `Stock` (primary) — Physical inventory item
- `Service` (success) — Non-stocked service; defaults `is_stockable = false`
- `Bundle` (warning) — Kit of multiple items

---

#### UnitType (HasLabel)
Unit of measure classification.

**Cases:**
- `Mass`, `Volume`, `Length`, `Area`, `Time`, `Piece`, `Other`

---

#### MovementType (HasColor, HasLabel)
Inventory transaction reason. Business-context naming (not generic Receipt/Issue).

**Cases:**
- `Purchase` — Inbound from supplier (default for `receive()`)
- `Sale` — Outbound to customer (default for `issue()`)
- `TransferOut` — Source side of a warehouse transfer
- `TransferIn` — Destination side of a warehouse transfer
- `Adjustment` — Manual correction (used by StockAdjustmentPage)
- `Return` — Goods returned
- `Opening` — Opening balance entry
- `InitialStock` — Initial stock load

**Design:** Business-context names produce readable audit trails. `InternalConsumption` and `Production` deferred to later phases.

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

### Phase 3.2 Sales Enums

**QuotationStatus** — Draft, Sent, Accepted, Expired, Rejected, Cancelled (HasColor, HasIcon, HasLabel)

**SalesOrderStatus** — Draft, Confirmed, PartiallyDelivered, Delivered, Invoiced, Cancelled (HasColor, HasIcon, HasLabel)

**DeliveryNoteStatus** — Draft, Confirmed, Cancelled (HasColor, HasLabel)

**SalesReturnStatus** — Draft, Confirmed, Cancelled (HasColor, HasLabel)

**AdvancePaymentStatus** — Open, PartiallyApplied, FullyApplied, Refunded (HasColor, HasLabel)

**InvoiceType** — Standard, Advance (HasLabel)

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

---

## 6. Phase 2 Tenant Database Schema (Catalog + Warehouse)

All models below live in the tenant database. No `tenant_id` column — tenancy is via database-level isolation.

### Category Model (`App\Models\Category`)
Hierarchical product categories, max 3 levels deep.

**Columns:** `id`, `name` (JSON, translatable), `slug` (unique), `parent_id` (self-referential FK, nullable), `description` (JSON, translatable), `is_active`, `created_at`, `updated_at`, `deleted_at`

**Traits:** HasFactory, HasTranslations, SoftDeletes

**Key behavior:**
- `boot()` saving event: auto-generates slug from name; throws `InvalidArgumentException` if depth > 2 (0=root, 1=child, 2=grandchild)
- Scopes: `roots()` (whereNull parent_id), `active()`, `withChildren()` (with eager-loaded children)
- Helper: `depthLevel(): int`

---

### Unit Model (`App\Models\Unit`)
Units of measure. Seeded at onboarding (13 standard units).

**Columns:** `id`, `name` (JSON, translatable), `symbol`, `type` (UnitType enum), `is_active`, `created_at`, `updated_at`

**No SoftDeletes** (reference data).

---

### Product Model (`App\Models\Product`)
Goods and services offered by the tenant.

**Columns:** `id`, `code` (unique), `name` (JSON, translatable), `description` (JSON, translatable), `type` (ProductType), `category_id` (nullable FK), `unit_id` (nullable FK), `purchase_price` (decimal 15,4), `sale_price` (decimal 15,4), `vat_rate_id` (nullable FK), `is_active`, `is_stockable`, `barcode` (varchar 128, nullable), `attributes` (JSON), `created_at`, `updated_at`, `deleted_at`

**Traits:** HasFactory, HasTranslations, SoftDeletes, LogsActivity (logs: name, code, type, is_active, sale_price)

**Key behavior:**
- `boot()` creating: sets `is_stockable` from type (Service → false, Stock/Bundle → true)
- `boot()` created: auto-creates a default hidden `ProductVariant` with `is_default=true`, `sku = product.code`
- Helper: `hasVariants(): bool` — true when non-default active variant exists
- Relationships: `variants()`, `defaultVariant()` (where is_default=true)

---

### ProductVariant Model (`App\Models\ProductVariant`)
Named variants of a product (size/color/material etc.). Every product has at least one (the hidden default).

**Columns:** `id`, `product_id` (FK), `name` (JSON, translatable), `sku` (unique), `purchase_price` (decimal 15,4, nullable), `sale_price` (decimal 15,4, nullable), `barcode` (varchar 128, nullable), `is_default`, `is_active`, `attributes` (JSON), `created_at`, `updated_at`, `deleted_at`

**Traits:** HasFactory, HasTranslations, SoftDeletes

**Key behavior:**
- Prices fall back to parent product if null: `effectivePurchasePrice()`, `effectiveSalePrice()`
- Default variant is hidden in UI (`ProductVariantsRelationManager` filters out `is_default=true`)
- `stockItems()`: HasMany → StockItem; `stockMovements()`: HasMany → StockMovement

---

### Warehouse Model (`App\Models\Warehouse`)
Physical warehouse locations.

**Columns:** `id`, `name`, `code` (unique), `address` (JSON: street/city/postal_code/country), `is_active`, `is_default`, `created_at`, `updated_at`, `deleted_at`

**Traits:** HasFactory, SoftDeletes

**Key behavior:**
- `boot()` saving: ensures only one `is_default = true` at a time (clears others when setting new default)
- Created at onboarding: `MAIN` warehouse (`is_default=true`, name="Main Warehouse")

---

### StockLocation Model (`App\Models\StockLocation`)
Bin/shelf/zone within a warehouse.

**Columns:** `id`, `warehouse_id` (FK), `name`, `code`, `is_active`, `created_at`, `updated_at`, `deleted_at`

**Traits:** HasFactory, SoftDeletes

**Unique constraint:** `(warehouse_id, code)`

---

### StockItem Model (`App\Models\StockItem`)
Current stock level per variant per warehouse (+ optional location). The "ledger balance."

**Columns:** `id`, `product_variant_id` (FK), `warehouse_id` (FK), `stock_location_id` (nullable FK), `quantity` (decimal 15,4), `reserved_quantity` (decimal 15,4), `created_at`, `updated_at`

**Traits:** HasFactory (**no SoftDeletes, no delete**)

**Key behavior:**
- Computed accessor: `available_quantity = quantity - reserved_quantity`
- Unique index: PostgreSQL partial unique `(product_variant_id, warehouse_id, COALESCE(stock_location_id, 0))` — handles nullable location

**Never update directly.** Always go through `StockService`.

---

### StockMovement Model (`App\Models\StockMovement`)
Immutable audit log of every stock change.

**Columns:** `id`, `product_variant_id` (FK, RESTRICT on delete), `warehouse_id` (FK, RESTRICT), `stock_location_id` (nullable FK), `type` (MovementType), `quantity` (decimal 15,4, signed: positive=in, negative=out), `reference_type` + `reference_id` (nullable morphs — for future Invoice/PO links), `notes`, `moved_at` (default: now), `moved_by` (user_id, no FK — cross-DB), `created_at`, `updated_at`

**Traits:** HasFactory (**no SoftDeletes**)

**Key behavior:**
- `boot()`: throws `RuntimeException` on update or delete ("Stock movements are immutable.")
- `reference()`: MorphTo — links to Invoice, PO, etc. in future phases (nullable now)

---

### StockService (`App\Services\StockService`)
Single entry point for all stock mutations. Stateless. All methods wrapped in `DB::transaction()`. Uses `bcmath` for decimal arithmetic.

```
receive(variant, warehouse, qty, ?location, ?reference, type=Purchase)
  → findOrCreate StockItem, increment quantity, create StockMovement(positive qty)
  → returns StockItem

issue(variant, warehouse, qty, ?location, ?reference, type=Sale)
  → check available_quantity >= qty (throws InsufficientStockException if not)
  → decrement quantity, create StockMovement(negative qty)
  → returns StockItem

adjust(variant, warehouse, qty (signed), reason, ?location)
  → increment or decrement based on sign
  → create StockMovement(Adjustment, signed qty, notes=reason)
  → returns StockItem

transfer(variant, fromWarehouse, toWarehouse, qty, ?fromLocation, ?toLocation)
  → check source available_quantity (throws InsufficientStockException if insufficient)
  → issue from source → create StockMovement(TransferOut, negative)
  → receive at destination → create StockMovement(TransferIn, positive)
  → returns [fromStockItem, toStockItem]
```

**Exception:** `App\Exceptions\InsufficientStockException` — carries `productVariant`, `warehouse`, `requestedQuantity`, `availableQuantity`.

---

### PdfTemplateResolver (`App\Services\PdfTemplateResolver`)
Resolves the correct Blade template path and render locale for PDF generation, supporting per-country statutory templates.

```
resolve(docType, ?countryCode = null)
  → checks View::exists("pdf.{docType}.{country}") where country comes from $countryCode ?? tenant->country_code
  → returns "pdf.{docType}.{country}" if view exists, else "pdf.{docType}.default"

localeFor(docType, ?countryCode = null)
  → returns "{country}" locale when a country-specific template exists (statutory requirement)
  → returns tenant->locale ?? config('app.fallback_locale') for the default template
```

**Used by:** all three invoice-family print actions — `ViewCustomerInvoice::print_invoice`, `ViewCustomerCreditNote::print_credit_note`, `ViewCustomerDebitNote::print_debit_note`.

**Template convention:** `resources/views/pdf/{docType}/{country}.blade.php` (e.g. `pdf/customer-invoice/bg.blade.php`); `pdf/{docType}/default.blade.php` is the universal fallback.

---

## 7. Summary Table

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
| **Category** | **Tenant** | **Product categories (max 3 deep)** | **HasTranslations, SoftDeletes** |
| **Unit** | **Tenant** | **Units of measure** | **HasTranslations** |
| **Product** | **Tenant** | **Catalog items** | **HasTranslations, LogsActivity, SoftDeletes** |
| **ProductVariant** | **Tenant** | **Product variants (always-variant pattern)** | **HasTranslations, SoftDeletes** |
| **Warehouse** | **Tenant** | **Physical stock locations** | **SoftDeletes** |
| **StockLocation** | **Tenant** | **Bin/shelf within warehouse** | **SoftDeletes** |
| **StockItem** | **Tenant** | **Current stock level (ledger balance)** | **– (no delete)** |
| **StockMovement** | **Tenant** | **Immutable stock audit log** | **– (immutable)** |

---

## 8. Phase 2 Design Decisions

### 8.1 Always-Variant Pattern (No Polymorphic Stockable)

**Why:** Simplicity over flexibility.

Every `Product` auto-creates a hidden default `ProductVariant` on creation. Stock is always tracked at the `ProductVariant` level via a simple `product_variant_id` FK. There is no polymorphic `stockable_type/stockable_id` column.

**Trade-off:** If a future model needs to be stockable (e.g., raw materials that aren't products), it must be represented as a Product. This is acceptable for the SME target market.

---

### 8.2 decimal(15,4) for Catalog Prices and Stock Quantities

Consistent with existing schema conventions. 4 decimal places handle unit-cost precision (e.g., 1 screw = 0.0500 BGN). Invoice totals use `decimal(15,2)` (Phase 3).

---

### 8.3 Immutable StockMovements

Stock movements are append-only. Updates and deletes throw `RuntimeException`. This enforces audit trail integrity — to correct a mistake, create a new adjustment movement (never edit the old one).

---

### 8.4 Translatable Fields via JSON Columns

Document-facing fields (`Product.name`, `Product.description`, `Category.name`, `Category.description`, `Unit.name`, `ProductVariant.name`) are stored as JSON (`{"en": "...", "bg": "..."}`) using `spatie/laravel-translatable`.

**Tenant locales:** Each tenant configures which locales they use via `CompanySettings` group `localization` keys (`locale_en`, `locale_bg`, etc.). `TranslatableLocales::forTenant()` reads these and returns the active locale list. Filament resources use this for the `LocaleSwitcher` header action.

---

## 9. Current Phase

**Phase 3.2 — Sales / Invoicing (in progress)**

Phases 1, 2, and 3.1 are complete. Phase 3.2 is building the outbound sales pipeline:

- ✅ 3.2.1: Enums + 20 migrations + 18 models + 15 factories (full outbound data layer)
- ✅ 3.2.2: Morph map (+8 entries), FiscalReceiptRequested event, StockService reserve/unreserve/issueReserved, 8 policies, RBAC (~80 new permissions), EuCountryVatRatesSeeder
- ✅ 3.2.3: QuotationResource + QuotationService + PDF templates (offer + proforma)
- ✅ 3.2.4: SalesOrderResource + SalesOrderService (stock reservation/unreservation, SO→PO import)
- ✅ 3.2.5: DeliveryNoteResource + DeliveryNoteService (issueReserved per stock item, SO qty update, PDF template)
- ✅ 3.2.6: CustomerInvoice Resource + CustomerInvoiceService + EuOssService + PDF template
- ✅ 3.2.7: CustomerCreditNote + CustomerDebitNote Resources + services
- ✅ VAT/VIES Wave 1: VIES pre-check + confirmWithScenario (EuB2bReverseCharge, NonEuExport, Exempt, Domestic)
- ✅ VAT/VIES Wave 2: DomesticExempt scenario + VatLegalReference model/seeder + per-country PDF rewrite (PdfTemplateResolver; country-specific Blade templates for all three invoice-family doc types; supplied_at field)
- ⬜ 3.2.8: SalesReturn Resource
- ⬜ 3.2.9: AdvancePayment Resource

**Phase 3.3 — SUPTO/NRA Fiscal Printer**
- `FiscalReceiptRequested` event is already wired; listener + ErpNet.FP REST integration is Phase 3.3
- Bulgarian fiscal compliance: print fiscal receipt on cash payment confirmation
- ErpNet.FP REST API for fiscal printer compliance

---

## 10. Test Infrastructure

### Overview

The test suite uses **Pest v4** with **parallel execution** (12 workers). Tests run via:

```bash
./vendor/bin/sail artisan test --parallel --compact
```

Typical runtime: ~75–90 seconds for 450+ tests. Without parallelism, the same suite takes ~250 seconds and leaves orphaned tenant databases.

---

### Why `DatabaseTruncation` Instead of `RefreshDatabase`

`RefreshDatabase` wraps each test in a database transaction and rolls it back on teardown. This is incompatible with multi-tenancy because **PostgreSQL DDL statements (`CREATE DATABASE`, `DROP DATABASE`) cannot run inside a transaction block**.

Every tenant test calls `TenantOnboardingService::onboard()`, which creates a new PostgreSQL database — that DDL would immediately abort a `RefreshDatabase` transaction.

`DatabaseTruncation` instead truncates all tables between tests without wrapping in a transaction. This is safe for DDL and is configured in `tests/Pest.php`:

```php
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->afterEach(function () {
        // Drop each tenant DB before central table truncation
        Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
    })
```

---

### Templated Tenant Database (`TemplatedPostgreSQLDatabaseManager`)

**File:** `app/Database/Testing/TemplatedPostgreSQLDatabaseManager.php`

**Problem:** Running ~40 migrations + 5 seeders for each new tenant DB in every test would be prohibitively slow across 400+ tests. Each `TenantOnboardingService::onboard()` call would spend ~1–2 seconds just on schema setup.

**Solution:** Instead of a bare `CREATE DATABASE`, the custom manager creates tenant DBs by cloning a pre-built template:

```sql
CREATE DATABASE "tenant_abc123" TEMPLATE "hmo_test_tenant_template"
```

PostgreSQL's `TEMPLATE` clause copies the entire database (schema + data) at the filesystem level. The clone is nearly instant (~5–10 ms vs ~1–2 s).

The custom class overrides `PostgreSQLDatabaseManager::createDatabase()`:

```php
class TemplatedPostgreSQLDatabaseManager extends PostgreSQLDatabaseManager
{
    public const TEMPLATE_DB = 'hmo_test_tenant_template';

    public function createDatabase(TenantWithDatabase $tenant): bool
    {
        return $this->database()->statement(sprintf(
            'CREATE DATABASE "%s" TEMPLATE "%s"',
            $tenant->database()->getName(),
            self::TEMPLATE_DB,
        ));
    }
}
```

It is registered in `config/tenancy.php` as the database manager for the `testing` environment.

---

### Template Database Management (`TenantTemplateManager`)

**File:** `tests/Support/TenantTemplateManager.php`

This class is responsible for building and validating the `hmo_test_tenant_template` database. It is called once per worker process via `TenantTemplateManager::ensureOnce()` in the Pest `beforeEach` hook.

#### How It Works

1. Acquires a **PostgreSQL advisory lock** (`pg_advisory_lock(987654321)`) — server-level, so all 12 workers serialise around the single build step.
2. Checks if the template is valid:
   - The `hmo_test_tenant_template` database exists in `pg_database`.
   - `storage/testing/tenant_template.hash` exists and matches the current hash.
3. If valid → releases lock and returns immediately (no rebuild needed).
4. If invalid → rebuilds: drops old template, creates fresh one, runs all tenant migrations, runs all seeders, stores the new hash.

#### Hash Invalidation

The hash is an `md5` of the concatenated `md5_file()` of:
- All files in `database/migrations/tenant/`
- `RolesAndPermissionsSeeder.php`
- `CurrencySeeder.php`
- `VatRateSeeder.php`
- `UnitSeeder.php`
- `EuCountryVatRatesSeeder.php`
- `TenantOnboardingService.php`

**Any change to these files automatically triggers a template rebuild on the next test run.** No manual intervention needed in the normal case.

---

### How to Force a Template Rebuild

#### Automatic (normal workflow)

Just add/edit a migration or seeder file. The hash will change on the next test run, and `TenantTemplateManager` will detect the mismatch and rebuild automatically.

#### Manual (when the template DB is corrupted or out of sync)

**Option 1 — delete the hash file** (template DB still exists but will be rebuilt):
```bash
rm storage/testing/tenant_template.hash
```

**Option 2 — drop the template DB + delete the hash file** (cleanest reset):
```bash
# Drop the template database
docker exec hmo-postgres psql -U hmo -d hmo_central \
  -c "DROP DATABASE IF EXISTS hmo_test_tenant_template;"

# Delete the hash so the manager knows to rebuild
rm -f storage/testing/tenant_template.hash
```

Then run the full test suite (not a filtered subset — see the race condition note below).

#### When You Must Drop + Delete

- The template DB was created from a broken migration (e.g., wrong timestamp ordering).
- A migration was deleted after the template was built.
- A seeder was manually run against the template outside of `TenantTemplateManager`.

---

### Parallel Race Condition (Filtered Runs)

When running a **small filtered subset** (e.g., `--filter=SomeTest`) with parallel enabled, all 12 workers start simultaneously with the hash missing. Multiple workers detect the template is invalid and all try to `CREATE DATABASE hmo_test_tenant_template` at the same time. The advisory lock should serialize this, but in practice the first worker to rebuild the template may complete before the second worker acquires the lock — so the second worker's `recreateTemplate()` call then tries to `CREATE DATABASE` on a database that already exists.

**Workaround:** After dropping the template and deleting the hash, run the **full test suite** first:

```bash
./vendor/bin/sail artisan test --parallel --compact
```

This gives enough concurrent tests that workers stay busy while worker 1 builds the template. Once the full suite has run (hash file written), filtered runs work reliably.

---

### Orphaned Tenant Database Cleanup

The `afterEach` hook in `tests/Pest.php` deletes all `Tenant` records after each test:

```php
Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
```

`Tenant::delete()` triggers `stancl/tenancy`'s lifecycle events, which call `TemplatedPostgreSQLDatabaseManager::deleteDatabase()` — dropping the cloned tenant DB. This prevents orphaned PostgreSQL databases from accumulating across the test run.

If you run tests **without `--parallel`**, the `afterEach` cleanup still runs but worker cleanup between test files may be incomplete. **Always use `--parallel`** — see the memory in `MEMORY.md`.
