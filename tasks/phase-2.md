# Phase 2 — Warehouse/WMS + Nomenclature/Catalog

> **Scope:** Tenant-side only. Landlord panel is feature-complete.  
> **Starting point:** 232/232 tests passing. Phase 1 landlord + SaaS layer fully hardened.

---

## Confirmed Decisions

| # | Decision |
|---|----------|
| 1 | Product variants **in Phase 2** (Task 2.1b) |
| 2 | Categories: **adjacency list, max 3 levels deep** — enforced at model level |
| 3 | Negative stock: **throw** (`InsufficientStockException`) |
| 4 | Opening balances: **TBD** — to be discussed separately |
| 5 | Barcode scanning: **in scope** (Task 2.7), browser-based via BarcodeDetector API |

---

## Task 2.1 — Nomenclature / Product Catalog

The catalog is the master list of products and services the tenant buys and sells.

### Models

**`Category`** — Hierarchical product categories (max 3 levels)
- `name`, `slug`, `parent_id` (self-referential, nullable), `description`, `is_active`
- Adjacency list — no nested set package needed
- Depth enforced in model: `beforeSave()` walks the parent chain; throws if depth > 3
- Scopes: `roots()`, `topLevel()`, `withChildren()`
- Helper: `depthLevel(): int` (0 = root, 1 = child, 2 = grandchild)

**`Unit`** — Units of measure
- `name`, `symbol`, `type` enum: `Mass | Volume | Length | Area | Time | Piece | Other`
- Seeded via `UnitSeeder`

**`Product`** — Goods and services
- `code` (unique per tenant), `name`, `description`
- `type` enum: `Good | Service | Bundle`
- `category_id`, `unit_id`
- `purchase_price` decimal(19,4), `sale_price` decimal(19,4)
- `vat_rate_id`
- `is_active`, `is_stockable` (false for services by default)
- `barcode` varchar(128), nullable — stores raw barcode value (EAN-13, QR, Code128, etc.)
- JSON `attributes` for flexible extra fields
- Has many `ProductVariant`

### Task 2.1b — Product Variants

**`ProductVariant`** — Named variants of a Product (size, color, material, etc.)
- `product_id`, `name` (e.g. "Red / L"), `sku` (unique per tenant)
- `purchase_price` decimal(19,4) nullable (falls back to product price if null)
- `sale_price` decimal(19,4) nullable
- `barcode` varchar(128) nullable
- `is_active`
- `attributes` JSON (e.g. `{"color": "red", "size": "L"}`)
- Has one `StockItem` per warehouse (variants are tracked separately from the parent product)

**Rules:**
- A product with variants is stocked at the variant level, not the product level
- `Product::hasVariants(): bool` — true when any active variant exists
- `StockService` accepts either a `Product` (no-variant) or `ProductVariant` as the stockable

### Filament Resources (new "Catalog" navigation group)

- `CategoryResource` — table with parent column; form has parent select filtered to exclude self + descendants; depth validation message on save
- `UnitResource` — simple CRUD
- `ProductResource`
  - Table: code, name badge (type color), category, sale price, VAT rate, stock badge, active toggle
  - Form: General section + Pricing & Tax section + Stockable toggle
  - `ProductVariantsRelationManager` — inline CRUD for variants with own SKU/price/barcode columns

### Policies

`CategoryPolicy`, `UnitPolicy`, `ProductPolicy`, `ProductVariantPolicy` — following existing pattern, permissions added to `RolesAndPermissionsSeeder`

---

## Task 2.2 — Warehouse / Stock Locations

**`Warehouse`**
- `name`, `code` (unique per tenant), `address` (JSON: street, city, country), `is_active`, `is_default`
- Only one warehouse can be `is_default = true` — enforced via model event

**`StockLocation`** — bin/shelf/zone within a warehouse
- `warehouse_id`, `name`, `code`, `is_active`

### Filament Resources (new "Warehouse" navigation group)

- `WarehouseResource` with `StockLocationsRelationManager`

### Policies

`WarehousePolicy`, `StockLocationPolicy`

---

## Task 2.3 — Stock / Inventory

**`StockItem`** — current stock level per stockable per warehouse (+ optional location)
- `stockable_type`, `stockable_id` (polymorphic: Product or ProductVariant)
- `warehouse_id`, `stock_location_id` (nullable)
- `quantity` decimal(15,4), `reserved_quantity` decimal(15,4)
- Computed: `available_quantity = quantity − reserved_quantity`
- Unique constraint: `(stockable_type, stockable_id, warehouse_id, stock_location_id)`

