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
- PurchaseReturnResource (sort 5, with PurchaseReturnItemsRelationManager; Confirm action calls PurchaseReturnService::confirm() — stock goes down; "Import from GRN" header action auto-imports GRN lines)

**Sales Group** (`NavigationGroup::Sales`):
- QuotationResource (sort 1, with QuotationItemsRelationManager; actions: Send, Accept, Reject, Convert to SO with warehouse picker modal, Cancel, Print as Offer PDF, Print as Proforma PDF)
- SalesOrderResource (sort 2, with SalesOrderItemsRelationManager; Confirm triggers stock reservation; Import to PO action; Cancel cascades to draft DNs/invoices)
- DeliveryNoteResource (sort 3, label 'Delivery Notes', with DeliveryNoteItemsRelationManager; Confirm Delivery calls DeliveryNoteService::confirm() — issues reserved stock; Print PDF action)
- CustomerInvoiceResource (sort 4, with CustomerInvoiceItemsRelationManager; Confirm calls CustomerInvoiceService::confirm() — updates SO qty_invoiced, sets service item qty_delivered, dispatches FiscalReceiptRequested on cash, accumulates EU OSS; Print Invoice PDF; Create Credit/Debit Note actions wired to their respective resources)
- CustomerCreditNoteResource (sort 5, with CustomerCreditNoteItemsRelationManager; quantity validated against remainingCreditableQuantity() with lockForUpdate(); requires linked customer_invoice_item)
- CustomerDebitNoteResource (sort 6, with CustomerDebitNoteItemsRelationManager; free-form line items — invoice item link optional, product_variant_id optional; no quantity constraint)
- SalesReturnResource (sort 7, with SalesReturnItemsRelationManager; Confirm calls SalesReturnService::confirm() — receives stock back via StockService::receive(); Create Credit Note modal action on confirmed returns; Cancel for Draft only)
- AdvancePaymentResource (sort 8, no items relation manager — single-amount document; Issue Advance Invoice action creates confirmed CustomerInvoice(Advance type); Apply to Invoice modal deducts amount from final invoice; Refund action)

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
- `supplier_invoice_id` — FK to `supplier_invoices`; set automatically when GRN is created via "Confirm & Receive" on a SupplierInvoice; enables Related Documents panel to link GRN ↔ SI even without a shared PO
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
- **Confirm & Receive** — visible when Draft AND tenant setting `express_purchasing = true`; opens warehouse selector modal; calls `SupplierInvoiceService::confirmAndReceive()` in a single transaction (confirms SI + creates and confirms a GRN with stock movement)
- **Cancel** — visible when editable
- **Create Credit Note** — URL link to SupplierCreditNotes create page with `?supplier_invoice_id=` pre-fill; visible when Confirmed

#### Mount Behaviour

`CreateSupplierInvoice::mount()` reads `?purchase_order_id=` from URL and pre-fills partner, currency, exchange_rate, pricing_mode from the PO.

`mutateFormDataBeforeCreate()` generates `internal_number` from the default SupplierInvoice `NumberSeries`.

#### SupplierInvoiceItemsRelationManager

- `isReadOnly()` returns `true` when invoice is confirmed
- **"Import from PO" header action** — visible when SI has a linked PO; bulk-creates items from all PO lines (using `purchase_order_item_id`, variant, quantity, unit_price, VAT); skips already-imported PO items (idempotent); calls `SupplierInvoiceService::recalculateItemTotals()` per item and `recalculateDocumentTotals()` after
- `product_variant_id` — **Nullable** (free-text lines without a product are allowed); when SI linked to PO, dropdown filtered to variants present on the PO only
- `purchase_order_item_id` — Optional PO line link; visible when SI has a linked PO; `afterStateUpdated` auto-fills variant, quantity, unit_price, VAT, description
- `description` — Required (not null; must be supplied for free-text lines)
- `quantity`, `unit_price`, `discount_percent`, `vat_rate_id`
- Computed: `vat_amount`, `line_total`, `line_total_with_vat` (disabled)
- `after()` hooks on Create/Edit/Delete call `SupplierInvoiceService::recalculateItemTotals()` and `recalculateDocumentTotals()`

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

