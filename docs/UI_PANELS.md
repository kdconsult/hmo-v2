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
  - `InitializeTenancyBySubdomain` (persistent middleware — extracts subdomain, looks up in domains table)
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

### 3.3 NumberSeriesResource

**Model:** `App\Models\NumberSeries`
**Navigation Icon:** `Heroicon::OutlinedDocumentText`
**Navigation Group:** `NavigationGroup::Settings`
**Navigation Label:** `'Number Series'`
**Record Title Attribute:** `name`
**Special:** Excludes SoftDeletingScope in route binding

**Pages:**
- `ListNumberSeries` (index)
- `CreateNumberSeries` (create)
- `ViewNumberSeries` (view)
- `EditNumberSeries` (edit)

**Relation Managers:** None

#### Form Schema (NumberSeriesForm)

**Series Settings Section:**
- `series_type` — Select from SeriesType enum (Invoice, CreditNote, PurchaseOrder, Product, Partner, …), required
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

## 4b. Admin Panel: Catalog Group

All Catalog group resources use `NavigationGroup::Catalog` for `$navigationGroup`.

### 4b.1 CategoryResource

**Model:** `App\Models\Category` | **Sort:** 1 | **Pages:** List, Create, View, Edit

**Form:** `name` (translatable, required), `description` (translatable), `parent_id` (select, optional — limited to top 2 levels so children max depth = 3), `sort_order`, `is_active`

**Table:** name, parent.name, is_active badge, sort_order | **Filters:** is_active, parent_id

**Relation Managers:** `SubcategoriesRelationManager` — inline child categories

---

### 4b.2 UnitResource

**Model:** `App\Models\Unit` | **Sort:** 2 | **Pages:** ManageUnits (single-page CRUD)

**Form:** `name` (translatable), `symbol`, `type` (UnitType select), `is_default`, `is_active`

**Table:** name, symbol, type badge, is_default, is_active

---

### 4b.3 ProductResource

**Model:** `App\Models\Product` | **Sort:** 3 | **Pages:** List, Create, View, Edit

**Form:**
- `name` (translatable, required), `description` (translatable)
- `category_id`, `unit_id`, `type` (ProductType: Stock/Service/Bundle), `status` (ProductStatus: Draft/Active/Discontinued)
- `sku`, `barcode`, `purchase_price` (decimal), `sale_price` (decimal), `is_stockable`
- `notes`

**Table:** name (searchable), category.name, type badge, status badge, sku, sale_price | **Filters:** type, status, category, is_stockable, TrashedFilter

**Relation Managers:** `ProductVariantsRelationManager` — manage named variants; default variant always present but hidden in list (`is_visible=false`)

---

## 4c. Admin Panel: Warehouse Group

All Warehouse group resources use `NavigationGroup::Warehouse` for `$navigationGroup`.

### 4c.1 WarehouseResource

**Model:** `App\Models\Warehouse` | **Sort:** 1 | **Pages:** List, Create, View, Edit

**Form:** `code` (unique), `name`, `address` (JSON textarea), `is_default`, `is_active`

**Table:** code (searchable), name (searchable), is_default badge, is_active | **Filters:** is_active, TrashedFilter

**Relation Managers:** `StockLocationsRelationManager` — bins/shelves within the warehouse

---

### 4c.2 StockItemResource

**Model:** `App\Models\StockItem` | **Read-only** | **Pages:** List only (no create/edit)

**Table:** variant.sku, variant.product.name, warehouse.name, quantity, reserved_quantity, available_quantity (computed) | **Filters:** warehouse, product

---

### 4c.3 StockMovementResource

**Model:** `App\Models\StockMovement` | **Read-only** | **Pages:** List only

**Table:** variant.sku, variant.product.name, warehouse.name, type badge (MovementType), quantity (signed — positive=in, negative=out), reference (morph link), moved_by ("By" column shows central user name), created_at

**Filters:** type (MovementType), warehouse, date range

---

## 5. Navigation Groups

Navigation groups are used to organize resources in the left sidebar menu.

### 5.1 Landlord Panel

- No explicit navigation group defined for TenantResource, UserResource, PlanResource
- Resources appear in default navigation section

### 5.2 Admin Panel

**Settings Group** (`NavigationGroup::Settings`):
- CurrencyResource
- VatRateResource
- NumberSeriesResource
- TenantUserResource
- RoleResource

**CRM Group** (`NavigationGroup::Crm`):
- PartnerResource
- ContractResource
- TagResource

**Catalog Group** (`NavigationGroup::Catalog`):
- CategoryResource (with subcategory relation manager)
- UnitResource (simple ManageRecords page)
- ProductResource (with ProductVariantsRelationManager)

**Warehouse Group** (`NavigationGroup::Warehouse`):
- WarehouseResource (with StockLocationsRelationManager)
- StockItemResource (read-only)
- StockMovementResource (read-only, shows `moved_by` as "By" column)

> Note: `StockAdjustmentPage` was removed in Phase 2.5 (WAREHOUSE-1). Stock adjustments require a formal inventory audit process (WAREHOUSE-2, future).

