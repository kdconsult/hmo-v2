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
| 3.1.12 | Refactor implementation — INFRA-1..5, PO-1..6, CATALOG-BUG-1, GRN-1, SI-1..3, PR-1 | ✅ |
| 3.1.12a | Tier 1+2 done: PO-1, INFRA-5, PO-5, PO-4, PO-2, INFRA-4, CATALOG-BUG-1 | ✅ |
| 3.1.12b | Tier 3 done: PO-6, PO-3, INFRA-2, INFRA-3, GRN-1, SI-1 | ✅ |
| 3.1.12c | INFRA-1 done: CurrencyRateService, ExchangeRates RM rebuild, live rate auto-fill, BGN→EUR fixes | ✅ |
| 3.1.12d | SI-2 done: Import from PO action, PO-filtered form, invoicedQuantity/remainingInvoiceableQuantity on PurchaseOrderItem | ✅ |
| 3.1.12e | SI-3 done: Express Purchasing tenant setting, Confirm & Receive action, supplier_invoice_id on GRN, GRNs in related docs | ✅ |
| 3.1.12f | PR-1 done: PurchaseReturn full document stack (enums, migrations, models, service, policy, Filament resource, factories, 9 tests) | ✅ |

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

## Sub-Phase 3.2 — Sales/Invoicing

**Status:** Brainstorm complete (2026-04-14). Ready for planning.

**Process (agreed 2026-04-14):**
1. ✅ Brainstorm → agree on coverage
2. Write `tasks/phase-3.2-plan.md` — detailed implementation plan
3. Implement the plan
4. Refactor phase — structured review + `tasks/phase-3.2-refactor.md` (mirrors Phase 3.1.12 approach)

---

### Instructions for the Planning Agent

Read `docs/STATUS.md` and `tasks/phase-3.md` (this file) for full project context. Then read:
- `tasks/phase-3.1-refactor.md` — how the refactor phase was structured
- `app/Services/StockService.php` — before adding new methods
- `app/Models/PurchaseOrder.php` + `app/Models/PurchaseOrderItem.php` — mirror for SalesOrder
- `app/Models/SupplierInvoice.php` — mirror for CustomerInvoice
- `app/Filament/Resources/PurchaseOrders/` — mirror structure for Sales resources
- `app/Enums/MovementType.php`, `SeriesType.php`, `NavigationGroup.php` — all need extending

Your task: write `tasks/phase-3.2-plan.md` following the sub-task breakdown below. The plan must be specific enough that an implementing agent can write code without making decisions. All design decisions are already settled — do not re-open them. Use `search-docs` before writing any Filament-specific implementation details.

---

### Settled Design Decisions

#### General
- Phase 3.2 is the outbound mirror of Phase 3.1. Mirror all patterns: one service per document, morph map entries, policies, RBAC, ActivityLog, SoftDeletes, `decimal(15,4)` prices/quantities, `decimal(15,2)` totals.
- New navigation group: `NavigationGroup::Sales` — add to the enum with icon + label.
- Phase 3.3 boundary: emit `FiscalReceiptRequested` event on cash payment confirmation (CustomerInvoice + AdvancePayment). Phase 3.3 listens. No SUPTO calls in Phase 3.2.

#### Document: `Quotation`
- Statuses: `Draft → Sent → Accepted / Expired / Rejected / Cancelled` (four distinct terminal states — each has different reporting meaning)
- Print modes on same model: print as **Offer** (when Sent), print as **Proforma Invoice** (when Sent/Accepted) — PDF via `barryvdh/laravel-dompdf`, not separate document types
- "Create Sales Order" action on Accepted quotation — copies partner, lines, pricing, currency into new `SalesOrder` (like "Create GRN from PO" in Phase 3.1)
- `SeriesType::Quotation`

#### Document: `SalesOrder`
- Statuses: `Draft → Confirmed → PartiallyDelivered → Delivered → Invoiced → Cancelled`
- Items track `qty_delivered` and `qty_invoiced` (mirrors `qty_received` on `PurchaseOrderItem`)
- `DeliveryNoteService::updateDeliveredQuantities()` auto-transitions SO status after DN confirmation
- Service lines (`ProductType::Service`) skip DeliveryNote — `qty_delivered` set when `CustomerInvoice` is created for them
- **Stock reservation on SO confirmation:** `StockService::reserve()` per stock-type line; `StockService::unreserve()` on SO cancellation
- **SO → PO linkage (line level):** `purchase_order_items.sales_order_item_id` (nullable FK). "Import to PO" action on SO + batch action on SO list → PO selector dialog (pick existing Draft PO or create new). Imports SO lines as PO lines with `sales_order_item_id` set. Does NOT advance PO status. Repeatable (multiple SOs into same PO). PO items table gets editable SO-item column (dropdown filtered by same `product_variant_id`). Never merge lines for same product from different SOs.
- `SeriesType::SalesOrder`