## 5c. Admin Panel: Sales Group

All Sales group resources use `NavigationGroup::Sales` for `$navigationGroup`.

### 5c.1 QuotationResource

**Model:** `App\Models\Quotation`
**Navigation Sort:** 1
**Record Title Attribute:** `quotation_number`
**Pages:** List, Create, View, Edit

#### Form Schema (QuotationForm)

**Quotation Section:**
- `partner_id` — Select from active customers (`Partner::customers()->where('is_active', true)`), required
- `document_series_id` — Optional
- `quotation_number` — Auto-filled from `SeriesType::Quote` series; disabled + dehydrated
- `pricing_mode` — Select VatExclusive/VatInclusive, required
- `currency_code`, `exchange_rate`
- `issued_at` — DatePicker, required
- `valid_until` — DatePicker, optional
- `notes`, `internal_notes`

#### Table Schema (QuotationsTable)

**Columns:** quotation_number (searchable), partner.name, status (badge), total, valid_until, issued_at (default sort desc)

**Filters:** SelectFilter status (QuotationStatus), TrashedFilter

#### View Page Header Actions

- **Edit** — visible when `isEditable()`
- **Mark as Sent** — Draft → Sent
- **Accept** — Sent → Accepted
- **Reject** — Sent → Rejected
- **Convert to Sales Order** — Accepted only; opens warehouse picker modal; calls `QuotationService::convertToSalesOrder()`; redirects to the new SO
- **Cancel**
- **Print as Offer** (PDF) — visible when Sent; streams `pdf/quotation-offer.blade.php` via DomPDF
- **Print as Proforma Invoice** (PDF) — visible when Sent or Accepted; streams `pdf/quotation-proforma.blade.php`

#### QuotationItemsRelationManager

- `product_variant_id` — Select with `afterStateUpdated` auto-filling `unit_price` from variant `sale_price`
- `quantity`, `unit_price`, `discount_percent`, `vat_rate_id`
- Computed: `line_total`, `line_total_with_vat` (disabled)
- `after()` hooks call `QuotationService::recalculateItemTotals()` and `recalculateDocumentTotals()`

---

### 5c.2 SalesOrderResource

**Model:** `App\Models\SalesOrder`
**Navigation Sort:** 2
**Record Title Attribute:** `so_number`
**Pages:** List, Create, View, Edit

#### Form Schema (SalesOrderForm)

**Sales Order Section:**
- `partner_id` — Select from active customers, required
- `warehouse_id` — **Required** (needed for stock reservation)
- `quotation_id` — Optional; live; `afterStateUpdated` auto-fills currency/pricing_mode from the quotation
- `so_number` — Auto-filled from `SeriesType::SalesOrder` series; disabled + dehydrated
- `pricing_mode`, `currency_code`, `exchange_rate`
- `issued_at`, `expected_delivery_date`
- `notes`, `internal_notes`

#### Table Schema (SalesOrdersTable)

**Columns:** so_number (searchable), partner.name, status (badge), total, expected_delivery_date, issued_at

**Filters:** SelectFilter status (SalesOrderStatus), TrashedFilter

#### View Page Header Actions

- **Edit** — visible when `isEditable()`
- **Confirm Order** — Draft → Confirmed; triggers `SalesOrderService::reserveAllItems()` (stock reservation)
- **Create Delivery Note** — URL link to DeliveryNotes create page with `?sales_order_id=` pre-fill; visible when Confirmed/PartiallyDelivered
- **Create Invoice** — URL link to CustomerInvoices create page with `?sales_order_id=` pre-fill; visible when Confirmed/PartiallyDelivered/Delivered
- **Import to PO** — Modal with supplier select + optional existing Draft PO; creates PO items with `sales_order_item_id` linkage; does not advance PO status
- **Cancel** — Modal warns about draft DN/invoice cascade and unreservation; calls `SalesOrderService::transitionStatus(Cancelled)`