**Purchases Group** (`NavigationGroup::Purchases`):
- PurchaseOrderResource (sort 1, with PurchaseOrderItemsRelationManager; actions: Send, Confirm, Cancel, Create GRN, Create Supplier Invoice)
- GoodsReceivedNoteResource (sort 2, label 'Goods Receipts', with GoodsReceivedNoteItemsRelationManager; Confirm Receipt action is irreversible, calls GoodsReceiptService)
- SupplierInvoiceResource (sort 3, with SupplierInvoiceItemsRelationManager supporting free-text lines; actions: Confirm, Cancel, Create Credit Note)
- SupplierCreditNoteResource (sort 4, with SupplierCreditNoteItemsRelationManager; quantity validated against remainingCreditableQuantity())

---

## 5b. Admin Panel: Purchases Group

All Purchases group resources use `NavigationGroup::Purchases` for `$navigationGroup`.

### 5b.1 PurchaseOrderResource

**Model:** `App\Models\PurchaseOrder`
**Navigation Sort:** 1
**Record Title Attribute:** `po_number`
**Pages:** List, Create, View, Edit

#### Form Schema (PurchaseOrderForm)

**Purchase Order Section:**
- `partner_id` — Select from active suppliers (`Partner::suppliers()->where('is_active', true)`), searchable, required
- `warehouse_id` — Select from warehouses, optional
- `document_series_id` — Select from PurchaseOrder series, optional
- `po_number` — Auto-filled from series or manual; disabled if auto-generated
- `pricing_mode` — Select VatExclusive/VatInclusive, required

**Pricing & Currency Section:**
- `currency_code` — Required, default EUR
- `exchange_rate` — Decimal, default 1.0

**Dates Section:**
- `ordered_at` — DatePicker, required
- `expected_delivery_date` — DatePicker, optional

**Computed Totals Section (disabled):**
- `subtotal`, `discount_amount`, `tax_amount`, `total`

**Notes Section:** `notes`, `internal_notes`

#### Table Schema (PurchaseOrdersTable)

**Columns:** po_number (searchable), partner.name, status (badge), total, expected_delivery_date, ordered_at (default sort desc)

**Filters:** SelectFilter status (PurchaseOrderStatus), TrashedFilter

#### View Page Header Actions

- **Edit** — visible when `$record->isEditable()`
- **Send** — Draft → Sent (calls `PurchaseOrderService::transitionStatus()`)
- **Confirm** — Sent → Confirmed
- **Cancel** — Cancels from any non-terminal status
- **Create GRN** — URL link to GoodsReceivedNotes create page with `?purchase_order_id=` pre-fill; visible when Confirmed/PartiallyReceived
- **Create Supplier Invoice** — URL link to SupplierInvoices create page with `?purchase_order_id=` pre-fill; visible when Confirmed+

#### PurchaseOrderItemsRelationManager

- `product_variant_id` — Select with afterStateUpdated to auto-fill `unit_price` (from variant `purchase_price`) and `description`
- `quantity`, `unit_price`, `discount_percent`, `vat_rate_id`
- `quantity_received` — Read-only progress indicator (disabled)
- Computed: `line_total`, `line_total_with_vat` (disabled)
- `after()` hooks on Create/Edit/Delete call `PurchaseOrderService::recalculateItemTotals()` and `recalculateDocumentTotals()`

---

### 5b.2 GoodsReceivedNoteResource

**Model:** `App\Models\GoodsReceivedNote`
**Navigation Sort:** 2
**Navigation Label:** `'Goods Receipts'`
**Record Title Attribute:** `grn_number`
**Pages:** List, Create, View, Edit (Edit redirects to View if GRN is confirmed)

#### Form Schema (GoodsReceivedNoteForm)

- `purchase_order_id` — Optional, live; `afterStateUpdated` auto-fills `partner_id` and `warehouse_id` from the PO
- `partner_id` — Required, select from active suppliers
- `warehouse_id` — **Required** (physical receipt must target a warehouse)
- `document_series_id` — Optional
- `grn_number` — Auto-filled from series or manual
- `received_at` — DatePicker, optional (set automatically on confirmation)
- `notes`

#### Table Schema (GoodsReceivedNotesTable)

**Columns:** grn_number (searchable), partner.name, warehouse.name, status (badge), received_at, created_at

**Filters:** SelectFilter status (GoodsReceivedNoteStatus), TrashedFilter

#### View Page Header Actions

- **Edit** — visible when `$record->isEditable()`
- **Confirm Receipt** — calls `GoodsReceiptService::confirm()`; has `requiresConfirmation()` modal with irreversibility warning; disabled after confirmation
- **Cancel** — visible for Draft only; calls `GoodsReceiptService::cancel()`

#### Mount Behaviour

`CreateGoodsReceivedNote::mount()` reads `?purchase_order_id=` from URL query string and pre-fills the form field (used by PO → "Create GRN" cross-document action).

#### GoodsReceivedNoteItemsRelationManager