**`StockMovement`** — immutable audit log of every stock change
- `stockable_type`, `stockable_id`
- `warehouse_id`, `stock_location_id`
- `type` enum: `Receipt | Issue | Adjustment | Transfer | Return | Opening`
- `quantity` decimal(15,4) — signed: positive = in, negative = out
- `reference_type`, `reference_id` (polymorphic — links to future Invoice/PO/etc.)
- `notes`, `moved_at` (default: now), `moved_by` (user_id from auth)

**`InsufficientStockException`** — thrown by `StockService::issue()` when available_quantity < requested qty

**`StockService`** — single class for all stock mutations; never update `StockItem` directly

```
receive(stockable, warehouse, qty, location?, reference?)  → StockItem, StockMovement(Receipt)
issue(stockable, warehouse, qty, location?, reference?)    → StockItem, StockMovement(Issue) — throws InsufficientStockException
adjust(stockable, warehouse, qty, reason, location?)       → StockItem, StockMovement(Adjustment)
transfer(stockable, fromWarehouse, toWarehouse, qty)       → paired Issue + Receipt movements
```

All mutations wrapped in `DB::transaction()`.

### Filament Resources / Pages (Warehouse group)

- `StockItemResource` — read-only, filterable by warehouse/category/stockable type
- `StockMovementResource` — read-only audit log, filterable by type/date/stockable
- `StockAdjustmentPage` — action page: pick product/variant + warehouse + qty + reason; admin/warehouse-manager only

---

## Task 2.4 — RBAC Extensions

Add to `RolesAndPermissionsSeeder` (models):
```
category, unit, product, product_variant, warehouse, stock_location, stock_item, stock_movement
```

Role updates:
- `admin` — full access to all Phase 2 models
- `warehouse-manager` — CRUD warehouse, stock locations, stock movements, adjustments; view products/categories
- `sales-manager` — view catalog + stock levels (view_any only)
- `viewer` — view all Phase 2 models

---

## Task 2.5 — Seeders & Factories

- `UnitSeeder` — pcs, kg, g, t, l, ml, m, cm, mm, m², h, day, month
- `CategoryFactory`, `UnitFactory`, `ProductFactory`, `ProductVariantFactory`
- `WarehouseFactory`, `StockLocationFactory`, `StockItemFactory`
- `TenantOnboardingService` — call `UnitSeeder` alongside existing seeders
- Add default warehouse creation to onboarding ("Main Warehouse", `is_default = true`)

---

## Task 2.6 — Tests

- `CategoryTest` — nesting (3 levels pass, 4th throws), depth helpers, root/child scopes
- `ProductCatalogTest` — CRUD, variants, stockable flag, VAT association, barcode field
- `StockServiceTest` — receive, issue (success + InsufficientStockException), adjust, transfer
- `StockMovementTest` — immutability, polymorphic stockable, reference links
- `WarehouseTest` — warehouse CRUD, single-default enforcement, stock location CRUD
- `CatalogPolicyTest` — Category, Unit, Product, ProductVariant permission checks
- `WarehousePolicyTest` — Warehouse, StockLocation, StockItem permission checks

---

## Task 2.7 — Barcode Scanning

Browser-based scanning via the **BarcodeDetector API** (Chrome/Edge/Android WebView).

**Scope:**
- Scan-to-find: scan a barcode on the StockAdjustmentPage to auto-fill the product/variant picker
- Scan-to-find on ProductResource table: quick search by barcode
- Graceful fallback: manual text input when BarcodeDetector is unavailable (iOS Safari, Firefox)

**Implementation:**
- Alpine.js component: `x-data="barcodeScanner()"` — opens camera, fires `barcode-detected` event with the decoded value
- Livewire listens for `barcode-detected` → sets the product/variant field
- No external library needed — BarcodeDetector is a native browser API
- Feature-detect on mount: hide camera button if `!('BarcodeDetector' in window)`

---

## Checklist

| Task | Status |
|------|--------|
| 2.1 — Category, Unit, Product models + resources | ⬜ |
| 2.1b — Product Variants | ⬜ |
| 2.2 — Warehouse + Stock Locations | ⬜ |
| 2.3 — Stock / Inventory (StockItem, StockMovement, StockService) | ⬜ |
| 2.4 — RBAC Extensions | ⬜ |
| 2.5 — Seeders & Factories | ⬜ |
| 2.6 — Tests | ⬜ |
| 2.7 — Barcode Scanning | ⬜ |

---

## Deferred / Open

- **Opening balances** — to be discussed. Options: (a) bulk CSV import, (b) manual StockAdjustment with type=Opening, (c) dedicated Opening Balance wizard. Defer until after 2.3 is built.