#### Mount Behaviour

`CreateSalesOrder::mount()` reads `?quotation_id=` from URL query string and pre-fills partner, currency, exchange_rate, pricing_mode, warehouse from the quotation (used by Quotation → "Convert to SO" action).

#### SalesOrderItemsRelationManager

- `product_variant_id` — Select with `afterStateUpdated` auto-filling `unit_price` from variant `sale_price`
- `qty_delivered`, `qty_invoiced` — Read-only progress columns (disabled)
- Computed: `line_total`, `line_total_with_vat` (disabled)
- `after()` hooks call `SalesOrderService::recalculateItemTotals()` and `recalculateDocumentTotals()`

---

### 5c.3 DeliveryNoteResource

**Model:** `App\Models\DeliveryNote`
**Navigation Sort:** 3
**Navigation Label:** `'Delivery Notes'`
**Record Title Attribute:** `dn_number`
**Pages:** List, Create, View, Edit (Edit redirects to View if DN is confirmed)

#### Form Schema (DeliveryNoteForm)

- `sales_order_id` — Optional, live; `afterStateUpdated` auto-fills `partner_id` and `warehouse_id` from the SO; options filtered to Confirmed + PartiallyDelivered SOs only
- `partner_id` — Required, select from active customers; disabled (read-only) when `sales_order_id` is set
- `warehouse_id` — **Required** (stock must be issued from a specific warehouse)
- `document_series_id` — Optional
- `dn_number` — Auto-filled from `SeriesType::DeliveryNote` series; disabled + dehydrated
- `delivered_at` — DatePicker, default today
- `notes`

#### Table Schema (DeliveryNotesTable)

**Columns:** dn_number (searchable), partner.name, warehouse.name, status (badge), delivered_at, salesOrder.so_number (toggleable, hidden by default)

**Filters:** SelectFilter status (DeliveryNoteStatus), TrashedFilter

#### View Page Header Actions

- **Edit** — visible when `isEditable()`
- **Confirm Delivery** — calls `DeliveryNoteService::confirm()`; irreversible; catches both `InvalidArgumentException` and `InsufficientStockException` and shows danger notifications
- **Print Delivery Note** (PDF) — visible when Confirmed; streams `pdf/delivery-note.blade.php` via DomPDF
- **Create Sales Return** — URL link to SalesReturns create page with `?delivery_note_id=` pre-fill; visible when Confirmed (placeholder until 3.2.8)
- **Cancel** — visible for Draft only; calls `DeliveryNoteService::cancel()`

#### Mount Behaviour

`CreateDeliveryNote::mount()` reads `?sales_order_id=` from URL query string and pre-fills `sales_order_id`, `partner_id`, `warehouse_id`, `delivered_at` (used by SO → "Create Delivery Note" action).

#### DeliveryNoteItemsRelationManager

- `isReadOnly()` returns `true` when DN is confirmed (items immutable)
- **"Import from SO" header action** — visible when DN has a linked SO; bulk-creates items from all SO lines with `remainingDeliverableQuantity() > 0`; auto-fills `sales_order_item_id`, `product_variant_id`, quantity, unit_cost
- `sales_order_item_id` — Optional; when SO-linked, dropdown shows remaining-deliverable SO items; `afterStateUpdated` auto-fills variant, quantity, unit_cost
- `product_variant_id` — Required; visible when no SO is linked
- `quantity`, `unit_cost` — Required (decimal)

---

### 5c.4 CustomerInvoiceResource

**Model:** `App\Models\CustomerInvoice`
**Navigation Sort:** 4
**Record Title Attribute:** `invoice_number`
**Pages:** List, Create, View, Edit (Edit redirects to View if Confirmed/Cancelled)

#### Form Schema (CustomerInvoiceForm)

