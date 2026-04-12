# Plan: Phase 3.1 — Purchases Module

## Context

Phase 2 (Catalog + Warehouse) and Phase 2.5 (Hardening) are complete. 293 tests pass. The Warehouse module has `StockService::receive()` ready but nothing in the UI calls it — no legitimate way to bring stock into the system through a proper document flow.

Phase 3.1 builds the **Purchases pipeline**: Purchase Order → Goods Received Note → Supplier Invoice → Supplier Credit Note. This gives businesses the full inbound flow: order from suppliers, receive goods (stock goes up via StockService), record supplier invoices, and handle returns/corrections via credit notes.

**Key adaptation from original spec:** The spec (`hmo-cuddly-watching-thompson.md`) uses `nomenclature_item_id` — our Phase 2 implementation uses `Product` + `ProductVariant` (always-variant pattern). All line items reference `product_variant_id`. Currency defaults are `EUR` (not BGN). Unit prices use `decimal(15,4)` (our convention, not spec's 19,4).

---

## Existing Building Blocks (DO NOT recreate)

| Component | Status | File |
|-----------|--------|------|
| `PurchaseOrderStatus` enum (6 cases) | Ready | `app/Enums/PurchaseOrderStatus.php` |
| `DocumentStatus` enum (7 cases) | Ready | `app/Enums/DocumentStatus.php` |
| `CreditNoteReason` / `DebitNoteReason` | Ready | `app/Enums/` |
| `PricingMode` enum | Ready | `app/Enums/PricingMode.php` |
| `SeriesType` (PurchaseOrder, SupplierInvoice, SupplierCreditNote, GoodsReceivedNote) | Ready | `app/Enums/SeriesType.php` |
| `MovementType::Purchase` / `::Return` | Ready | `app/Enums/MovementType.php` |
| `NavigationGroup::Purchases` | Ready | `app/Enums/NavigationGroup.php` |
| `StockService::receive()` | Ready — pass GRN as `$reference` morph | `app/Services/StockService.php` |
| `NumberSeries::generateNumber()` | Ready — atomic locking | `app/Models/NumberSeries.php` |
| `Partner.is_supplier` + `PartnerFactory::supplier()` | Ready | `app/Models/Partner.php` |
| `purchasing-manager` role (stub, 0 permissions) | Needs permissions | `database/seeders/RolesAndPermissionsSeeder.php` |

---

## Task Breakdown

### Task 3.1.1 — Enum + Models + Migrations + Factories

**New enum:**
- `app/Enums/GoodsReceivedNoteStatus.php` — `Draft`, `Confirmed`, `Cancelled` (simpler than DocumentStatus — no payment states)

**8 migrations** in `database/migrations/tenant/`:

| Table | Key columns | Notes |
|-------|-------------|-------|
| `purchase_orders` | po_number (unique), partner_id (FK→partners), warehouse_id (FK nullable→warehouses), status, currency_code, exchange_rate, pricing_mode, subtotal/discount/tax/total (decimal 15,2), expected_delivery_date, ordered_at, created_by, soft deletes | partner restrictOnDelete, warehouse nullOnDelete |
| `purchase_order_items` | purchase_order_id (FK cascade), product_variant_id (FK restrict), quantity/quantity_received (decimal 15,4), unit_price (decimal 15,4), discount_percent, vat_rate_id (FK), computed totals, sort_order | |
| `goods_received_notes` | grn_number (unique), purchase_order_id (FK nullable), partner_id (FK), warehouse_id (FK **required**), status (GoodsReceivedNoteStatus), received_at, created_by, soft deletes | warehouse NOT nullable (must specify where goods arrive) |
| `goods_received_note_items` | goods_received_note_id (FK cascade), purchase_order_item_id (FK nullable), product_variant_id (FK), quantity (decimal 15,4), unit_cost (decimal 15,4) | |
| `supplier_invoices` | supplier_invoice_number (supplier's own), internal_number (unique, from NumberSeries), purchase_order_id (FK nullable), partner_id (FK), status (DocumentStatus), currency/exchange/pricing, totals, amount_paid/amount_due, issued_at, received_at, due_date, payment_method, soft deletes | Composite unique: (partner_id, supplier_invoice_number) |
| `supplier_invoice_items` | supplier_invoice_id (FK cascade), purchase_order_item_id (nullable), product_variant_id (nullable — free-text lines), description, qty, unit_price, vat, totals, sort_order | |
| `supplier_credit_notes` | credit_note_number (unique), supplier_invoice_id (FK restrict), partner_id (FK), status (DocumentStatus), reason (CreditNoteReason), totals, issued_at, soft deletes | |
| `supplier_credit_note_items` | supplier_credit_note_id (FK cascade), supplier_invoice_item_id (FK restrict), product_variant_id (nullable), qty, unit_price, vat, totals | Constraint: sum(qty) per invoice item ≤ original qty |

All FK to number_series: use `->constrained('number_series')->nullOnDelete()` (historical rename).

**8 models** in `app/Models/`:
- `PurchaseOrder` — HasFactory, SoftDeletes, LogsActivity. Key methods: `isEditable()`, `isFullyReceived()`, `recalculateTotals()`
- `PurchaseOrderItem` — HasFactory. Key methods: `remainingQuantity()`, `isFullyReceived()`
- `GoodsReceivedNote` — HasFactory, SoftDeletes, LogsActivity. Key methods: `isEditable()`, `isConfirmed()`
- `GoodsReceivedNoteItem` — HasFactory
- `SupplierInvoice` — HasFactory, SoftDeletes, LogsActivity. Key methods: `isEditable()`, `isOverdue()`, `recalculateTotals()`
- `SupplierInvoiceItem` — HasFactory. Key methods: `creditedQuantity()`, `remainingCreditableQuantity()`
- `SupplierCreditNote` — HasFactory, SoftDeletes, LogsActivity
- `SupplierCreditNoteItem` — HasFactory

**8 factories** in `database/factories/` with status states (draft, sent, confirmed, etc.)

**Add to Partner model:** `scopeSuppliers()` — `where('is_supplier', true)`

### Task 3.1.2 — Infrastructure (Morph Map, RBAC, Policies)

**Morph map** (`AppServiceProvider.php`) — add 4 entries: `purchase_order`, `goods_received_note`, `supplier_invoice`, `supplier_credit_note`

**RolesAndPermissionsSeeder** — add 8 models to `$models` array:
- `purchase_order`, `purchase_order_item`, `goods_received_note`, `goods_received_note_item`, `supplier_invoice`, `supplier_invoice_item`, `supplier_credit_note`, `supplier_credit_note_item`
- `purchasing-manager`: full CRUD on all purchase models + view catalog/warehouse/partners
- `accountant`: view POs/GRNs + full CRUD on supplier invoices/credit notes
- `warehouse-manager`: full CRUD on GRNs + view POs
- `admin`: automatic (gets all)

**4 policies** in `app/Policies/`:
- `PurchaseOrderPolicy`, `GoodsReceivedNotePolicy`, `SupplierInvoicePolicy`, `SupplierCreditNotePolicy`
- Follow existing pattern (User model, `hasPermissionTo()` delegation)

### Task 3.1.3 — Services

**`app/Services/PurchaseOrderService.php`**
- `recalculateItemTotals(PurchaseOrderItem)` — compute discount, VAT, line totals
- `recalculateDocumentTotals(PurchaseOrder)` — sum items → subtotal/tax/total
- `transitionStatus(PurchaseOrder, PurchaseOrderStatus)` — validate transitions, throw on invalid
- `updateReceivedQuantities(PurchaseOrder)` — called after GRN confirm, sums GRN items → PO item `quantity_received`, auto-updates PO status (PartiallyReceived/Received)

**`app/Services/GoodsReceiptService.php`**
- `confirm(GoodsReceivedNote)` — in DB::transaction:
  1. Validate Draft status, has items, warehouse set
  2. For each item: `StockService::receive($variant, $warehouse, $qty, null, $grn, MovementType::Purchase)`
  3. If linked PO: update PO item `quantity_received`, recalculate PO status
  4. Set GRN status → Confirmed, set `received_at`
- `cancel(GoodsReceivedNote)` — only if Draft

### Task 3.1.4 — PurchaseOrder Filament Resource

**Directory:** `app/Filament/Resources/PurchaseOrders/`

- `PurchaseOrderResource.php` — `NavigationGroup::Purchases`, `$navigationSort = 1`
- `Schemas/PurchaseOrderForm.php` — partner (suppliers only), warehouse, number series, pricing mode, currency, dates, notes, computed totals (disabled)
- `Tables/PurchaseOrdersTable.php` — po_number, partner.name, status badge, total, expected_delivery_date, ordered_at, filters by status
- `Pages/` — List, Create, View, Edit
- `RelationManagers/PurchaseOrderItemsRelationManager.php` — product variant selector (auto-fills purchase_price), quantity, unit_price, discount, VAT rate, computed line totals, quantity_received progress indicator

**Status actions** (on View page header):
- Send (Draft→Sent), Confirm (Sent→Confirmed), Cancel (Draft/Sent/Confirmed→Cancelled)
- "Create GRN" (when Confirmed/PartiallyReceived) — pre-fills from PO
- "Create Supplier Invoice" (when Confirmed+) — pre-fills from PO

### Task 3.1.5 — GoodsReceivedNote Filament Resource

**Directory:** `app/Filament/Resources/GoodsReceivedNotes/`

- Same structure as PurchaseOrder resource
- `$navigationSort = 2`
- Form: PO selector (optional, live → auto-fills partner/warehouse), partner, warehouse (**required**), received_at
- Items: when PO linked, select from PO items with remaining qty > 0, auto-fill variant/price/qty
- **Confirm Receipt action** — calls `GoodsReceiptService::confirm()`, confirmation modal warning about irreversibility
- Edit page: redirect to view if confirmed (`isEditable()` check)

### Task 3.1.6 — SupplierInvoice Filament Resource

**Directory:** `app/Filament/Resources/SupplierInvoices/`

- `$navigationSort = 3`
- Form: supplier_invoice_number (supplier's own), internal_number (auto-generated, disabled), partner, PO link, dates (issued_at, received_at, due_date), payment_method, totals
- Items: product variant (nullable for free-text), description, qty, unit_price, discount, VAT
- Status actions: Confirm, Cancel
- "Create Credit Note" action (when Confirmed+)

### Task 3.1.7 — SupplierCreditNote Filament Resource

**Directory:** `app/Filament/Resources/SupplierCreditNotes/`

- `$navigationSort = 4`
- Form: supplier_invoice_id (required, live → populates partner/currency), reason (CreditNoteReason), reason_description (visible when Other), issued_at
- Items: select from parent invoice items with remaining creditable qty > 0, auto-fill variant/price, quantity validated against `remainingCreditableQuantity()` with `lockForUpdate()` to prevent race conditions
- Status actions: Confirm, Cancel

### Task 3.1.8 — Tests

**5 test files:**

| File | Tests | Key assertions |
|------|-------|----------------|
| `PurchaseOrderTest.php` | ~8 | CRUD, status transitions, total recalculation, partner-must-be-supplier |
| `GoodsReceivedNoteTest.php` | ~10 | Confirm triggers StockService::receive(), correct morph reference, PO qty_received updates, PO status auto-update (Partial/Received), standalone GRN (no PO), confirmed = immutable |
| `SupplierInvoiceTest.php` | ~5 | CRUD, status transitions, auto internal_number |
| `SupplierCreditNoteTest.php` | ~5 | Quantity validation (single CN, multiple CNs), CRUD |
| `PurchasePolicyTest.php` | ~6 | Purchasing-manager, accountant, warehouse-manager permissions |

### Task 3.1.9 — Docs & Task Files

- Update `docs/STATUS.md` — Phase 3.1, new models/resources
- Update `docs/UI_PANELS.md` — Purchases nav group
- Update `tasks/phase-3.md` (create if needed)
- Run Pint + full test suite

---

## Implementation Order

```
3.1.1  Enum + Models + Migrations + Factories
3.1.2  Morph map, RBAC seeder, Policies, Partner scope
3.1.3  PurchaseOrderService + GoodsReceiptService
3.1.4  PurchaseOrder Resource (form, table, pages, items RM, actions)
3.1.5  GoodsReceivedNote Resource (form, table, pages, items RM, confirm action)
3.1.6  SupplierInvoice Resource
3.1.7  SupplierCreditNote Resource
3.1.8  Tests
3.1.9  Docs + Pint + Final test run
```

---

## Verification

```bash
# Migrations run cleanly
./vendor/bin/sail artisan migrate

# No remaining DocumentSeries / BGN references in new code
grep -r "DocumentSeries\|BGN" app/Models/Purchase* app/Models/Goods* app/Models/Supplier*

# All tests pass (expect ~330+)
./vendor/bin/sail artisan test --parallel --compact

# Pint clean
vendor/bin/pint --dirty --format agent

# Morph map works: GRN confirm creates StockMovement with reference_type = 'goods_received_note'
# Manual browser check: Purchases nav group shows 4 resources, PO→GRN→stock flow works
```

---

## File Count Estimate

| Category | New | Modified |
|----------|-----|----------|
| Enums | 1 | 0 |
| Migrations | 8 | 0 |
| Models | 8 | 1 (Partner) |
| Factories | 8 | 0 |
| Policies | 4 | 0 |
| Services | 2 | 0 |
| Resources (all files) | ~28 | 0 |
| Tests | 5 | 0 |
| Infrastructure | 0 | 2 (AppServiceProvider, RolesAndPermissionsSeeder) |
| **Total** | **~64** | **3** |
