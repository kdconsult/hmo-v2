# Backlog — Post-Phase 2 Improvements

Items identified during design review and brainstorming. Not yet scheduled to a phase.

---

## Catalog

### CATALOG-1: Brands / Manufacturers resource
Add a `Brand` (or `Manufacturer`) entity to the Catalog navigation group.

- Model: `Brand` — `name` (translatable), `description`, `is_active`, soft deletes
- Relationship: `Product` belongs to `Brand` (nullable FK `brand_id`)
- Filament resource: `BrandResource` under `NavigationGroup::Catalog`
- RBAC: add `brands` permissions to seeder and roles

---

### CATALOG-2: `require_product_category` company setting
Per-tenant toggle that forces every product to belong to a category.

- Setting group: `catalog`, key: `require_product_category`, default: `false`
- When enabled: `category_id` becomes required on the product form (validated server-side)
- Company Settings page: expose the toggle under a "Catalog" section
- Note: even when enabled, "Other" or a catch-all category is a valid assignment — the system should not prevent saving, just require *some* category

---

### CATALOG-3: Category inheritable defaults
Categories carry default values for product attributes. Products inherit at creation time (creation-time defaults only — not live inheritance).

**Attributes stored on Category:**
- `vat_rate_id` (FK, nullable) — default VAT rate for products in this category
- `unit_id` (FK, nullable) — default unit of measure
- *(more attributes may be added as the system grows)*

**Nested inheritance:**
- Child category inherits from parent if its own value is null
- Resolution order at product creation: Product form → Category value → Parent Category value → null
- Resolved defaults are copied onto the product; product then owns its values independently

---

### CATALOG-5: Product "Clone" action
Replicate a product into a new Create form with all fields pre-populated.

- Use Filament's built-in `ReplicateAction` on the ProductResource
- Clones: all product fields + variants
- Does NOT clone: stock items, stock movements
- Code field: duplicated as-is but unique validation will catch it — user must change it before saving
- Opens the Create form pre-populated (not a silent background clone)

---

### CATALOG-6: Auto-generated product codes via NumberSeries
Product codes auto-generated from a configurable series, per `ProductType`.

- Reuses the `NumberSeries` model (see CORE-1 below) with `SeriesType::Product`
- Configured per ProductType (Stock, Service, Bundle get separate series if desired)
- Format options: prefix, separator, year inclusion, padding, next number, yearly reset
- Auto-generates on product creation; user can always manually override
- Company setting `product_code_auto` (default: `true`) — when `false`, code field is fully manual
- Category parent selection on the product form auto-populates VAT rate and unit from the category (CATALOG-3)

---

### CATALOG-4: Category "force cascade" action
An explicit bulk-update action on a Category record that pushes selected attribute values down to all children and products in the entire subtree.

**Behavior:**
- Triggered from the Category view/edit page (an Action button)
- User selects which attributes to cascade: VAT rate, unit, or all
- Confirmation modal shows:
  - Number of direct products affected
  - Number of subcategories affected
  - Total products in entire subtree
  - Which attributes will be overwritten
- On confirm: unconditional bulk update — overwrites all, including prior manual overrides
- Runs in a DB transaction; consider queuing for large subtrees

**Design notes:**
- No `is_overridden` tracking flags — override is destructive by design, user is warned
- Escape hatch: cancel and update individual products manually
- Real-world use case: government VAT rate changes on product groups (e.g. bread, consumer basket items)

---

### CATALOG-7: Product status enum refactor ⚡ DO THIS PHASE
Replace `is_active` boolean on `Product` with a `ProductStatus` enum: `Draft`, `Active`, `Discontinued`.

**What changes:**
- New enum: `app/Enums/ProductStatus.php` — cases: `Draft`, `Active`, `Discontinued`
- Migration: drop `is_active` boolean, add `status` string/enum column, default `Active`
- `Product` model: replace `is_active` with `status` cast, update `scopeActive()` to filter `status = Active`, update `getActivitylogOptions()`
- `ProductForm.php`: replace `Toggle::make('is_active')` → `Select::make('status')->options(ProductStatus::class)`
- `ProductsTable.php`: replace `IconColumn::make('is_active')` → `TextColumn::make('status')->badge()`
- `ProductFactory` + tests: update accordingly
- `ProductVariant.is_active` stays as-is — variants are a separate concern

**Behavior notes:**
- `Draft` — not yet available for use on documents
- `Active` — normal, can be added to invoices/orders
- `Discontinued` — cannot be added to new documents, but remains on historical ones

---

### CATALOG-8: Unit conversions
Allow a unit to define conversion ratios to other units (e.g. 1 pallet = 120 pieces). Relevant for purchasing in bulk and selling individually.

- No priority — implement when a concrete use case requires it

---

## Warehouse

### WAREHOUSE-1: Remove StockAdjustmentPage ⚡ DO THIS PHASE
The current `StockAdjustmentPage` allows unrestricted stock quantity manipulation with no authorization, no approval flow, and no audit document. This is incorrect for a legally compliant ERP.

**Remove:**
- `app/Filament/Pages/StockAdjustmentPage.php`
- `resources/views/filament/pages/stock-adjustment-page.blade.php`
- Registration in `AdminPanelProvider`
- Any tests covering the page

**Keep:**
- `StockService::adjust()` — the underlying mechanism, will be used by the formal inventory audit process
- The `Adjustment` MovementType — still valid
- All stock movement permissions — will be reused

**Replace with (future — WAREHOUSE-2):**
A formal inventory audit (инвентаризация) process with authorization, committee members, count sheets, protocol document, and approval before any quantities are adjusted.

