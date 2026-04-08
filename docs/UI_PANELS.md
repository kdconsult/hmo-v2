# UI: Filament Panels & Resources

## 1. Panel Architecture

The application implements two separate Filament panels for distinct user roles and access patterns:

### 1.1 Landlord Panel

**Path:** `/landlord`
**ID:** `landlord`
**Primary Color:** Slate (`Color::Slate`)
**Provider:** `App\Providers\Filament\LandlordPanelProvider`

**Features:**
- Handles multi-tenant platform management at the central level
- No tenancy middleware (operates on central database)
- Standard authentication using `Filament\Http\Middleware\Authenticate`
- Discovers resources from `app/Filament/Landlord/Resources`
- Includes standard dashboard with AccountWidget and FilamentInfoWidget

**Auth Guard:** Default (central users)

---

### 1.2 Admin Panel

**Path:** `/admin`
**ID:** `admin`
**Primary Color:** Amber (`Color::Amber`)
**Provider:** `App\Providers\Filament\AdminPanelProvider`
**Default Panel:** Yes (route `/admin` is the application default)

**Features:**
- Multi-tenant aware panel for per-tenant application features
- Implements stancl/tenancy middleware for tenant isolation
- Auth middleware chain includes:
  - `Filament\Http\Middleware\Authenticate` (standard)
  - `App\Http\Middleware\EnsureActiveSubscription` (custom subscription validation)
- Tenancy initialization via:
  - `InitializeTenancyByDomain` (persistent middleware)
  - `PreventAccessFromCentralDomains` (blocks access from central domains)
- Discovers resources from `app/Filament/Resources`

**Auth Guard:** Tenant-scoped users (via tenancy middleware)

---

## 2. Landlord Panel Resources

### 2.1 TenantResource

**Model:** `App\Models\Tenant`
**Navigation Icon:** `Heroicon::OutlinedRectangleStack`
**Record Title Attribute:** `name`

**Pages:**
- `ListTenants` (index)
- `CreateTenant` (create)
- `ViewTenant` (view)
- `EditTenant` (edit)

**Relation Managers:**
- `DomainsRelationManager` (manage `domains` relationship)
- `UsersRelationManager` (manage `users` relationship)

#### Form Schema (TenantForm)

**Company Info Section:**
- `name` — Required, max 255 chars, full width
- `slug` — Required, unique, 63 chars max, alphaDash, auto-generated from name, visible on edit only
- `email` — Email format, max 255 chars
- `phone` — Tel format, max 50 chars
- `eik` — Company registration number, max 20 chars
- `vat_number` — Max 20 chars
- `mol` — Responsible person, max 255 chars
- `address_line_1` — Address, max 255 chars
- `city` — Max 100 chars
- `postal_code` — Max 20 chars
- `country_code` — Select field (EU countries), required, default 'BG', live + afterStateUpdated to auto-set currency/timezone/locale

**Localization Section:**
- `locale` — Select from collected EU country locales, required, default 'bg_BG'
- `timezone` — Select from EU timezones, required, default 'Europe/Sofia'
- `default_currency_code` — Select, required, default 'EUR'

**Subscription Section:**
- `plan_id` — Select relationship to Plan, required, filters active plans by sort_order
- `subscription_status` — Select enum (SubscriptionStatus), required, default Trial, edit only
- `trial_ends_at` — DateTimePicker, default +14 days
- `subscription_ends_at` — DateTimePicker for paid subscription, edit only

**Owner Section (Create Only):**
- `owner_user_id` — Select from central Users, optional, helper text explains the field

**Lifecycle Status Section (Edit Only):**
Placeholders showing read-only tenant lifecycle data:
- `status` — Displays TenantStatus enum label
- `deactivation_reason` — Shows 'Non-payment', 'Tenant request', or 'Other'
- `deactivated_at` — DateTime display
- `deactivated_by_name` — Name of deactivating user
- `marked_for_deletion_at` — DateTime display
- `scheduled_for_deletion_at` — DateTime display
- `deletion_scheduled_for` — When automatic deletion will occur, full width

#### Table Schema (TenantsTable)

**Columns (Sortable/Searchable as Marked):**
- `name` — Searchable, sortable (default sort)
- `slug` — Searchable, badge display
- `status` — Badge display, sortable
- `email` — Searchable, toggleable
- `eik` — Searchable, toggleable (hidden by default)
- `country_code` — Badge display
- `plan.name` — Relationship column, badge, toggleable
- `subscription_status` — Badge, sortable, toggleable
- `deactivated_at` — DateTime, sortable, toggleable (hidden by default)
- `deletion_scheduled_for` — DateTime, sortable, toggleable (hidden by default)
- `created_at` — DateTime, sortable, toggleable (hidden by default)

