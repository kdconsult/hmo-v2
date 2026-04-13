# Phase 3 — Sales/Invoicing + Purchases + SUPTO/Fiscal

Split into sub-phases for manageable implementation.

---

## Sub-Phase 3.1 — Purchases

Build the inbound purchasing pipeline: PO → GRN → Supplier Invoice → Supplier Credit Note.

### Summary

| Task | Description | Status |
|------|-------------|--------|
| 3.1.1 | Enum + Models + Migrations + Factories | ✅ |
| 3.1.2 | Infrastructure (Morph map, RBAC, Policies) | ✅ |
| 3.1.3 | Services (PurchaseOrderService, GoodsReceiptService) | ✅ |
| 3.1.4 | PurchaseOrder Filament Resource | ✅ |
| 3.1.5 | GoodsReceivedNote Filament Resource | ✅ |
| 3.1.6 | SupplierInvoice Filament Resource | ✅ |
| 3.1.7 | SupplierCreditNote Filament Resource | ✅ |
| 3.1.8 | Tests | ✅ |
| 3.1.9 | Docs update + Pint + final test run | ✅ |
| 3.1.10 | UX wiring — connect pipeline, fix totals, auto-numbering, cascade data | ✅ |
| 3.1.11 | Structured review — 17 findings written to `tasks/phase-3.1-refactor.md` | ✅ |
| 3.1.12 | Refactor implementation — INFRA-1..5, PO-1..6, CATALOG-BUG-1, GRN-1, SI-1..3, PR-1 | 🔄 |
| 3.1.12a | Tier 1+2 done: PO-1, INFRA-5, PO-5, PO-4, PO-2, INFRA-4, CATALOG-BUG-1 | ✅ |
| 3.1.12b | Tier 3 done: PO-6, PO-3, INFRA-2, INFRA-3, GRN-1, SI-1 | ✅ |
| 3.1.12c | INFRA-1 done: CurrencyRateService, ExchangeRates RM rebuild, live rate auto-fill, BGN→EUR fixes | ✅ |

---

### Task 3.1.1 — Enum + Models + Migrations + Factories

- [x] Create `GoodsReceivedNoteStatus` enum (Draft, Confirmed, Cancelled)
- [x] Migration: `create_purchase_orders_table`
- [x] Migration: `create_purchase_order_items_table`
- [x] Migration: `create_goods_received_notes_table`
- [x] Migration: `create_goods_received_note_items_table`
- [x] Migration: `create_supplier_invoices_table`
- [x] Migration: `create_supplier_invoice_items_table`
- [x] Migration: `create_supplier_credit_notes_table`
- [x] Migration: `create_supplier_credit_note_items_table`
- [x] Model: `PurchaseOrder` (HasFactory, SoftDeletes, LogsActivity)
- [x] Model: `PurchaseOrderItem` (HasFactory)
- [x] Model: `GoodsReceivedNote` (HasFactory, SoftDeletes, LogsActivity)
- [x] Model: `GoodsReceivedNoteItem` (HasFactory)
- [x] Model: `SupplierInvoice` (HasFactory, SoftDeletes, LogsActivity)
- [x] Model: `SupplierInvoiceItem` (HasFactory)
- [x] Model: `SupplierCreditNote` (HasFactory, SoftDeletes, LogsActivity)
- [x] Model: `SupplierCreditNoteItem` (HasFactory)
- [x] Factory for each model (8 total) with status states
- [x] Add `scopeSuppliers()` to `Partner` model

### Task 3.1.2 — Infrastructure

- [x] Morph map: add `purchase_order`, `goods_received_note`, `supplier_invoice`, `supplier_credit_note` to `AppServiceProvider`
- [x] RBAC seeder: add 8 models to `$models` array (40 new permissions)
- [x] RBAC seeder: assign `purchasing-manager` permissions (full CRUD purchases + view catalog/warehouse/partners)
- [x] RBAC seeder: assign `accountant` permissions (view POs/GRNs + full CRUD supplier invoices/credit notes)
- [x] RBAC seeder: assign `warehouse-manager` GRN permissions + view POs
- [x] Policy: `PurchaseOrderPolicy`
- [x] Policy: `GoodsReceivedNotePolicy`
- [x] Policy: `SupplierInvoicePolicy`
- [x] Policy: `SupplierCreditNotePolicy`

### Task 3.1.3 — Services

- [x] `PurchaseOrderService` — item total calc, document total calc, status transitions, received qty update
- [x] `GoodsReceiptService` — `confirm()` calls `StockService::receive()` per line, updates PO status; `cancel()` for Draft only

### Task 3.1.4 — PurchaseOrder Resource

- [x] `PurchaseOrderResource.php` (NavigationGroup::Purchases, sort 1)
- [x] `PurchaseOrderForm.php` — supplier selector, warehouse, number series, pricing, dates, notes, computed totals
- [x] `PurchaseOrdersTable.php` — po_number, partner, status badge, total, dates, status filter
- [x] Pages: List, Create, View, Edit
- [x] `PurchaseOrderItemsRelationManager` — variant selector (auto-fills purchase_price), qty, price, discount, VAT, computed totals, qty_received progress
- [x] Status actions: Send, Confirm, Cancel
- [x] Cross-document actions: "Create GRN", "Create Supplier Invoice"

### Task 3.1.5 — GoodsReceivedNote Resource