---

### WAREHOUSE-5: Add `created_by` to StockMovement ⚡ DO THIS PHASE
`StockMovement` records who caused a stock change at the type/reference level but not at the user level.

**Changes:**
- Add `created_by` FK (nullable) → `users` table on `stock_movements`
- `StockService` — set `created_by = auth()->id()` on every movement it creates
- Display in `StockMovementResource` table — "Created by" column
- Migration: add nullable `created_by` column (nullable to avoid breaking existing records)

---

### WAREHOUSE-6: Stock Movements — enrich with filters, export, reference links
**Filters:**
- By MovementType
- By date range
- By product / variant
- By warehouse
- By created_by (who)

**Reference link:**
- Once Phase 3 builds invoices and purchase orders, the `reference` morph column is already wired — clicking a `Sale` movement should navigate to the Invoice, `Purchase` to the Purchase Order

**Export:**
- CSV/Excel export of movements for a date range — required for accounting and audits

**Summary:**
- Totals per type in a period — total received, total issued, net change

---

### WAREHOUSE-3: Stock Levels — enrich with actions and navigation
Current `StockItemResource` is a read-only table with no actions or links — not useful enough.

**Navigation / Links:**
- Product name → links to product record
- Warehouse name → links to warehouse record
- Row click → filtered Stock Movements for that variant/warehouse combination

**Row actions:**
- "View movements" — opens Stock Movements filtered to that specific variant + warehouse
- "Transfer" — modal to move quantity to another warehouse (calls `StockService::transfer()`)

**Toolbar:**
- Export to CSV/Excel — essential for accountants, auditors, инвентаризация count sheets
- Low stock filter — show only items below a configurable threshold

**Enrichment:**
- Reorder point per `StockItem` — flag when quantity drops below threshold
- Low stock badge — visual indicator in the table

---

### WAREHOUSE-7: Warehouse type enum + virtual warehouses
Current `Warehouse` model has no type — all warehouses are implicitly physical. Business needs virtual/mobile/consignment warehouses.

**Add `WarehouseType` enum:**
- `Physical` — standard warehouse with an address
- `Mobile` — assigned to a person/vehicle (e.g. technician's van)
- `Consignment` — stock at a partner/customer premises
- `InTransit` — system-managed, holds stock between a TransferOut and TransferIn (auto-created per tenant on onboarding)

**Model changes:**
- Add `type` → `WarehouseType` enum, default `Physical`
- Add `assigned_to` (nullable FK → users) — for `Mobile` type
- Add `partner_id` (nullable FK → partners) — for `Consignment` type
- Address fields become optional (not meaningful for Mobile/InTransit)

**UI changes:**
- `WarehouseResource` form: show/hide fields based on type (address for Physical, assigned_to for Mobile, partner_id for Consignment)
- Table: type badge column

**Phase 4 connection:**
- `Mobile` warehouse is the foundation of the Field Service module — technician takes stock from main warehouse (TransferOut → van), uses on job (Sale from van), returns unused (Transfer back)

---

### WAREHOUSE-8: Warehouse — enrich with navigation and actions
**Navigation:**
- Warehouse row → shows current stock items for that warehouse
- Warehouse row → shows movement history for that warehouse

**Actions:**
- "Transfer stock" — initiate a transfer to another warehouse directly from the warehouse record
- Assign responsible person (for Physical warehouses)

---

### WAREHOUSE-4: Purchasing / Supply — Phase 3
Stock currently has no inbound flow from purchasing. `StockService::receive()` exists but nothing in the UI calls it.

**Missing entirely:**
- Supplier association per product (which supplier, at what price, lead time)
- Purchase Orders — formal request to supplier
- Goods Receipt — confirms delivery, triggers `StockService::receive()` per line
- Supplier price tracking vs sale price

**Note:** This is the purchasing side of Phase 3 and is a planned feature. Design separately when Phase 3 begins.

---

### WAREHOUSE-2: Formal inventory audit (инвентаризация)
A legally compliant stocktake process required by Bulgarian and EU accounting law.

**Process flow:**
1. Management issues an audit order (decree) — authorized by CEO/manager role
2. Committee members are designated and recorded
3. Count sheet generated — expected quantities vs physically counted
4. Committee physically counts and submits results
5. Discrepancies reviewed and approved
6. On approval: system calls `StockService::adjust()` for each discrepancy
7. Protocol document generated — signed by all committee members

**Notes:**
- No quantity changes happen without a completed, approved audit record
- Full audit trail — immutable once approved
- Design as a multi-step wizard or a dedicated resource with status workflow
- Not a Phase 3 blocker — design and implement when Warehouse module is formalized

---

## Core / Infrastructure

### CORE-1: Generalize DocumentSeries → NumberSeries
`DocumentSeries` is currently a placeholder (no invoices/orders yet). Generalize it before Phase 3 bakes in document-specific assumptions.

**Changes:**
- Rename model: `DocumentSeries` → `NumberSeries`
- Rename table: `document_series` → `number_series`
- Rename column: `document_type` → `series_type`
- Rename enum: `DocumentType` → `SeriesType` — expand cases: `Invoice`, `CreditNote`, `PurchaseOrder`, `Product`, `Partner`, etc.
- Update `getDefault()` to accept `SeriesType`
- Update `DocumentSeriesResource` → `NumberSeriesResource`, navigation label "Number Series"
- Zero logic change — `generateNumber()` and `formatNumber()` are already fully generic

**Why now:** Model is unused (Phase 3 not started). Zero migration pain, zero data loss risk. Doing it after Phase 3 would require touching invoice generation code.

---