**Filters:**
- Status filter (SelectFilter using TenantStatus enum)

**Record Actions:**
- `ViewAction`
- `EditAction`
- `suspend` — Pause-circle icon, warning color
  - Modal form: Select reason (non_payment, tenant_request, other, default non_payment)
  - Calls `$record->suspend(auth()->user(), $data['reason'])`
  - Visible when tenant `isActive()`
  - Authorized by policy
- `markForDeletion` — Exclamation-triangle icon, danger color
  - No form, just confirmation
  - Calls `$record->markForDeletion()`
  - Visible when tenant `isSuspended()`
  - Authorized by policy
- `scheduleForDeletion` — X-circle icon, danger color
  - Modal form: DateTimePicker for `deletion_scheduled_for`, required, min date tomorrow
  - Calls `$record->scheduleForDeletion(Carbon date)`
  - Visible when status is `TenantStatus::MarkedForDeletion`
  - Authorized by policy
- `reactivate` — Check-circle icon, success color
  - No form, just confirmation
  - Calls `$record->reactivate()`
  - Visible when tenant is not active
  - Authorized by policy

---

### 2.2 PlanResource

**Model:** `App\Models\Plan`
**Navigation Icon:** `Heroicon::OutlinedCreditCard`
**Navigation Group:** `'Billing'`
**Record Title Attribute:** `name`

**Pages:**
- `ListPlans` (index)
- `CreatePlan` (create)
- `ViewPlan` (view)
- `EditPlan` (edit)

**Relation Managers:** None

#### Form Schema (PlanForm)

**Plan Details Section:**
- `name` — Required, max 100 chars, live onBlur, auto-generates slug
- `slug` — Required, unique, max 100 chars, alphaDash
- `price` — Required, numeric, EUR prefix, default 0, minValue 0
- `billing_period` — Select (monthly, yearly, lifetime), placeholder 'Free (no billing)'
- `max_users` — Numeric, minValue 1, placeholder 'Unlimited'
- `max_documents` — Label 'Max Documents / Month', numeric, minValue 1, placeholder 'Unlimited'
- `sort_order` — Numeric, default 0, required
- `is_active` — Toggle, default true, required

**Features Section:**
- `features` — KeyValue field for storing feature flags (key=feature name, value=value)

---

### 2.3 UserResource

**Model:** `App\Models\User` (central)
**Navigation Icon:** `Heroicon::OutlinedRectangleStack`
**Record Title Attribute:** `name`

**Pages:**
- `ListUsers` (index)
- `CreateUser` (create)
- `ViewUser` (view)
- `EditUser` (edit)

**Relation Managers:** None

#### Form Schema (UserForm)

Simple form with fields:
- `name` — Required
- `email` — Required, email format
- `email_verified_at` — DateTimePicker
- `password` — Required, password input
- `avatar_path` — Text input
- `locale` — Text input
- `is_landlord` — Toggle, required
- `last_login_at` — DateTimePicker

#### Table Schema (UsersTable)

Standard CRUD table with display for user records (not shown in detail in schema files).

---

### 2.4 DomainsRelationManager

**Relationship:** `domains` on Tenant

**Form Schema:**
- `domain` — Required, max 255 chars

**Table Columns:**
- `domain` — Searchable

**Actions:**
- Header: CreateAction, AssociateAction
- Record: EditAction, DissociateAction, DeleteAction
- Toolbar: DissociateBulkAction, DeleteBulkAction

---

### 2.5 UsersRelationManager

**Relationship:** `users` on Tenant

**Form Schema:**
- `name` — Required, max 255 chars
- `email` — Required, email format, max 255 chars
- `password` — Required, password input, max 255 chars
- `is_landlord` — Toggle, default false

**Table Columns:**
- `name` — Searchable, sortable
- `email` — Searchable
- `last_login_at` — Label 'Last Login', DateTime, sortable, placeholder 'Never'
- `is_landlord` — Label 'Landlord', IconColumn (boolean)
- `created_at` — DateTime, sortable, toggleable (hidden by default)

**Actions:**
- Header: CreateAction (with `after` hook calling `TenantOnboardingService->onboard()`), AssociateAction (with `after` hook creating TenantUser in tenant DB)
- Record: ViewAction, DissociateAction, DeleteAction
- Toolbar: DissociateBulkAction, DeleteBulkAction