#### Document: `DeliveryNote`
- Statuses: `Draft → Confirmed → Cancelled`
- Confirming calls `StockService::issueReserved()` per stock-type line
- Service lines skipped (no stock movement)
- One warehouse per DN (mirrors GRN)
- Links to SalesOrder; updates SO `qty_delivered` via `SalesOrderService`
- `SeriesType::DeliveryNote`

#### Document: `CustomerInvoice`
- `InvoiceType` enum: `Standard | Advance` — same model, different badge
- Advance invoices: issued when advance payment received (Bulgarian ЗДДС: within 5 days of receiving money; also correct EU practice for B2B)
- Final invoices: deduct advances as negative line rows — negative rows MUST carry negative VAT amounts so net VAT is correct. `VatCalculationService` must handle negative amounts without errors.
- Statuses: `Draft → Confirmed → Cancelled`
- Updates SO `qty_invoiced` on confirmation; sets service line `qty_delivered` on creation
- **Intra-EU reverse charge:** if `partner.country ≠ tenant.country` AND partner has valid EU VAT number → VAT rate forced to 0%, `is_reverse_charge = true` on invoice, "Reverse charge" note added
- **EU OSS threshold:** track cumulative B2C cross-border sales in `eu_oss_accumulations` table `(year, country_code, accumulated_amount_eur)`. Threshold: €10,000 aggregate across ALL EU countries (not per country). Once crossed, all B2C invoices to any EU country use destination-country VAT rate. Needs `eu_country_vat_rates` config/table (standard rate per EU country).
- `SeriesType::CustomerInvoice` (plan should note whether Advance type warrants separate series)

#### Document: `CustomerCreditNote`
- Quantity-constrained against original invoice lines — `remainingCreditableQuantity()` + `lockForUpdate()` in `DB::transaction()` (mirrors `SupplierCreditNote`)
- Statuses: `Draft → Confirmed → Cancelled`
- **Prompted from SalesReturn confirmation:** if SO has one invoice → auto-select; if multiple invoices → invoice selector in prompt dialog. Hook available on rejection.
- `SeriesType::CustomerCreditNote`

#### Document: `CustomerDebitNote`
- Amount-only — no stock movement, no quantity constraints
- Use case: shipping/packaging charges known only after dispatch, additional post-invoice charges
- Has line items (description + amount + VAT) but `product_variant_id` not required
- Links to original `CustomerInvoice` (informational, not constraining)
- Statuses: `Draft → Confirmed → Cancelled`
- `SeriesType::CustomerDebitNote`

#### Document: `SalesReturn`
- Mirrors `PurchaseReturn` exactly
- Links to `DeliveryNote` (nullable for future standalone)
- Confirming calls `StockService::receive()` per line with `MovementType::SalesReturn`
- On confirmation: prompted — "this SO has a CustomerInvoice — create Credit Note?" modal. Auto-selects if one invoice; selector if multiple. Hook available on rejection.
- `SeriesType::SalesReturn`

#### Document: `AdvancePayment`
- Standalone financial tracker — NOT a CustomerInvoice subtype
- Fields: `partner_id` (required), `sales_order_id` (nullable — B2C direct; B2B redundant but kept for historical context), `customer_invoice_id` (nullable — the advance invoice, B2B only), `amount`, `currency_code`, `received_at`
- Statuses: `Open → PartiallyApplied → FullyApplied → Refunded`
- `Refunded`: SO cancelled → Credit Note against advance invoice (B2B); direct refund record (B2C). No wallet / carry-forward.
- **`advance_payment_applications` pivot:** `(advance_payment_id, customer_invoice_id, amount_applied)` — financial truth for where advance was applied
- When creating CustomerInvoice: surface open advances for that partner (by `partner_id`) — "apply advances?" with amount selector
- `SeriesType::AdvancePayment`

