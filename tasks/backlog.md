# Backlog — Unscheduled Improvements

Items identified during design review and brainstorming. Not yet scheduled to a phase.

> Items completed in Phase 2.5 (CATALOG-7, WAREHOUSE-1, WAREHOUSE-5, CORE-1) and Phase 3.1 (WAREHOUSE-4) have been removed — they are tracked in their respective phase task files.

---

## Easy — Quick wins, isolated changes

### WAREHOUSE-8: Warehouse — assign responsible person *(nav links + transfer done in Backlog Session 1)*

Add a responsible person field to warehouses. Depends on WAREHOUSE-7 (which adds `WarehouseType` enum and `assigned_to` FK to the `warehouses` table).

**Once WAREHOUSE-7 is done, add to WarehouseResource:**
- Form: show `assigned_to` user selector (Select, scoped to active users) when `type = Physical` or `type = Mobile`
  - For `Physical`: label "Responsible Person"
  - For `Mobile`: label "Assigned To (Technician)"
- Table: add "Responsible" column showing the user's name, placeholder `—`
- ViewWarehouse: show responsible person in the infolist/details section

**Model side (done in WAREHOUSE-7):**
- `assigned_to` nullable FK → `users` table already added by WAREHOUSE-7
- `warehouse->assignedTo()` BelongsTo relationship already added by WAREHOUSE-7

---

### WAREHOUSE-6: Stock Movements — export + summary *(filters + reference column done in Backlog Session 1)*

**Deferred — requires export package decision first (no package currently installed):**
- CSV/Excel export of movements for a date range — `ExportAction` in the toolbar, columns: date, type, product, SKU, warehouse, quantity, moved_by, reference
- Summary totals per type in a period — totals panel above the table (total received, total issued, net change) for the active filter range

**Note on export:** `barryvdh/laravel-dompdf` is installed but for PDFs only. Need either `maatwebsite/excel` (most common) or Filament's built-in ExportAction (check if available in Filament v5 without extra package). Decide and add to dependencies before implementing.

---

### WAREHOUSE-3: Stock Levels — export + low stock *(nav links + row actions done in Backlog Session 1)*

**Deferred — export (same package decision as WAREHOUSE-6):**
- Export to CSV/Excel — `ExportAction` in toolbar, columns: product, SKU, variant, warehouse, location, quantity, available_quantity

**Deferred — low stock threshold:**
- Add `reorder_point` nullable `decimal(15,4)` column to `stock_items` via migration
- `StockItem` model: add `is_low_stock` computed accessor (`quantity <= reorder_point && reorder_point !== null`)
- Table: add low stock badge/color on `quantity` column when `is_low_stock`
- Table filter: "Low stock only" TernaryFilter
- Company setting `catalog.low_stock_default_threshold` (optional — per-item reorder point takes precedence)

---

## Medium — Multi-file, some design needed

### CATALOG-1: Brands / Manufacturers resource
Add a `Brand` entity to the Catalog navigation group.

- Model: `Brand` — `name` (translatable), `description`, `is_active`, soft deletes
- Relationship: `Product` belongs to `Brand` (nullable FK `brand_id`)
- Filament resource: `BrandResource` under `NavigationGroup::Catalog`
- RBAC: add `brand` permissions to seeder and roles

---

### CATALOG-3: Category inheritable defaults
Categories carry default values for product attributes. Products inherit at creation time only (not live inheritance).

**Attributes stored on Category:**
- `vat_rate_id` (FK, nullable) — default VAT rate for products in this category
- `unit_id` (FK, nullable) — default unit of measure

**Nested inheritance:**
- Child category inherits from parent if its own value is null
- Resolution order at product creation: Product form → Category value → Parent Category value → null
- Resolved defaults are copied onto the product; product then owns its values independently

---

### CATALOG-6: Auto-generated product codes via NumberSeries
Product codes auto-generated from a configurable series, per `ProductType`.

- Reuses the `NumberSeries` model with `SeriesType::Product`
- Configured per ProductType (Stock, Service, Bundle get separate series if desired)
- Auto-generates on product creation; user can always manually override
- Company setting `product_code_auto` (default: `true`) — when `false`, code field is fully manual
- Depends on CATALOG-3 for category-driven VAT/unit auto-fill on product form

---