- `isReadOnly()` returns `true` when GRN is confirmed (items immutable)
- `purchase_order_item_id` — Optional; when set, auto-fills `product_variant_id` and `unit_cost`
- `product_variant_id` — Required; select with afterStateUpdated to auto-fill `unit_cost` from variant `purchase_price`
- `quantity` — Required (decimal)
- `unit_cost` — Required (decimal)

---

### 5b.3 SupplierInvoiceResource

**Model:** `App\Models\SupplierInvoice`
**Navigation Sort:** 3
**Record Title Attribute:** `internal_number`
**Pages:** List, Create, View, Edit

#### Form Schema (SupplierInvoiceForm)

**Invoice Reference Section:**
- `supplier_invoice_number` — Supplier's own reference number, required
- `internal_number` — Auto-generated from NumberSeries at creation; displayed as disabled/dehydrated on edit
- `document_series_id`, `purchase_order_id` (live — auto-fills partner/currency on change)

**Partner & Currency Section:**
- `partner_id` — Required, select from active suppliers
- `currency_code`, `exchange_rate`, `pricing_mode`

**Dates Section:**
- `issued_at` — Required
- `received_at`, `due_date` — Optional

**Payment:** `payment_method` (optional)

**Computed Totals (disabled):** `subtotal`, `discount_amount`, `tax_amount`, `total`, `amount_paid`, `amount_due`

**Notes:** `notes`, `internal_notes`

#### Table Schema (SupplierInvoicesTable)

**Columns:** internal_number (searchable), supplier_invoice_number (searchable), partner.name, status (badge), total, due_date, issued_at

**Filters:** SelectFilter status, TrashedFilter

#### View Page Header Actions

- **Edit** — visible when `isEditable()`
- **Confirm** — Draft → Confirmed
- **Cancel** — visible when editable
- **Create Credit Note** — URL link to SupplierCreditNotes create page with `?supplier_invoice_id=` pre-fill; visible when Confirmed

#### Mount Behaviour

`CreateSupplierInvoice::mount()` reads `?purchase_order_id=` from URL and pre-fills partner, currency from the PO.

`mutateFormDataBeforeCreate()` generates `internal_number` from the default SupplierInvoice `NumberSeries`.

#### SupplierInvoiceItemsRelationManager

- `isReadOnly()` returns `true` when invoice is confirmed
- `product_variant_id` — **Nullable** (free-text lines without a product are allowed)
- `description` — Required (not null; must be supplied for free-text lines)
- `quantity`, `unit_price`, `discount_percent`, `vat_rate_id`
- Computed: `vat_amount`, `line_total`, `line_total_with_vat` (disabled)
- `after()` hooks on Create/Edit/Delete call `$invoice->recalculateTotals()`

---

### 5b.4 SupplierCreditNoteResource

**Model:** `App\Models\SupplierCreditNote`
**Navigation Sort:** 4
**Record Title Attribute:** `credit_note_number`
**Pages:** List, Create, View, Edit

#### Form Schema (SupplierCreditNoteForm)

- `supplier_invoice_id` — **Required**, live; `afterStateUpdated` auto-fills `partner_id`, `currency_code`, `exchange_rate`
- `partner_id` — Read-only display (populated from invoice)
- `reason` — Select CreditNoteReason enum, live
- `reason_description` — Visible only when `reason = Other`
- `issued_at` — Required
- `document_series_id`
- `credit_note_number` — Auto-generated at creation
- `currency_code`, `exchange_rate`
- Computed totals (disabled): `subtotal`, `tax_amount`, `total`

#### Table Schema (SupplierCreditNotesTable)

**Columns:** credit_note_number (searchable), supplierInvoice.internal_number, partner.name, reason (badge), status (badge), total, issued_at

**Filters:** SelectFilter status, TrashedFilter

#### Mount Behaviour

`CreateSupplierCreditNote::mount()` reads `?supplier_invoice_id=` from URL and pre-fills the form (used by SupplierInvoice → "Create Credit Note" action).

#### SupplierCreditNoteItemsRelationManager

- `isReadOnly()` returns `true` when credit note is confirmed
- `supplier_invoice_item_id` — Required; options filtered to parent invoice's items; label shows item description + remaining creditable qty
- `afterStateUpdated` auto-fills `product_variant_id`, `unit_price`, `vat_rate_id`, `description`
- `quantity` — Validated against `remainingCreditableQuantity()` using `lockForUpdate()` to prevent race conditions
- `after()` hooks call `$creditNote->recalculateTotals()`

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
- **Admin Panel:** Fully tenant-scoped via `InitializeTenancyBySubdomain` middleware
- **TenantUser Model:** Acts as tenant-specific user representation; linked to central User via `user_id` relationship

### 7.3 Soft Deletes

Resources with soft-delete support (Partner, Contract, VatRate, NumberSeries, TenantUser) override `getRecordRouteBindingEloquentQuery()` to exclude SoftDeletingScope, allowing them to query soft-deleted records in edit/view pages. Tables include TrashedFilter and bulk restore/force-delete actions.

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