---

## 3. Admin Panel: Settings Group

All Settings group resources use the string literal `'Settings'` for `$navigationGroup`.

### 3.1 CurrencyResource

**Model:** `App\Models\Currency`
**Navigation Icon:** `Heroicon::OutlinedCurrencyDollar`
**Navigation Group:** `'Settings'`
**Record Title Attribute:** `name`

**Pages:**
- `ListCurrencies` (index)
- `CreateCurrency` (create)
- `ViewCurrency` (view)
- `EditCurrency` (edit)

**Relation Managers:**
- `ExchangeRatesRelationManager` (manage `exchangeRates` relationship)

#### Form Schema (CurrencyForm)

2-column layout:
- `code` — Required, max 3 chars, upperCase, unique
- `name` — Required, max 100 chars
- `symbol` — Max 10 chars
- `decimal_places` — Numeric integer, default 2, range 0-8
- `is_default` — Label 'Default Currency', toggle
- `is_active` — Toggle, default true

---

### 3.2 VatRateResource

**Model:** `App\Models\VatRate`
**Navigation Icon:** `Heroicon::OutlinedReceiptPercent`
**Navigation Group:** `'Settings'`
**Record Title Attribute:** `name`
**Special:** Excludes SoftDeletingScope in route binding

**Pages:**
- `ListVatRates` (index)
- `CreateVatRate` (create)
- `ViewVatRate` (view)
- `EditVatRate` (edit)

**Relation Managers:** None

#### Form Schema (VatRateForm)

2-column layout:
- `country_code` — Label 'Country Code', required, max 2, upperCase, default 'BG'
- `type` — Select (standard, reduced, zero, exempt), required
- `name` — Required, max 100 chars, full width
- `rate` — Numeric, required, suffix '%', range 0-100
- `sort_order` — Numeric integer, default 0
- `effective_from` — DatePicker, label 'Effective From'
- `effective_to` — DatePicker, label 'Effective To'
- `is_default` — Label 'Default Rate', toggle
- `is_active` — Toggle, default true

---

### 3.3 DocumentSeriesResource

**Model:** `App\Models\DocumentSeries`
**Navigation Icon:** `Heroicon::OutlinedDocumentText`
**Navigation Group:** `'Settings'`
**Record Title Attribute:** `name`
**Special:** Excludes SoftDeletingScope in route binding

**Pages:**
- `ListDocumentSeries` (index)
- `CreateDocumentSeries` (create)
- `ViewDocumentSeries` (view)
- `EditDocumentSeries` (edit)

**Relation Managers:** None

#### Form Schema (DocumentSeriesForm)

**Series Settings Section:**
- `document_type` — Select from DocumentType enum, required
- `name` — Required, max 100 chars
- `prefix` — Required, max 20 chars, upperCase
- `separator` — Default '-', max 5 chars

**Number Format Section:**
- `include_year` — Label 'Include Year', toggle, default true
- `year_format` — Label 'Year Format', select (Y = '4 digits (2025)', y = '2 digits (25)'), default 'Y'
- `padding` — Numeric integer, default 5, range 1-10, helper 'Number of digits (padded with zeros)'
- `next_number` — Numeric integer, default 1, minValue 1
- `reset_yearly` — Label 'Reset Counter Yearly', toggle, default true
- `is_default` — Label 'Default for Type', toggle
- `is_active` — Toggle, default true

---

### 3.4 TenantUserResource

**Model:** `App\Models\TenantUser`
**Navigation Icon:** `Heroicon::OutlinedUsers`
**Navigation Group:** `'Settings'`
**Record Title Attribute:** `user_id`
**Special:** Excludes SoftDeletingScope in route binding

**Pages:**
- `ListTenantUsers` (index)
- `CreateTenantUser` (create)
- `ViewTenantUser` (view)
- `EditTenantUser` (edit)

**Relation Managers:** None

#### Form Schema (TenantUserForm)

**User Account Section:**
- `user_id` — Label 'Central User', select from central User table, searchable, required, helper text explains linking
- `roles` — Label 'Role', select from Spatie\Permission Role table, preload enabled
- `display_name` — Max 255 chars, placeholder 'Leave empty to use account name'
- `job_title` — Max 255 chars
- `phone` — Tel format, max 50 chars
- `is_active` — Toggle, default true

---

### 3.5 RoleResource

**Model:** `Spatie\Permission\Models\Role`
**Navigation Icon:** `Heroicon::OutlinedShieldCheck`
**Navigation Group:** `'Settings'`
**Record Title Attribute:** `name`