- [x] `GoodsReceivedNoteResource.php` (NavigationGroup::Purchases, sort 2)
- [x] `GoodsReceivedNoteForm.php` — PO selector (live, auto-fills partner/warehouse), warehouse (required), received_at
- [x] `GoodsReceivedNotesTable.php` — grn_number, partner, warehouse, status, received_at
- [x] Pages: List, Create, View, Edit (edit redirects to view if confirmed)
- [x] `GoodsReceivedNoteItemsRelationManager` — PO item selector (remaining qty), variant, qty, unit_cost
- [x] **Confirm Receipt action** — calls `GoodsReceiptService::confirm()`, irreversible warning modal

### Task 3.1.6 — SupplierInvoice Resource

- [x] `SupplierInvoiceResource.php` (NavigationGroup::Purchases, sort 3)
- [x] `SupplierInvoiceForm.php` — supplier_invoice_number, internal_number (auto), partner, PO link, dates, payment_method, totals
- [x] `SupplierInvoicesTable.php` — internal_number, supplier_invoice_number, partner, status, total, due_date
- [x] Pages: List, Create, View, Edit
- [x] `SupplierInvoiceItemsRelationManager` — variant (nullable for free-text), description, qty, price, discount, VAT
- [x] Status actions: Confirm, Cancel
- [x] "Create Credit Note" action

### Task 3.1.7 — SupplierCreditNote Resource

- [x] `SupplierCreditNoteResource.php` (NavigationGroup::Purchases, sort 4)
- [x] `SupplierCreditNoteForm.php` — invoice selector (live, populates partner/currency), reason, reason_description, issued_at
- [x] `SupplierCreditNotesTable.php` — credit_note_number, invoice, partner, reason, status, total
- [x] Pages: List, Create, View, Edit
- [x] `SupplierCreditNoteItemsRelationManager` — invoice item selector (remaining creditable qty), qty validated against `remainingCreditableQuantity()`, auto-fill from invoice item

### Task 3.1.8 — Tests

- [x] `PurchaseOrderTest.php` — CRUD, status transitions, total recalculation, partner-must-be-supplier
- [x] `GoodsReceivedNoteTest.php` — confirm → stock up, morph reference, PO qty_received update, PO status auto-update, standalone GRN, confirmed = immutable
- [x] `SupplierInvoiceTest.php` — CRUD, status transitions, auto internal_number
- [x] `SupplierCreditNoteTest.php` — qty validation (single CN, multiple CNs), CRUD
- [x] `PurchasePolicyTest.php` — purchasing-manager, accountant, warehouse-manager permissions

### Task 3.1.10 — UX Wiring

- [x] Migration: add `pricing_mode` to `supplier_credit_notes`
- [x] `SupplierInvoiceService` — `recalculateItemTotals()` + `recalculateDocumentTotals()`
- [x] `SupplierCreditNoteService` — `recalculateItemTotals()` + `recalculateDocumentTotals()`
- [x] `SupplierCreditNote` model: add `pricing_mode` to fillable + casts
- [x] `SupplierInvoiceItem::creditedQuantity()` — exclude cancelled CNs
- [x] `Currency::scopeActive()` added
- [x] Fix auto-numbering: `po_number`, `grn_number`, `credit_note_number` → disabled+dehydrated (remove `required()`)
- [x] Fix `currency_code` → `Select` backed by Currency model (PO, SI, SCN forms)
- [x] Fix `mount()` on CreateGoodsReceivedNote — eager-load PO and fill partner/warehouse/received_at
- [x] Fix `mount()` on CreateSupplierInvoice — eager-load PO and fill all fields
- [x] Fix `mount()` on CreateSupplierCreditNote — eager-load invoice and fill all fields + mutate safety net
- [x] SCN form: add `partner_id` (hidden/dehydrated), `exchange_rate`, `pricing_mode` fields; update `afterStateUpdated`
- [x] SI form `afterStateUpdated`: also cascade `exchange_rate` and `pricing_mode` from PO
- [x] GRN items RM: "Import from PO" action + `purchase_order_item_id` selector in manual form
- [x] SI items RM: wire `SupplierInvoiceService` into CreateAction/EditAction/DeleteAction after hooks
- [x] SCN items RM: wire `SupplierCreditNoteService` into after hooks + fix `lockForUpdate` in `DB::transaction()`
- [x] PO items RM: fix `isReadOnly()` to check `isEditable()` (was hardcoded `return false`)
- [x] Tests: `SupplierInvoiceServiceTest.php` (4 tests), `SupplierCreditNoteServiceTest.php` (4 tests, includes cancelled CN exclusion)
- [x] Tests: add partner-must-be-supplier test to `PurchaseOrderTest.php`
- [x] Factory: add `pricing_mode` to `SupplierCreditNoteFactory`
- [x] Pint + full test run — 335 tests pass

### Task 3.1.9 — Docs + Final Verification

- [x] Update `docs/STATUS.md`
- [x] Update `docs/UI_PANELS.md` — add Purchases nav group
- [x] Run `vendor/bin/pint --dirty --format agent`
- [x] Run `./vendor/bin/sail artisan test --parallel --compact` — all pass
- [x] Verify: `grep -r "DocumentSeries\|BGN" app/Models/Purchase* app/Models/Goods* app/Models/Supplier*` → zero results

---

## Sub-Phase 3.2 — Sales/Invoicing (not yet planned)

Quote → SalesOrder → Invoice → CreditNote → DebitNote → DeliveryNote

## Sub-Phase 3.3 — SUPTO/Fiscal (not yet planned)

FiscalReceipt, CashRegister, CashRegisterShift, ErpNet.FP integration