- `invoice_number` — Auto-filled from `SeriesType::Invoice` series; disabled + dehydrated
- `invoice_type` — Select: Standard / Advance (default: Standard)
- `partner_id` — Required, active customers only; disabled (read-only) when `sales_order_id` is set
- `sales_order_id` — Optional, live; `afterStateUpdated` auto-fills partner, currency, exchange_rate, pricing_mode from SO; options filtered to Confirmed + PartiallyDelivered + Delivered SOs
- `is_reverse_charge` — Toggle; disabled + dehydrated (manual-only; no auto-detection)
- `pricing_mode` — disabled when SO-linked
- `currency_code` — disabled when SO-linked; `afterStateUpdated` uses `CurrencyRateService::makeAfterCurrencyChanged('issued_at')`
- `exchange_rate` — `CurrencyRateService::makeSaveRateAction('issued_at')`
- `issued_at` — Required, default today; `afterStateUpdated` uses `CurrencyRateService::makeAfterDateChanged()`
- `due_date` — Optional
- `payment_method` — Optional; triggers `FiscalReceiptRequested` on Cash when confirmed
- `notes`, `internal_notes`

#### Table Schema (CustomerInvoicesTable)

**Columns:** invoice_number (searchable), partner.name, status (badge), total, amount_due, issued_at, due_date (toggleable)

**Filters:** SelectFilter status (DocumentStatus), TrashedFilter

#### View Page Header Actions

- **Edit** — visible when `isEditable()`
- **Confirm Invoice** — Draft → Confirmed; calls `CustomerInvoiceService::confirm()`
- **Print Invoice** (PDF) — visible when Confirmed; streams `pdf/customer-invoice.blade.php` via DomPDF
- **Create Credit Note** — visible when Confirmed; URL link to CustomerCreditNote create page with `?customer_invoice_id=`
- **Create Debit Note** — visible when Confirmed; URL link to CustomerDebitNote create page with `?customer_invoice_id=`
- **Cancel** — visible for Draft and Confirmed; sets status to Cancelled

#### Related Documents Panel

Shows: linked SalesOrder, CustomerCreditNotes, CustomerDebitNotes

#### CustomerInvoiceItemsRelationManager

- `isReadOnly()` returns `true` when invoice is not editable
- **"Import from SO" header action** — visible when invoice has a linked SO; bulk-creates items from all SO lines with `remainingInvoiceableQuantity() > 0`; auto-fills `sales_order_item_id`, variant, quantity, unit_price
- `sales_order_item_id` — Optional; when SO-linked, dropdown shows remaining-invoiceable SO items; `afterStateUpdated` auto-fills variant, quantity, unit_price
- `product_variant_id` — Required; visible when no SO is linked
- `after()` hooks call `CustomerInvoiceService::recalculateItemTotals()` and `recalculateDocumentTotals()`

---

### 5c.5 CustomerCreditNoteResource

**Model:** `App\Models\CustomerCreditNote`
**Navigation Sort:** 5
**Record Title Attribute:** `credit_note_number`
**Pages:** List, Create, View, Edit (Edit redirects to View if not Draft)

#### Form Schema (CustomerCreditNoteForm)

- `credit_note_number` — Auto-filled from `SeriesType::CreditNote` series; disabled + dehydrated
- `customer_invoice_id` — Required; options filtered to Confirmed invoices for the selected partner
- `partner_id` — Required, active customers; auto-filled from invoice when invoice is selected
- `pricing_mode` — Copied from linked invoice
- `currency_code`, `exchange_rate`, `issued_at`, `reason`, `reason_description`

#### Table Schema (CustomerCreditNotesTable)

**Columns:** credit_note_number (searchable), partner.name, status (badge), total, issued_at

**Filters:** SelectFilter status (DocumentStatus), TrashedFilter

#### View Page Header Actions

- **Edit** — visible when `isEditable()`
- **Confirm Credit Note** — Draft → Confirmed
- **Cancel** — visible for Draft and Confirmed