**Pages:**
- `ListRoles` (index)
- `CreateRole` (create)
- `ViewRole` (view)
- `EditRole` (edit)

**Relation Managers:** None

**Form/Table/Infolist:** Managed by RoleForm, RoleInfolist, RolesTable schemas (implementation details in respective files).

---

## 4. Admin Panel: CRM Group

All CRM group resources use the string literal `'CRM'` for `$navigationGroup`.

### 4.1 PartnerResource

**Model:** `App\Models\Partner`
**Navigation Icon:** `Heroicon::OutlinedBuildingOffice2`
**Navigation Group:** `'CRM'`
**Record Title Attribute:** `name`
**Special:** Excludes SoftDeletingScope in route binding, supports soft deletes with bulk restore/force delete

**Pages:**
- `ListPartners` (index)
- `CreatePartner` (create)
- `ViewPartner` (view)
- `EditPartner` (edit)

**Relation Managers:**
- `AddressesRelationManager` (manage `addresses` relationship)
- `ContactsRelationManager` (manage `contacts` relationship)
- `BankAccountsRelationManager` (manage `bankAccounts` relationship)

#### Form Schema (PartnerForm)

**General Info Section:**
- `type` — Select PartnerType enum, required, default PartnerType::Company
- `name` — Required, max 255 chars, full width
- `company_name` — Max 255 chars
- `eik` — Label 'EIK', max 20 chars
- `vat_number` — Label 'VAT Number', max 20 chars
- `mol` — Label 'MOL', max 255 chars
- `email` — Email format, max 255 chars
- `phone` — Tel format, max 50 chars
- `secondary_phone` — Tel format, max 50 chars
- `website` — URL format, max 255 chars

**Classification Section:**
- `is_customer` — Label 'Customer', toggle, default true
- `is_supplier` — Label 'Supplier', toggle
- `is_active` — Toggle, default true

**Financial Section:**
- `default_currency_code` — Label 'Currency', max 3 chars, default 'BGN'
- `default_payment_term_days` — Label 'Payment Terms (days)', numeric integer, default 30
- `default_payment_method` — Select PaymentMethod enum
- `default_vat_rate_id` — Label 'Default VAT Rate', select from VatRate active records, searchable
- `credit_limit` — Numeric, BGN prefix
- `discount_percent` — Numeric, '%' suffix

#### Table Schema (PartnersTable)

**Columns:**
- `name` — Searchable, sortable (default sort)
- `type` — Badge display, sortable
- `email` — Searchable, toggleable
- `phone` — Searchable, toggleable
- `eik` — Label 'EIK', searchable, toggleable (hidden by default)
- `vat_number` — Label 'VAT No.', searchable, toggleable (hidden by default)
- `is_customer` — Label 'Customer', IconColumn (boolean)
- `is_supplier` — Label 'Supplier', IconColumn (boolean)
- `is_active` — Label 'Active', IconColumn (boolean)

**Filters:**
- Type filter (SelectFilter using PartnerType enum)
- is_customer filter (TernaryFilter, label 'Customer')
- is_supplier filter (TernaryFilter, label 'Supplier')
- is_active filter (TernaryFilter, label 'Active')
- TrashedFilter (shows soft-deleted records)

**Search Debounce:** 500ms

**Record Actions:**
- ViewAction
- EditAction

**Toolbar Actions:**
- DeleteBulkAction
- ForceDeleteBulkAction
- RestoreBulkAction

---

### 4.2 ContractResource

**Model:** `App\Models\Contract`
**Navigation Icon:** `Heroicon::OutlinedClipboardDocumentList`
**Navigation Group:** `'CRM'`
**Record Title Attribute:** `contract_number`
**Special:** Excludes SoftDeletingScope in route binding

**Pages:**
- `ListContracts` (index)
- `CreateContract` (create)
- `ViewContract` (view)
- `EditContract` (edit)

**Relation Managers:** None

**Form/Table/Infolist:** Managed by ContractForm, ContractInfolist, ContractsTable schemas.

---

### 4.3 TagResource

**Model:** `App\Models\Tag`
**Navigation Icon:** `Heroicon::OutlinedTag`
**Navigation Group:** `'CRM'`
**Navigation Label:** `'Tags'`

**Pages:**
- `ManageTags` (single page for listing/creating/editing, no traditional CRUD pages)

**Form Schema (Inline in TagResource):**

2-column layout:
- `name` — Required, max 50 chars, unique
- `color` — ColorPicker

