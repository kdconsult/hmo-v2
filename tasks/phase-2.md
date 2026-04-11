# Phase 2 — Warehouse/WMS + Nomenclature/Catalog

> **Scope:** Tenant-side only. Landlord panel is feature-complete.  
> **Starting point:** 232/232 tests passing. Phase 1 landlord + SaaS layer fully hardened.

---

## Context & Goals

Phase 2 adds the product/service catalog and warehouse management foundation that all later phases (invoicing, purchasing, field service) will depend on. Every model built here lives in the **tenant database**.

---

## Task 2.1 — Nomenclature / Product Catalog

The catalog is the master list of products and services the tenant buys and sells.

### Models

**`Category`** — Hierarchical product categories
- `name`, `slug`, `parent_id` (self-referential), `description`, `is_active`
- Nested set or simple adjacency list (confirm with user)

**`Unit`** — Units of measure (kg, pcs, l, m², h, etc.)
- `name`, `symbol`, `type` (mass, volume, length, area, time, piece)
- Seeded with common EU units

**`Product`** (covers both goods and services)
- `code` (internal SKU/code, unique per tenant), `name`, `description`
- `type` enum: `Good | Service | Bundle`
- `category_id`, `unit_id`
- `purchase_price` decimal(19,4), `sale_price` decimal(19,4)
- `vat_rate_id`
- `is_active`, `is_stockable` (services and some goods are not stocked)
- `barcode` (EAN-13 / QR, optional)
- JSON `attributes` for flexible extra fields

**`ProductVariant`** (optional, Phase 2+) — size/color/etc. variants of a Product
- Defer if not needed immediately

### Filament Resources (tenant panel, CRM group or new "Catalog" group)

- `CategoryResource` — tree view or nested list
- `UnitResource` — simple CRUD
- `ProductResource` — full CRUD with variant support placeholder
  - Table: code, name, type badge, category, sale price, VAT rate, active toggle
  - Form: two sections (General + Pricing & Tax)
  - `ProductVariantsRelationManager` (stubbed if deferred)

### Policies

- `CategoryPolicy`, `UnitPolicy`, `ProductPolicy` — following existing pattern
- Permissions added to `RolesAndPermissionsSeeder`

---

## Task 2.2 — Warehouse / Stock Locations

**`Warehouse`**
- `name`, `code`, `address`, `is_active`, `is_default`

**`StockLocation`** (bin/shelf within a warehouse)
- `warehouse_id`, `name`, `code`, `is_active`

### Filament Resources

- `WarehouseResource` with `StockLocationsRelationManager`

---

## Task 2.3 — Stock / Inventory

**`StockItem`** — current stock level per product per location
- `product_id`, `warehouse_id`, `stock_location_id` (nullable)
- `quantity` decimal(15,4), `reserved_quantity` decimal(15,4)
- `available_quantity` (computed: quantity − reserved)
- Unique constraint: `(product_id, warehouse_id, stock_location_id)`

**`StockMovement`** — immutable audit log of every stock change
- `product_id`, `warehouse_id`, `stock_location_id`
- `type` enum: `Receipt | Issue | Adjustment | Transfer | Return | Opening`
- `quantity` (signed: positive = in, negative = out)
- `reference_type`, `reference_id` (polymorphic: links to future Invoice/PO/etc.)
- `notes`, `moved_at`, `moved_by` (user_id)

**`StockService`** — single service class for all stock mutations
- `receive(product, warehouse, qty, reference?)` — creates Receipt movement
- `issue(product, warehouse, qty, reference?)` — creates Issue movement, checks availability
- `adjust(product, warehouse, qty, reason)` — creates Adjustment movement
- `transfer(product, fromWarehouse, toWarehouse, qty)` — paired movements

All mutations go through `StockService`; never update `StockItem` directly.

### Filament Resources / Pages

- `StockItemResource` — read-only table (current stock levels), filterable by warehouse/category
- `StockMovementResource` — read-only audit log
- `StockAdjustmentPage` — simple action page for manual adjustments (admin/warehouse-manager only)

---

## Task 2.4 — RBAC Extensions

Add to `RolesAndPermissionsSeeder`:

```
category, unit, product, warehouse, stock_location, stock_item, stock_movement
```

Extend roles:
- `admin` — full access to all Phase 2 models
- `warehouse-manager` — CRUD warehouse, stock locations, stock movements; view products/categories
- `sales-manager` — view products/catalog, view stock levels
- `viewer` — view all

---

## Task 2.5 — Seeders & Factories

- `UnitSeeder` — seed common EU units (pcs, kg, g, l, ml, m, m², h, day)
- `CategoryFactory`, `UnitFactory`, `ProductFactory`
- `WarehouseFactory`, `StockItemFactory`
- `DatabaseSeeder` — call `UnitSeeder` from tenant onboarding

---

## Task 2.6 — Tests

- `ProductCatalogTest` — CRUD, category hierarchy, pricing, VAT rate association
- `StockMovementTest` — receive/issue/adjust/transfer, availability check, negative stock guard
- `WarehouseTest` — warehouse + location CRUD, default warehouse logic
- `CatalogPolicyTest` — permission checks for Category, Unit, Product
- `WarehousePolicyTest` — permission checks for Warehouse, StockLocation, StockItem

---

## Checklist

| Task | Status |
|------|--------|
| 2.1 — Nomenclature / Product Catalog | ⬜ |
| 2.2 — Warehouse / Stock Locations | ⬜ |
| 2.3 — Stock / Inventory | ⬜ |
| 2.4 — RBAC Extensions | ⬜ |
| 2.5 — Seeders & Factories | ⬜ |
| 2.6 — Tests | ⬜ |

---

## Open Questions (confirm before starting)

1. **Product variants** — do we need size/color variants in Phase 2, or stub and defer?
2. **Category structure** — adjacency list (simple) or nested set (complex queries, ordered tree)?
3. **Negative stock** — should `StockService::issue()` throw when stock goes below zero, or warn and allow?
4. **Opening balances** — is there an import/bulk-entry flow for initial stock, or manual adjustments only?
5. **Barcode scanning** — browser-based (BarcodeDetector API) or out of scope for Phase 2?