### WAREHOUSE-7: Warehouse type enum + virtual warehouses
**Add `WarehouseType` enum:**
- `Physical` — standard warehouse with an address
- `Mobile` — assigned to a person/vehicle (e.g. technician's van)
- `Consignment` — stock at a partner/customer premises
- `InTransit` — system-managed, holds stock between TransferOut and TransferIn

**Model changes:**
- Add `type` → `WarehouseType` enum, default `Physical`
- Add `assigned_to` (nullable FK → users) — for `Mobile` type
- Add `partner_id` (nullable FK → partners) — for `Consignment` type
- Address fields become optional for non-Physical types

**Phase 4 connection:** `Mobile` warehouse is the foundation of the Field Service module.

---

## Complex / Design-heavy

### CATALOG-4: Category "force cascade" action
An explicit bulk-update action on a Category record that pushes attribute values down to all children and products in the entire subtree.

**Behavior:**
- Triggered from the Category view/edit page
- User selects which attributes to cascade: VAT rate, unit, or all
- Confirmation modal shows affected product/subcategory counts and which attributes will be overwritten
- On confirm: unconditional bulk update — overwrites all, including prior manual overrides
- Runs in a DB transaction; consider queuing for large subtrees

**Depends on:** CATALOG-3 (category inheritable defaults must exist first)

---

### WAREHOUSE-2: Formal inventory audit (инвентаризация)
A legally compliant stocktake process required by Bulgarian and EU accounting law.

**Process flow:**
1. Management issues an audit order — authorized by CEO/manager role
2. Committee members designated and recorded
3. Count sheet generated — expected quantities vs physically counted
4. Committee physically counts and submits results
5. Discrepancies reviewed and approved
6. On approval: `StockService::adjust()` called for each discrepancy
7. Protocol document generated — signed by all committee members

**Notes:**
- No quantity changes without a completed, approved audit record
- Full audit trail — immutable once approved
- Design as a multi-step wizard or dedicated resource with status workflow

---

## No priority

### PURCHASES-ARCHIVE: Archive purchase documents ⚠️ NEEDS TRIAGE

Mark purchase documents (PO, GRN, SI, SCN, PR) as archived — read-only for historical and statistical purposes. Not a soft delete. Archived records remain visible, searchable, and reportable but all mutating actions are hidden.

**Open design questions before implementing:**
1. **Reversible?** Should an "Unarchive" action exist? "Cannot be undone" is a strong choice — a misclick strands the document permanently.
2. **Linked documents:** If a PO is archived but its GRN is still Draft, can the GRN still be confirmed? `GoodsReceiptService` would write back to the archived PO via `updateReceivedQuantities()`. Should archiving be blocked unless the document is in a terminal state (Received/Cancelled)?
3. **Service-layer guard:** UI-only hiding is not enough — service calls (tinker, future API) bypass it. `isEditable()` on models must also check `archived_at`, or archiving is cosmetic only.
4. **Financial documents:** Archiving an SI with outstanding `amount_due` — does it imply the debt is settled? Probably not, but needs to be stated explicitly.

**Suggested answers (not decided):** guard at service layer; reversible; only allow archive on terminal-status documents; financial state unaffected.

**Scope:** PurchaseOrder, GoodsReceivedNote, SupplierInvoice, SupplierCreditNote, PurchaseReturn.

**Migration (per model):**
- Add `archived_at` nullable timestamp column
- Add `archived_by` nullable FK → `users` (records who archived it)

**Model side:**
- Add `archived_at`, `archived_by` to `$fillable`
- Add `archivedBy()` BelongsTo relationship
- Add `isArchived(): bool` helper (`return $this->archived_at !== null`)
- Existing `isEditable()` methods already gate UI — extend them: archived records are never editable regardless of status

**Filament — per resource:**
- Add "Archive" header action on View page — visible when `! $record->isArchived()`, requires confirmation modal ("This document will become read-only. This cannot be undone.")
- Action sets `archived_at = now()`, `archived_by = auth()->id()`; sends success notification
- All other header actions (Edit, Send, Confirm, Cancel, Create GRN, etc.) get `->visible(fn () => ! $record->isArchived())` — hidden on archived records
- Table: add `archived_at` column (toggleable, hidden by default) + `TernaryFilter` to show/hide archived records (default: exclude archived)
- Archived badge: show a subtle "Archived" badge on the View page header when `isArchived()`

**What is NOT changed:**
- No `deleted_at` — record stays fully accessible
- No RBAC changes — any role that can view can also see archived records
- No cascade — archiving a PO does not auto-archive its GRNs or SIs (each document archived independently)

**Future consideration:** bulk archive action for closing out a financial period.

---

### CATALOG-8: Unit conversions
Allow a unit to define conversion ratios to other units (e.g. 1 pallet = 120 pieces).

- No priority — implement when a concrete use case requires it