**Table Schema:**
- `color` — ColorColumn
- `name` — Searchable, sortable

**Record Actions:**
- EditAction
- DeleteAction

**Toolbar Actions:**
- DeleteBulkAction

---

## 5. Navigation Groups

Navigation groups are used to organize resources in the left sidebar menu.

### 5.1 Landlord Panel

- No explicit navigation group defined for TenantResource, UserResource, PlanResource
- Resources appear in default navigation section

### 5.2 Admin Panel

**Settings Group** (`$navigationGroup = 'Settings'`):
- CurrencyResource
- VatRateResource
- DocumentSeriesResource
- TenantUserResource
- RoleResource

**CRM Group** (`$navigationGroup = 'CRM'`):
- PartnerResource
- ContractResource
- TagResource

---

## 6. Relation Managers

All relation managers follow the standard Filament pattern with form, table, header/record/toolbar actions.

### 6.1 Landlord Panel Relation Managers

#### DomainsRelationManager
- **Attached to:** TenantResource
- **Relationship:** `domains` (Tenant → Domain)
- **Unique Fields:** domain (searchable)
- **Actions:** Create, Associate, Edit, Dissociate, Delete, with bulk Dissociate/Delete

#### UsersRelationManager
- **Attached to:** TenantResource
- **Relationship:** `users` (Tenant → User via pivot)
- **Unique Fields:** name (searchable), email (searchable), last_login_at
- **Actions:** Create (with TenantOnboardingService integration), Associate (with TenantUser creation), View, Dissociate, Delete, with bulk Dissociate/Delete
- **Special Behavior:** CreateAction and AssociateAction have `after()` hooks for onboarding/tenant user setup

---

### 6.2 Admin Panel Relation Managers

#### ExchangeRatesRelationManager
- **Attached to:** CurrencyResource
- **Relationship:** `exchangeRates` on Currency
- **Unique Fields:** id (searchable)
- **Actions:** Create, Associate, Edit, Dissociate, Delete, with bulk Dissociate/Delete

#### AddressesRelationManager
- **Attached to:** PartnerResource
- **Relationship:** `addresses` on Partner
- **Unique Fields:** full_name (searchable)
- **Actions:** Create, Associate, Edit, Dissociate, Delete, with bulk Dissociate/Delete

#### ContactsRelationManager
- **Attached to:** PartnerResource
- **Relationship:** `contacts` on Partner
- **Unique Fields:** full_name (searchable)
- **Actions:** Create, Associate, Edit, Dissociate, Delete, with bulk Dissociate/Delete

#### BankAccountsRelationManager
- **Attached to:** PartnerResource
- **Relationship:** `bankAccounts` on Partner
- **Unique Fields:** account_number (searchable)
- **Actions:** Create, Associate, Edit, Dissociate, Delete, with bulk Dissociate/Delete

---

## 7. Key Architectural Patterns

### 7.1 Schema Organization

Resources use separate schema files (Schemas/ subdirectory) for form, infolist, and table definitions:
- `Schemas/ResourceForm.php` — Form configuration
- `Schemas/ResourceInfolist.php` — Read-only infolist display
- `Tables/ResourceTable.php` — Table listing configuration

Each schema file contains a static `configure(Schema|Table $schema)` method called from the Resource class.

### 7.2 Tenancy Integration

- **Landlord Panel:** No tenancy middleware, operates on central database
- **Admin Panel:** Fully tenant-scoped via `InitializeTenancyByDomain` middleware
- **TenantUser Model:** Acts as tenant-specific user representation; linked to central User via `user_id` relationship

### 7.3 Soft Deletes

Resources with soft-delete support (Partner, Contract, VatRate, DocumentSeries, TenantUser) override `getRecordRouteBindingEloquentQuery()` to exclude SoftDeletingScope, allowing them to query soft-deleted records in edit/view pages. Tables include TrashedFilter and bulk restore/force-delete actions.

### 7.4 Lifecycle Management

TenantResource includes four lifecycle actions with modal confirmations:
- **suspend:** User can pause a tenant's access
- **markForDeletion:** Triggers grace period
- **scheduleForDeletion:** Sets automatic deletion date
- **reactivate:** Restores a non-active tenant

All actions call methods on the Tenant model and are policy-authorized.

### 7.5 Dynamic Field Behavior

- TenantForm: `country_code` live field triggers auto-update of currency, timezone, locale
- PlanForm: `name` field live generates slug on blur
- CurrencyForm: `is_default` toggle manages default currency state