#### Related Documents Panel

Shows: linked CustomerInvoice

#### CustomerCreditNoteItemsRelationManager

- `isReadOnly()` returns `true` when credit note is not editable
- `customer_invoice_item_id` — Required; dropdown shows items from the linked invoice with `remainingCreditableQuantity() > 0`; `afterStateUpdated` auto-fills variant, unit_price
- `quantity` — validated client-side and server-side against `remainingCreditableQuantity()`
- `after()` hooks call `CustomerCreditNoteService::recalculateItemTotals()` and `recalculateDocumentTotals()`

---

### 5c.6 CustomerDebitNoteResource

**Model:** `App\Models\CustomerDebitNote`
**Navigation Sort:** 6
**Record Title Attribute:** `debit_note_number`
**Pages:** List, Create, View, Edit (Edit redirects to View if not Draft)

#### Form Schema (CustomerDebitNoteForm)

- `debit_note_number` — Auto-filled from `SeriesType::DebitNote` series; disabled + dehydrated
- `customer_invoice_id` — Optional; links to a Confirmed invoice
- `partner_id` — Required, active customers; auto-filled from invoice when selected
- `pricing_mode`, `currency_code`, `exchange_rate`, `issued_at`, `reason`, `reason_description`

#### Table Schema (CustomerDebitNotesTable)

**Columns:** debit_note_number (searchable), partner.name, status (badge), total, issued_at

**Filters:** SelectFilter status (DocumentStatus), TrashedFilter

#### View Page Header Actions

- **Edit** — visible when `isEditable()`
- **Confirm Debit Note** — Draft → Confirmed
- **Cancel** — visible for Draft and Confirmed

#### Related Documents Panel

Shows: linked CustomerInvoice (if present)

#### CustomerDebitNoteItemsRelationManager

- `isReadOnly()` returns `true` when debit note is not editable
- Free-form items: `customer_invoice_item_id` optional, `product_variant_id` optional — no quantity constraints
- `after()` hooks call `CustomerDebitNoteService::recalculateItemTotals()` and `recalculateDocumentTotals()`

---

### 5c.7 SalesReturnResource

**Model:** `App\Models\SalesReturn`
**Navigation Sort:** 7
**Record Title Attribute:** `sr_number`
**Pages:** List, Create, View, Edit (Edit redirects to View if not Draft)

#### Form Schema (SalesReturnForm)

- `sr_number` — Auto-filled from `SeriesType::SalesReturn` series; disabled + dehydrated
- `delivery_note_id` — Optional, live; `afterStateUpdated` auto-fills `partner_id` and `warehouse_id` from the DN; options filtered to Confirmed DNs
- `partner_id` — Required, active customers; disabled (read-only) when DN is selected
- `warehouse_id` — Required; disabled (read-only) when DN is selected
- `returned_at` — DatePicker, default today
- `reason` — Textarea

#### Table Schema (SalesReturnsTable)

**Columns:** sr_number (searchable), partner.name, warehouse.name, status (badge), returned_at

**Filters:** SelectFilter status (SalesReturnStatus), TrashedFilter

#### View Page Header Actions

- **Edit** — visible when `isEditable()`
- **Confirm Return** — Draft → Confirmed; calls `SalesReturnService::confirm()` which receives all items back into stock via `StockService::receive()` (creates `StockMovement::SalesReturn` entries); irreversible; shows danger notification on `InvalidArgumentException`
- **Create Credit Note** — visible when Confirmed AND linked SO has at least one Confirmed invoice; shows modal to select which invoice to credit; redirects to CustomerCreditNote create page with `?customer_invoice_id=`
- **Cancel** — Draft only; calls `SalesReturnService::cancel()`

#### Related Documents Panel

Shows: linked DeliveryNote

#### SalesReturnItemsRelationManager