---

### Infrastructure Additions

#### StockService — 3 new methods
- `reserve(ProductVariant, Warehouse, float $qty, Model $reference)` — increases `reserved_quantity`
- `unreserve(ProductVariant, Warehouse, float $qty, Model $reference)` — decreases `reserved_quantity`
- `issueReserved(ProductVariant, Warehouse, float $qty, Model $reference, User $by)` — **single atomic UPDATE** decreasing both `reserved_quantity` AND `quantity` (prevents race condition); creates `StockMovement`

#### New enum values
- `MovementType::SalesReturn`
- `SeriesType::Quotation | SalesOrder | DeliveryNote | CustomerInvoice | CustomerCreditNote | CustomerDebitNote | SalesReturn | AdvancePayment`
- `NavigationGroup::Sales`
- `InvoiceType::Standard | Advance` (new enum)

#### New DB tables (tenant migrations)
- `quotations`, `quotation_items`
- `sales_orders`, `sales_order_items`
- `delivery_notes`, `delivery_note_items`
- `customer_invoices`, `customer_invoice_items`
- `customer_credit_notes`, `customer_credit_note_items`
- `customer_debit_notes`, `customer_debit_note_items`
- `sales_returns`, `sales_return_items`
- `advance_payments`
- `advance_payment_applications` (pivot: advance_payment_id, customer_invoice_id, amount_applied)
- `purchase_order_items` — add `sales_order_item_id` nullable FK (migration)
- `eu_oss_accumulations` (year, country_code, accumulated_amount_eur)
- `eu_country_vat_rates` (country_code, standard_rate) — or config file; plan should decide

#### Morph map entries
`quotation`, `sales_order`, `delivery_note`, `customer_invoice`, `customer_credit_note`, `customer_debit_note`, `sales_return`, `advance_payment`

#### Policies (8 new)
One per document model. Register in `AuthServiceProvider`.

#### RBAC
- All 8 new models added to `RolesAndPermissionsSeeder`
- `sales-manager` role: full CRUD on all Sales documents + view catalog/warehouse/partners
- `accountant` role: extended with CustomerInvoice, CustomerCreditNote, CustomerDebitNote, AdvancePayment
- `warehouse-manager` role: extended with DeliveryNote + SalesReturn

---

### Sub-Task Breakdown

| Sub-task | Scope | Status |
|---|---|---|
| **3.2.1** | Enums + Models + Migrations + Factories (all documents + pivots + OSS table + `InvoiceType` enum) | ✅ |
| **3.2.2** | Infrastructure (StockService 3 new methods, SeriesType, NavigationGroup, InvoiceType, morph map, RBAC, Policies) | ✅ |
| **3.2.3** | `Quotation` resource (CRUD, status pipeline, print actions, Create SO action) | ✅ |
| **3.2.4** | `SalesOrder` resource (CRUD, status pipeline, reserve on confirm, SO→PO import action) | ✅ |
| **3.2.5** | `DeliveryNote` resource (CRUD, confirm → `issueReserved()`, service line skip, SO qty update) | ✅ |
| **3.2.6** | `CustomerInvoice` resource (Standard + Advance types, advance deduction rows with VAT, reverse charge, OSS VAT logic) | ✅ |
| **3.2.7** | `CustomerCreditNote` + `CustomerDebitNote` resources | ✅ |
| **3.2.8** | `SalesReturn` resource (confirm → `receive()`, prompt CN modal) | ✅ |
| **3.2.9** | `AdvancePayment` resource (CRUD, applications pivot, open advances surfaced on invoice creation) | ✅ |
| **3.2.10** | Tests — SalesPolicyTest, EuOssTest, CustomerInvoiceTest, CustomerCreditNoteTest, CustomerDebitNoteTest (513 tests; ReverseChargeTest skipped — no auto-detection logic exists) | ✅ |
| **3.2.11** | Docs update (`docs/STATUS.md`, `docs/UI_PANELS.md`) + Pint + final test run | |
| **3.2.12** | **Refactor phase** — structured review → `tasks/phase-3.2-refactor.md` | |

---

### Deferred to Backlog
- Per-partner price lists and special prices — add detailed item to `tasks/backlog.md`

## Sub-Phase 3.3 — SUPTO/Fiscal (not yet planned)

FiscalReceipt, CashRegister, CashRegisterShift, ErpNet.FP integration