- `isReadOnly()` returns `true` when return is confirmed
- **"Import from DN" header action** — visible when SR has a linked DN; bulk-creates items from DN lines
- `delivery_note_item_id` — Optional; when DN-linked, dropdown shows DN items
- `product_variant_id`, `quantity`, `unit_cost` — Required

---

### 5c.8 AdvancePaymentResource

**Model:** `App\Models\AdvancePayment`
**Navigation Sort:** 8
**Record Title Attribute:** `ap_number`
**Pages:** List, Create, View, Edit (Edit redirects to View if not Open)
**No items relation manager** — single-amount document

#### Form Schema (AdvancePaymentForm)

- `ap_number` — Auto-filled from `SeriesType::AdvancePayment` series; disabled + dehydrated
- `sales_order_id` — Optional, live; `afterStateUpdated` auto-fills partner, currency, exchange_rate from SO
- `partner_id` — Required, active customers; disabled (read-only) when SO is selected
- `amount` — Required, decimal
- `payment_method` — Optional; Cash triggers `FiscalReceiptRequested` when advance invoice is issued
- `currency_code` — `afterStateUpdated` uses `CurrencyRateService::makeAfterCurrencyChanged('received_at')`
- `exchange_rate` — `CurrencyRateService::makeSaveRateAction('received_at')`
- `received_at` — DatePicker; `afterStateUpdated` uses `CurrencyRateService::makeAfterDateChanged()`

#### Table Schema (AdvancePaymentsTable)

**Columns:** ap_number (searchable), partner.name, status (badge), amount, amount_applied, remaining (computed via `remainingAmount()`), received_at

**Filters:** SelectFilter status (AdvancePaymentStatus), TrashedFilter

#### View Page Header Actions

- **Edit** — visible when `isEditable()` (Open status only)
- **Issue Advance Invoice** — visible when no advance invoice exists and status is Open/PartiallyApplied; calls `AdvancePaymentService::createAdvanceInvoice()` which creates a Confirmed CustomerInvoice of type Advance with a single line item; dispatches `FiscalReceiptRequested` on Cash; redirects to new invoice
- **Apply to Invoice** — visible when advance invoice exists and status is Open/PartiallyApplied; modal with invoice selector + amount input; calls `AdvancePaymentService::applyToFinalInvoice()` which adds a negative deduction row on the final invoice (qty=-1, same VAT rate as advance invoice item) and updates `amount_applied`; transitions to PartiallyApplied / FullyApplied
- **Refund** — visible for Open/PartiallyApplied; calls `AdvancePaymentService::refund()` → Refunded

#### Related Documents Panel

Shows: linked Advance Invoice (CustomerInvoice), Applied-to Invoices (via AdvancePaymentApplications), linked SalesOrder

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

---

## 8. Admin Panel: Company Settings Page

**Path:** `/admin/company-settings`
**Class:** `App\Filament\Pages\CompanySettingsPage`

A multi-tab settings page (not a resource) for tenant-level configuration. Settings are stored via `App\Models\CompanySettings` using the `CompanySettings::get(group, key, default)` / `CompanySettings::set(group, key, value)` API.

### Tabs

#### General
- `general.company_name` — Company name
- `general.company_address`, `general.company_city`, `general.company_postal_code`, `general.company_country`

#### Invoicing
- `invoicing.default_payment_terms` — Numeric (days)
- `invoicing.invoice_footer_text` — Textarea

#### Purchasing
- `purchasing.express_purchasing` — Toggle (default: **off**)
  - When **on**: "Confirm & Receive" button appears on `ViewSupplierInvoice` alongside the regular "Confirm" button, enabling one-click SI confirmation + GRN creation + stock receipt.
  - When **off**: SI confirmation flow is standard (confirm only, no GRN created).
  - Intended for micro-businesses where one person handles purchasing, receiving, and accounting.
  - Keep **off** for businesses with separation of duties (purchasing manager, warehouse, accountant).

#### Fiscal
- Bulgarian SUPTO/NRA fiscal printer settings (future Phase 3.3)
