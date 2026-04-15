# Phase 3.2 — Sales / Invoicing: Implementation Plan

## Context

Phase 3.1 (Purchases) is complete (398 tests). Phase 3.2 builds the outbound sales pipeline, mirroring the purchase pipeline structure. All design decisions are settled in `tasks/phase-3.md`. This plan provides implementation-level detail for each sub-task.

**Guiding principle:** For each document that mirrors a purchase-side equivalent, reference the mirror file and list only **deviations**. The implementing agent should read the mirror file and replicate its patterns unless the plan says otherwise.

---

## Sub-task 3.2.1 — Enums + Models + Migrations + Factories ✅ DONE (2026-04-14, 398 tests)

### Enum Changes

**Modify `app/Enums/SeriesType.php`** — ADD two cases:
- `SalesReturn = 'sales_return'` → label `__('Sales Return')`
- `AdvancePayment = 'advance_payment'` → label `__('Advance Payment')`

**Modify `app/Enums/MovementType.php`** — ADD one case:
- `SalesReturn = 'sales_return'` → color `success` (inbound — stock returns to warehouse)

**Create `app/Enums/QuotationStatus.php`** (replaces `QuoteStatus.php`):
- Cases: `Draft`, `Sent`, `Accepted`, `Expired`, `Rejected`, `Cancelled`
- Colors: Draft=gray, Sent=info, Accepted=success, Expired=warning, Rejected=danger, Cancelled=gray
- Icons: Draft=OutlinedPencil, Sent=OutlinedPaperAirplane, Accepted=OutlinedHandThumbUp, Expired=OutlinedClock, Rejected=OutlinedHandThumbDown, Cancelled=OutlinedXCircle
- DELETE `app/Enums/QuoteStatus.php` — confirmed unused (only in definition + FilamentIconEnumTest)

**Create `app/Enums/SalesOrderStatus.php`** (replaces `OrderStatus.php`):
- Cases: `Draft`, `Confirmed`, `PartiallyDelivered`, `Delivered`, `Invoiced`, `Cancelled`
- Colors: Draft=gray, Confirmed=info, PartiallyDelivered=warning, Delivered=success, Invoiced=primary, Cancelled=gray
- DELETE `app/Enums/OrderStatus.php` — confirmed unused

**Create `app/Enums/DeliveryNoteStatus.php`** — mirror `GoodsReceivedNoteStatus.php`:
- Cases: `Draft`, `Confirmed`, `Cancelled`

**Create `app/Enums/SalesReturnStatus.php`** — mirror `PurchaseReturnStatus.php`:
- Cases: `Draft`, `Confirmed`, `Cancelled`

**Create `app/Enums/AdvancePaymentStatus.php`**:
- Cases: `Open`, `PartiallyApplied`, `FullyApplied`, `Refunded`
- Colors: Open=info, PartiallyApplied=warning, FullyApplied=success, Refunded=danger

**Create `app/Enums/InvoiceType.php`**:
- Cases: `Standard`, `Advance`
- Implements `HasLabel`

**Update `tests/Unit/Enums/FilamentIconEnumTest.php`** — replace `QuoteStatus`/`OrderStatus` references with new enum names.

### Migrations (all in `database/migrations/tenant/`)

**IMPORTANT:** All column names must match existing codebase patterns:
- Item columns: `vat_rate_id` (FK to `vat_rates`), `vat_amount` (decimal:15,2), `line_total` (decimal:15,2), `line_total_with_vat` (decimal:15,2), `discount_percent` (decimal:5,2), `discount_amount` (decimal:15,2)
- Document totals: `subtotal`, `discount_amount`, `tax_amount`, `total` (all decimal:15,2)
- Quantities: `decimal(15,4)`; Prices: `decimal(15,4)`; Exchange rate: `decimal(16,6)`

**Migration 01: `create_quotations_table`**
- Mirror: `create_purchase_orders_table`
- Columns: `id`, `quotation_number` (unique), `document_series_id` FK nullable→number_series, `partner_id` FK→partners (restrictOnDelete), `status` default 'draft', `currency_code` char:3 default 'EUR', `exchange_rate` decimal:16,6, `pricing_mode` default 'vat_exclusive', `subtotal` decimal:15,2, `discount_amount` decimal:15,2, `tax_amount` decimal:15,2, `total` decimal:15,2, `valid_until` date nullable, `issued_at` date nullable, `notes` text nullable, `internal_notes` text nullable, `created_by` FK nullable→users, timestamps, softDeletes
- Indexes: status, partner_id, issued_at
- Deviation from PO: no `warehouse_id`, adds `valid_until`, no `expected_delivery_date`

**Migration 02: `create_quotation_items_table`**
- Mirror: `create_purchase_order_items_table`
- Columns: `id`, `quotation_id` FK cascadeOnDelete, `product_variant_id` FK restrictOnDelete, `description` text nullable, `quantity` decimal:15,4, `unit_price` decimal:15,4, `discount_percent` decimal:5,2 default 0, `discount_amount` decimal:15,2 default 0, `vat_rate_id` FK restrictOnDelete, `vat_amount` decimal:15,2, `line_total` decimal:15,2, `line_total_with_vat` decimal:15,2, `sort_order` int default 0, timestamps
- Deviation from PO items: no `quantity_received` or tracking columns

**Migration 03: `create_sales_orders_table`**
- Mirror: `create_purchase_orders_table`
- Columns: `id`, `so_number` (unique), `document_series_id` FK nullable, `partner_id` FK restrictOnDelete, `quotation_id` FK nullable nullOnDelete→quotations, `warehouse_id` FK restrictOnDelete→warehouses, `status` default 'draft', `currency_code`, `exchange_rate`, `pricing_mode`, `subtotal`, `discount_amount`, `tax_amount`, `total`, `expected_delivery_date` date nullable, `issued_at` date nullable, `notes`, `internal_notes`, `created_by`, timestamps, softDeletes
- Deviation from PO: adds `quotation_id` FK; `warehouse_id` is NOT nullable (required for stock reservation)

**Migration 04: `create_sales_order_items_table`**
- Mirror: `create_purchase_order_items_table`
- Columns: same as PO items, plus: `quotation_item_id` FK nullable nullOnDelete→quotation_items, `qty_delivered` decimal:15,4 default 0, `qty_invoiced` decimal:15,4 default 0
- Deviation from PO items: TWO tracking columns instead of one (`quantity_received`)

**Migration 05: `create_delivery_notes_table`**
- Mirror: `create_goods_received_notes_table`
- Columns: `id`, `dn_number` (unique), `document_series_id` FK nullable, `sales_order_id` FK nullable nullOnDelete→sales_orders, `partner_id` FK restrictOnDelete, `warehouse_id` FK restrictOnDelete, `status` default 'draft', `delivered_at` date nullable, `notes` text nullable, `created_by`, timestamps, softDeletes
- Deviation from GRN: `sales_order_id` replaces `purchase_order_id`; `delivered_at` replaces `received_at`; no `supplier_invoice_id`

**Migration 06: `create_delivery_note_items_table`**
- Mirror: `create_goods_received_note_items_table`
- Columns: `id`, `delivery_note_id` FK cascadeOnDelete, `sales_order_item_id` FK nullable nullOnDelete, `product_variant_id` FK restrictOnDelete, `quantity` decimal:15,4, `unit_cost` decimal:15,4, `notes` text nullable, timestamps

**Migration 07: `create_customer_invoices_table`**
- Mirror: `create_supplier_invoices_table`
- Columns: `id`, `invoice_number` (unique), `document_series_id` FK nullable, `sales_order_id` FK nullable nullOnDelete→sales_orders, `partner_id` FK restrictOnDelete, `status` default 'draft', `invoice_type` default 'standard', `is_reverse_charge` boolean default false, `currency_code`, `exchange_rate`, `pricing_mode`, `subtotal`, `discount_amount`, `tax_amount`, `total`, `amount_paid` decimal:15,2 default 0, `amount_due` decimal:15,2 default 0, `payment_method` nullable, `issued_at` date nullable, `due_date` date nullable, `notes`, `internal_notes`, `created_by`, timestamps, softDeletes
- Deviations from SI: adds `invoice_type`, `is_reverse_charge`, `sales_order_id`; drops `supplier_invoice_number`, `received_at`, `purchase_order_id`

**Migration 08: `create_customer_invoice_items_table`**
- Mirror: `create_supplier_invoice_items_table`
- Columns: `id`, `customer_invoice_id` FK cascadeOnDelete, `sales_order_item_id` FK nullable nullOnDelete, `product_variant_id` FK nullable nullOnDelete, `description` text, `quantity` decimal:15,4, `unit_price` decimal:15,4, `discount_percent` decimal:5,2 default 0, `discount_amount` decimal:15,2 default 0, `vat_rate_id` FK restrictOnDelete, `vat_amount` decimal:15,2, `line_total` decimal:15,2, `line_total_with_vat` decimal:15,2, `sort_order`, timestamps

**Migration 09: `create_customer_credit_notes_table`**
- Mirror: `create_supplier_credit_notes_table`
- Columns: `id`, `credit_note_number` (unique), `document_series_id` FK nullable, `customer_invoice_id` FK restrictOnDelete, `partner_id` FK restrictOnDelete, `status` default 'draft', `currency_code`, `exchange_rate`, `pricing_mode`, `reason`, `reason_description` text nullable, `subtotal`, `tax_amount`, `total`, `issued_at` date nullable, `created_by`, timestamps, softDeletes
- Note: no `discount_amount` (same as SupplierCreditNote)

**Migration 10: `create_customer_credit_note_items_table`**
- Mirror: `create_supplier_credit_note_items_table`
- Columns: `id`, `customer_credit_note_id` FK cascadeOnDelete, `customer_invoice_item_id` FK restrictOnDelete, `product_variant_id` FK nullable nullOnDelete, `description` text, `quantity` decimal:15,4, `unit_price` decimal:15,4, `vat_rate_id` FK restrictOnDelete, `vat_amount`, `line_total`, `line_total_with_vat`, `sort_order`, timestamps
- No discount columns (matches SupplierCreditNoteItem pattern)

**Migration 11: `create_customer_debit_notes_table`**
- Same structure as credit notes table but:
- Columns: `debit_note_number` (unique) instead of `credit_note_number`
- `customer_invoice_id` FK nullable (informational, not constraining) — **nullOnDelete** not restrictOnDelete
- Uses `DebitNoteReason` enum (already exists) instead of `CreditNoteReason`

**Migration 12: `create_customer_debit_note_items_table`**
- Same structure as credit note items but `customer_debit_note_id` FK, `customer_invoice_item_id` FK nullable (not required)
- `product_variant_id` nullable (description-only line items allowed)

**Migration 13: `create_sales_returns_table`**
- Mirror: `create_purchase_returns_table`
- Columns: `id`, `sr_number` (unique), `document_series_id` FK nullable, `delivery_note_id` FK nullable nullOnDelete, `partner_id` FK restrictOnDelete, `warehouse_id` FK restrictOnDelete, `status` default 'draft', `returned_at` date nullable, `reason` text nullable, `notes` text nullable, `created_by`, timestamps, softDeletes

**Migration 14: `create_sales_return_items_table`**
- Mirror: `create_purchase_return_items_table`
- Columns: `id`, `sales_return_id` FK cascadeOnDelete, `delivery_note_item_id` FK nullable nullOnDelete, `product_variant_id` FK restrictOnDelete, `quantity` decimal:15,4, `unit_cost` decimal:15,4, `notes` text nullable, timestamps

**Migration 15: `create_advance_payments_table`** (no purchase-side mirror)
- Columns: `id`, `ap_number` (unique), `document_series_id` FK nullable, `partner_id` FK restrictOnDelete, `sales_order_id` FK nullable nullOnDelete→sales_orders, `customer_invoice_id` FK nullable nullOnDelete→customer_invoices, `status` default 'open', `currency_code` char:3 default 'EUR', `exchange_rate` decimal:16,6 default 1, `amount` decimal:15,2, `amount_applied` decimal:15,2 default 0, `payment_method` nullable, `received_at` date nullable, `notes` text nullable, `created_by`, timestamps, softDeletes

**Migration 16: `create_advance_payment_applications_table`** (pivot)
- Columns: `id`, `advance_payment_id` FK cascadeOnDelete, `customer_invoice_id` FK cascadeOnDelete, `amount_applied` decimal:15,2, `applied_at` datetime nullable, timestamps
- Unique: `[advance_payment_id, customer_invoice_id]`

**Migration 17: `create_eu_country_vat_rates_table`**
- Columns: `id`, `country_code` char:2 unique, `country_name`, `standard_rate` decimal:5,2, `reduced_rate` decimal:5,2 nullable, `timestamps`

**Migration 18: `create_eu_oss_accumulations_table`**
- Columns: `id`, `year` smallint unsigned, `country_code` char:2, `accumulated_amount_eur` decimal:15,2 default 0, `threshold_exceeded_at` datetime nullable, timestamps
- Unique: `[year, country_code]`

**Migration 19: `add_country_code_to_partners_table`** (ALTER existing table)
- Adds `country_code` char:2 nullable after `is_active` (or similar)

**Migration 20: `add_sales_order_item_id_to_purchase_order_items_table`** (ALTER existing table)
- Adds `sales_order_item_id` FK nullable nullOnDelete→sales_order_items
- CRITICAL: separate migration file, not mixed with new table migrations
- **Also update existing code:**
  - `app/Models/PurchaseOrderItem.php`: add `sales_order_item_id` to `$fillable`, add `salesOrderItem()` belongsTo SalesOrderItem (nullable)
  - `app/Filament/Resources/PurchaseOrders/RelationManagers/PurchaseOrderItemsRelationManager.php`: add editable `sales_order_item_id` Select column (dropdown filtered by same `product_variant_id`)

### Models

For each model, mirror the purchase-side equivalent. Listed below are only the **deviations**.

**`Quotation`** — mirror `PurchaseOrder`
- Status: `QuotationStatus` (not `PurchaseOrderStatus`)
- No `warehouse_id`; adds `valid_until` date
- Relationships: `items()` → QuotationItem, `salesOrders()` hasMany SalesOrder (via `quotation_id`)
- Methods: `isEditable()`, `recalculateTotals()`, `isExpired()` (new: `valid_until && valid_until->isPast()`)

**`QuotationItem`** — mirror `PurchaseOrderItem`
- No tracking columns (`quantity_received`, etc.)
- No `remainingQuantity()` or similar methods
- Relationship: `quotation()`, `productVariant()`, `vatRate()`

**`SalesOrder`** — mirror `PurchaseOrder`
- Status: `SalesOrderStatus`
- Adds: `quotation_id` FK (nullable), `issued_at` date
- `warehouse_id` is required (not nullable)
- Relationships: adds `quotation()` belongsTo, `deliveryNotes()` hasMany, `customerInvoices()` hasMany
- Methods: `isFullyDelivered()`, `isFullyInvoiced()`

**`SalesOrderItem`** — mirror `PurchaseOrderItem`
- Adds: `quotation_item_id` FK, `qty_delivered`, `qty_invoiced` (two tracking fields vs one `quantity_received`)
- Methods: `remainingDeliverableQuantity()`, `remainingInvoiceableQuantity()`, `isFullyDelivered()`, `isFullyInvoiced()`
- Relationships: adds `quotationItem()`, `deliveryNoteItems()` hasMany, `customerInvoiceItems()` hasMany

**`DeliveryNote`** — mirror `GoodsReceivedNote`
- `sales_order_id` replaces `purchase_order_id`; `delivered_at` replaces `received_at`
- No `supplier_invoice_id`
- Relationships: `salesOrder()`, `salesReturns()` hasMany, `stockMovements()` morphMany

**`DeliveryNoteItem`** — mirror `GoodsReceivedNoteItem`
- `delivery_note_id` + `sales_order_item_id` replace GRN equivalents
- Methods: `returnedQuantity()`, `remainingReturnableQuantity()` (filtering by confirmed SalesReturns)

**`CustomerInvoice`** — mirror `SupplierInvoice`
- Adds: `sales_order_id` FK, `invoice_type` (cast to `InvoiceType`), `is_reverse_charge` boolean
- Drops: `supplier_invoice_number`, `received_at`, `purchase_order_id`
- Relationships: `salesOrder()`, `creditNotes()` hasMany CustomerCreditNote, `debitNotes()` hasMany CustomerDebitNote, `advancePaymentApplications()` hasMany

**`CustomerInvoiceItem`** — mirror `SupplierInvoiceItem`
- `customer_invoice_id` + `sales_order_item_id` replace SI equivalents
- Methods: `creditedQuantity()`, `remainingCreditableQuantity()` (same pattern as SupplierInvoiceItem)

**`CustomerCreditNote`** — mirror `SupplierCreditNote` (exact same structure)
- `customer_invoice_id` replaces `supplier_invoice_id`

**`CustomerCreditNoteItem`** — mirror `SupplierCreditNoteItem`
- `customer_credit_note_id` + `customer_invoice_item_id` replace SCN equivalents

**`CustomerDebitNote`** — same structure as CustomerCreditNote
- `debit_note_number` instead of `credit_note_number`
- `reason` cast to `DebitNoteReason` (existing enum) instead of `CreditNoteReason`
- `customer_invoice_id` is nullable (informational link, not constraining)

**`CustomerDebitNoteItem`** — same structure as CustomerCreditNoteItem
- `customer_debit_note_id` + `customer_invoice_item_id` (nullable)
- `product_variant_id` nullable (free-text line items)

**`SalesReturn`** — mirror `PurchaseReturn`
- `delivery_note_id` replaces `goods_received_note_id`
- Relationships: `deliveryNote()`, `stockMovements()` morphMany

**`SalesReturnItem`** — mirror `PurchaseReturnItem`
- `delivery_note_item_id` replaces `goods_received_note_item_id`

**`AdvancePayment`** (no purchase-side mirror)
- Traits: HasFactory, LogsActivity, SoftDeletes
- Fillable: `ap_number`, `document_series_id`, `partner_id`, `sales_order_id`, `customer_invoice_id`, `status`, `currency_code`, `exchange_rate`, `amount`, `amount_applied`, `payment_method`, `received_at`, `notes`, `created_by`
- Casts: `status` → `AdvancePaymentStatus`, `payment_method` → `PaymentMethod`, `amount`/`amount_applied` → `decimal:2`, `exchange_rate` → `decimal:6`, `received_at` → date
- Relationships: `partner()`, `salesOrder()` nullable, `advanceInvoice()` belongsTo CustomerInvoice (FK: `customer_invoice_id`), `applications()` hasMany AdvancePaymentApplication, `documentSeries()`, `createdBy()`
- Methods: `isEditable()` (Open only), `remainingAmount()` → bcsub(amount, amount_applied, 2), `isFullyApplied()` → bccomp(amount_applied, amount, 2) >= 0

**`AdvancePaymentApplication`** (pivot model, no mirror)
- Fillable: `advance_payment_id`, `customer_invoice_id`, `amount_applied`, `applied_at`
- Casts: `amount_applied` → `decimal:2`, `applied_at` → datetime
- Relationships: `advancePayment()`, `customerInvoice()`

**`EuCountryVatRate`** (reference data, no mirror)
- Fillable: `country_code`, `country_name`, `standard_rate`, `reduced_rate`
- No SoftDeletes, no LogsActivity
- Static: `getStandardRate(string $countryCode): ?float`

**`EuOssAccumulation`** (tracking, no mirror)
- Fillable: `year`, `country_code`, `accumulated_amount_eur`, `threshold_exceeded_at`
- Static: `accumulate(string $countryCode, int $year, float $amountEur): self`, `isThresholdExceeded(int $year): bool` (checks total across ALL countries against €10,000)

**Partner model changes:**
- Add `country_code` to `$fillable`
- Add `scopeCustomers()`: `$query->where('is_customer', true)`
- Add `hasValidEuVat(): bool` — checks `vat_number` not empty and `country_code` is EU member

### Factories

Mirror the pattern in `database/factories/PurchaseOrderFactory.php`. Each factory has `definition()` with sensible defaults and state methods for each status.

| Factory | States |
|---------|--------|
| QuotationFactory | draft, sent, accepted, expired, rejected, cancelled |
| QuotationItemFactory | — |
| SalesOrderFactory | draft, confirmed, partiallyDelivered, delivered, invoiced, cancelled |
| SalesOrderItemFactory | — |
| DeliveryNoteFactory | draft, confirmed, cancelled |
| DeliveryNoteItemFactory | — |
| CustomerInvoiceFactory | draft, confirmed, cancelled |
| CustomerInvoiceItemFactory | — |
| CustomerCreditNoteFactory | draft, confirmed, cancelled |
| CustomerCreditNoteItemFactory | — |
| CustomerDebitNoteFactory | draft, confirmed, cancelled |
| CustomerDebitNoteItemFactory | — |
| SalesReturnFactory | draft, confirmed, cancelled |
| SalesReturnItemFactory | — |
| AdvancePaymentFactory | open, partiallyApplied, fullyApplied, refunded |

---

## Sub-task 3.2.2 — Infrastructure ✅ DONE (2026-04-14, 406 tests)

### StockService — 3 New Methods

**File: `app/Services/StockService.php`** — add to existing class, existing methods untouched.

```php
public function reserve(ProductVariant $variant, Warehouse $warehouse, float $qty, Model $reference): void
```
- In DB::transaction: find StockItem via `findOrCreateStockItem()`, check `$stockItem->available_quantity >= $qty`, increment `reserved_quantity`
- Throws `InsufficientStockException` if insufficient available stock
- Does NOT create StockMovement (reservations are bookings, not physical movements)

```php
public function unreserve(ProductVariant $variant, Warehouse $warehouse, float $qty, Model $reference): void
```
- Find StockItem, decrement `reserved_quantity` by `$qty` (floor at 0)
- Does NOT create StockMovement

```php
public function issueReserved(ProductVariant $variant, Warehouse $warehouse, float $qty, Model $reference, ?User $by = null): StockMovement
```
- **CRITICAL: Single atomic SQL UPDATE** to prevent race conditions:
```sql
UPDATE stock_items
SET quantity = quantity - :qty,
    reserved_quantity = reserved_quantity - :qty
WHERE product_variant_id = :variant_id
  AND warehouse_id = :warehouse_id
  AND reserved_quantity >= :qty
  AND quantity >= :qty
```
- Check affected rows === 1; if 0, throw `InsufficientStockException`
- Then create StockMovement with `MovementType::Sale`, negative `$qty`, polymorphic `$reference`
- This differs from `issue()` which checks PHP-level available quantity

### Morph Map

**File: `app/Providers/AppServiceProvider.php`** — add to existing morph map:
```
'quotation' => Quotation::class,
'sales_order' => SalesOrder::class,
'delivery_note' => DeliveryNote::class,
'customer_invoice' => CustomerInvoice::class,
'customer_credit_note' => CustomerCreditNote::class,
'customer_debit_note' => CustomerDebitNote::class,
'sales_return' => SalesReturn::class,
'advance_payment' => AdvancePayment::class,
```

### RBAC

**File: `database/seeders/RolesAndPermissionsSeeder.php`**
- Add 16 entries to `$models` array: `quotation`, `quotation_item`, `sales_order`, `sales_order_item`, `delivery_note`, `delivery_note_item`, `customer_invoice`, `customer_invoice_item`, `customer_credit_note`, `customer_credit_note_item`, `customer_debit_note`, `customer_debit_note_item`, `sales_return`, `sales_return_item`, `advance_payment`, `advance_payment_application`
- **CREATE** new role `sales-manager` (not extending — new role, parallels `purchasing-manager`): full CRUD on all 16 models + view catalog/warehouse/partners
- `accountant` role: extend with view+CRUD on financial sales docs (customer_invoice, customer_credit_note, customer_debit_note, advance_payment and their items); view on quotation, sales_order, delivery_note, sales_return
- `warehouse-manager` role: extend with full CRUD on delivery_note, delivery_note_item, sales_return, sales_return_item; view on sales_order

### Policies (8 new)

All mirror `app/Policies/PurchaseOrderPolicy.php` exactly — each method delegates to `$user->hasPermissionTo('{action}_{model}')`.

| File | Permission prefix |
|------|------------------|
| QuotationPolicy | `{action}_quotation` |
| SalesOrderPolicy | `{action}_sales_order` |
| DeliveryNotePolicy | `{action}_delivery_note` |
| CustomerInvoicePolicy | `{action}_customer_invoice` |
| CustomerCreditNotePolicy | `{action}_customer_credit_note` |
| CustomerDebitNotePolicy | `{action}_customer_debit_note` |
| SalesReturnPolicy | `{action}_sales_return` |
| AdvancePaymentPolicy | `{action}_advance_payment` |

### EU VAT Rates Seeder

**File: `database/seeders/EuCountryVatRatesSeeder.php`** — seeds all 27 EU member states with current standard rates. Called from tenant DatabaseSeeder.

### FiscalReceiptRequested Event

**File: `app/Events/FiscalReceiptRequested.php`** — simple dispatchable event with `public CustomerInvoice $invoice` property. No listener until Phase 3.3. Dispatched on cash payment invoice confirmation.

---

## Sub-task 3.2.3 — Quotation Resource ✅ DONE (2026-04-14, 418 tests)

### Implementation notes (deviations from plan)
- `convertToSalesOrder()` takes a `Warehouse` parameter — `sales_orders.warehouse_id` is NOT NULL in the migration, so the ViewQuotation "Convert to SO" action shows a warehouse picker modal before creating the SO.
- SO number generated via `NumberSeries::getDefault(SeriesType::SalesOrder)` if available, falls back to `SO-{random}`. The redirect after conversion currently points back to the quotation view; will be updated to redirect to the SO in 3.2.4.
- PDF print actions use `response()->streamDownload()` inside the Filament action closure (Livewire 4 native support).

### QuotationService

**File: `app/Services/QuotationService.php`**
- Mirror: `PurchaseOrderService.php`
- Dependencies: `VatCalculationService`

Methods:
1. `recalculateItemTotals(QuotationItem)` — identical to PO pattern
2. `recalculateDocumentTotals(Quotation)` — identical to PO pattern
3. `transitionStatus(Quotation, QuotationStatus)` — valid transitions:
   - Draft → Sent, Cancelled
   - Sent → Accepted, Rejected, Expired, Cancelled
   - Accepted → Cancelled (Accepted stays Accepted even after SO creation)
4. `convertToSalesOrder(Quotation, Warehouse): SalesOrder` — creates SO copying: partner, currency, exchange_rate, pricing_mode, warehouse (required via modal), items (with quotation_item_id linkage). Returns the new SO. Does NOT change quotation status.

### Filament Resource

**Directory: `app/Filament/Resources/Quotations/`**
- Mirror structure from `app/Filament/Resources/PurchaseOrders/`
- NavigationGroup: `Sales`, navigationSort: 1

**QuotationForm** — mirror PurchaseOrderForm with deviations:
- Partner select: `Partner::customers()->where('is_active', true)` (not suppliers)
- No `warehouse_id` field
- Adds `valid_until` DatePicker
- `quotation_number` disabled+dehydrated instead of `po_number`
- `issued_at` instead of `ordered_at`

**QuotationsTable** — mirror PurchaseOrdersTable:
- Columns: quotation_number, partner.name, status badge (QuotationStatus), total, valid_until, issued_at
- Filter: QuotationStatus enum

**CreateQuotation** — mirror CreatePurchaseOrder:
- `SeriesType::Quote` for number generation

**ViewQuotation** — mirror ViewPurchaseOrder:
- Header actions: Edit (if editable), Send (Draft→Sent), Accept (Sent→Accepted), Reject (Sent→Rejected), **Convert to Sales Order** (Accepted only — calls `QuotationService::convertToSalesOrder()`, redirects to new SO edit page), Cancel
- **Print actions** (PDF via `barryvdh/laravel-dompdf`):
  - "Print as Offer" (visible when Sent) — generates offer PDF
  - "Print as Proforma Invoice" (visible when Sent or Accepted) — same data, different header/template
  - Both use the same model, different Blade PDF templates: `resources/views/pdf/quotation-offer.blade.php` and `resources/views/pdf/quotation-proforma.blade.php`
- Related documents: linked SalesOrders

**QuotationItemsRelationManager** — mirror PurchaseOrderItemsRelationManager:
- Uses `QuotationService` for recalculation
- Product variant select auto-fills `sale_price` (not `purchase_price`)

---

## Sub-task 3.2.4 — SalesOrder Resource ✅ DONE (2026-04-14, 435 tests)

### SalesOrderService

**File: `app/Services/SalesOrderService.php`**
- Mirror: `PurchaseOrderService.php`
- Dependencies: `VatCalculationService`, `StockService`

Methods:
1. `recalculateItemTotals(SalesOrderItem)` — identical to PO pattern
2. `recalculateDocumentTotals(SalesOrder)` — identical to PO pattern
3. `transitionStatus(SalesOrder, SalesOrderStatus)` — valid transitions:
   - Draft → Confirmed, Cancelled
   - Confirmed → PartiallyDelivered, Delivered, Invoiced, Cancelled
   - PartiallyDelivered → Delivered, Cancelled
   - Delivered → Invoiced, Cancelled
   - On **Confirmed**: call `reserveAllItems()`
   - On **Cancelled**: call `unreserveRemainingItems()` + cascade cancel draft DNs/invoices
4. `reserveAllItems(SalesOrder)` — DB::transaction, for each stock-type item: `StockService::reserve(variant, warehouse, qty, $order)`
5. `unreserveRemainingItems(SalesOrder)` — for each item: unreserve `qty - qty_delivered` (only undelivered portion)
6. `updateDeliveredQuantities(SalesOrder)` — mirrors `PurchaseOrderService::updateReceivedQuantities()`: sums confirmed DN item quantities per SO item, auto-transitions to PartiallyDelivered/Delivered
7. `updateInvoicedQuantities(SalesOrder)` — sums confirmed invoice item quantities per SO item, auto-transitions to Invoiced when all fully invoiced

### Filament Resource

**Directory: `app/Filament/Resources/SalesOrders/`**
- NavigationGroup: `Sales`, navigationSort: 2

**SalesOrderForm** — mirror PurchaseOrderForm with deviations:
- Partner: `scopeCustomers()`
- `so_number` disabled+dehydrated
- Adds `quotation_id` Select (nullable, filtered by partner) with `afterStateUpdated` auto-filling currency/pricing from quotation
- `warehouse_id` required (not nullable)
- `issued_at` date field

**CreateSalesOrder** — mirror CreatePurchaseOrder:
- `SeriesType::SalesOrder`
- `mount()` handles `?quotation_id` query param: pre-fills partner, currency, exchange_rate, pricing_mode, warehouse from quotation

**ViewSalesOrder** — header actions:
- Confirm (Draft→Confirmed): triggers stock reservation
- Create Delivery Note (Confirmed/PartiallyDelivered): `?sales_order_id=` link
- Create Invoice (Confirmed/PartiallyDelivered/Delivered): `?sales_order_id=` link
- **Import to PO** (Confirmed+): modal — pick existing Draft PO or create new. Imports SO lines as PO lines with `sales_order_item_id` set. Does NOT advance PO status.
- Cancel: with cascade warning for draft DNs/invoices + unreservation warning
- Related documents: DeliveryNotes, CustomerInvoices

**SalesOrderItemsRelationManager** — mirror PurchaseOrderItemsRelationManager:
- Product variant auto-fills `sale_price` (not `purchase_price`)
- Table shows `qty_delivered` and `qty_invoiced` columns (read-only)

### SO → PO Import Action (on ViewSalesOrder)

Header action "Import to PO":
- Modal form: Select existing Draft PO (filtered by same warehouse + supplier, or "Create new PO")
- On submit: for each SO item → create PO item with `sales_order_item_id` set
- Never merge lines for same product from different SOs
- Repeatable (multiple SOs into same PO)

Also: batch action on ListSalesOrders — select multiple SOs → same modal → bulk import

---

## Sub-task 3.2.5 — DeliveryNote Resource ✅ DONE (2026-04-14, 445 tests)

### DeliveryNoteService

**File: `app/Services/DeliveryNoteService.php`**
- Mirror: `GoodsReceiptService.php`
- Dependencies: `StockService`, `SalesOrderService`

Methods:
1. `confirm(DeliveryNote)` — DB::transaction:
   - For each item (stock-type only, skip services): `StockService::issueReserved(variant, warehouse, qty, $dn)`
   - Set status = Confirmed, `delivered_at` = today
   - If SO-linked: `SalesOrderService::updateDeliveredQuantities($so)`
   - **Key difference from GRN**: uses `issueReserved()` not `receive()`
2. `cancel(DeliveryNote)` — only Draft. Set status = Cancelled.

### Filament Resource

**Directory: `app/Filament/Resources/DeliveryNotes/`**
- Mirror: `app/Filament/Resources/GoodsReceivedNotes/`
- NavigationGroup: `Sales`, navigationSort: 3

**DeliveryNoteForm** — mirror GRN form:
- `sales_order_id` Select replaces `purchase_order_id`, auto-fills partner + warehouse
- Partner: `scopeCustomers()`
- `delivered_at` replaces `received_at`

**CreateDeliveryNote** — `mount()` reads `?sales_order_id`, pre-fills from SO

**ViewDeliveryNote** — header actions:
- Confirm Delivery (Draft): calls `DeliveryNoteService::confirm()`
- Create Sales Return (Confirmed): `?delivery_note_id=` link
- Cancel (Draft)
- Related documents: SalesOrder, SalesReturns

**DeliveryNoteItemsRelationManager** — mirror GRN items RM:
- "Import from SO" header action: loads SO items with `remainingDeliverableQuantity() > 0`
- SO item selector auto-fills variant, quantity, unit_cost

**PDF Template**: `resources/views/pdf/delivery-note.blade.php`
- Header action "Print Delivery Note" on ViewDeliveryNote (visible when Confirmed)
- Uses `barryvdh/laravel-dompdf`

---

## Sub-task 3.2.6 — CustomerInvoice Resource ✅ DONE (2026-04-14, 445 tests)

### CustomerInvoiceService

**File: `app/Services/CustomerInvoiceService.php`**
- Mirror: `SupplierInvoiceService.php`
- Dependencies: `VatCalculationService`, `SalesOrderService`, `EuOssService`

Methods:
1. `recalculateItemTotals(CustomerInvoiceItem)` — standard pattern. **Handles negative quantities correctly** for advance deduction rows: VatCalculationService uses multiplication (`$net * $rate/100`), so negative net produces negative vat_amount naturally. Deduction rows MUST carry the same `vat_rate_id` as the original advance invoice item — never 0%.
2. `recalculateDocumentTotals(CustomerInvoice)` — standard pattern (amount_due = total - amount_paid). Negative deduction rows reduce subtotal, tax_amount, and total correctly via summation.
3. `confirm(CustomerInvoice)`:
   - Set status = Confirmed
   - If SO-linked: `SalesOrderService::updateInvoicedQuantities()`
   - If SO-linked and service lines exist: set `qty_delivered` on SO items for service lines
   - If cash payment: dispatch `FiscalReceiptRequested` event
   - If applicable: `EuOssService::accumulate()`
4. `applyReverseCharge(CustomerInvoice)`:
   - Guard: `partner.country_code !== tenant.country_code` AND partner.country_code is EU AND partner.hasValidEuVat()
   - Sets `is_reverse_charge = true`
   - Forces all item VAT to 0% (sets `vat_rate_id` to 0% rate, recalculates)
5. `checkAndApplyOss(CustomerInvoice)`:
   - Guard: partner is B2C (no valid VAT), partner.country_code is EU, different from tenant
   - Checks `EuOssAccumulation::isThresholdExceeded(year)`
   - If yes: applies destination-country VAT rate from `EuCountryVatRate::getStandardRate(partner.country_code)`

### EuOssService

**File: `app/Services/EuOssService.php`**
Methods:
1. `shouldApplyOss(Partner, Tenant): bool` — B2C + cross-border EU + threshold exceeded
2. `accumulate(CustomerInvoice)` — converts total to EUR, updates EuOssAccumulation
3. `getDestinationVatRate(string $countryCode): float` — looks up EuCountryVatRate

### Filament Resource

**Directory: `app/Filament/Resources/CustomerInvoices/`**
- Mirror: `app/Filament/Resources/SupplierInvoices/`
- NavigationGroup: `Sales`, navigationSort: 4

**CustomerInvoiceForm** — mirror SupplierInvoiceForm with deviations:
- Partner: `scopeCustomers()`
- `invoice_number` disabled+dehydrated (auto-generated)
- `sales_order_id` replaces `purchase_order_id`
- Adds `invoice_type` Select (InvoiceType enum, default Standard)
- `is_reverse_charge` Toggle (disabled, auto-set by service)
- No `supplier_invoice_number` or `received_at`

**CreateCustomerInvoice**:
- `SeriesType::Invoice`
- `mount()` reads `?sales_order_id`, pre-fills from SO

**ViewCustomerInvoice** — header actions:
- Confirm (Draft→Confirmed): calls `CustomerInvoiceService::confirm()`
- Create Credit Note (Confirmed): `?customer_invoice_id=` link
- Create Debit Note (Confirmed): `?customer_invoice_id=` link
- Apply Advance Payment: modal form — select from open advance payments for this partner, enter amount, calls `AdvancePaymentService::applyToFinalInvoice()`
- Cancel
- Related: CreditNotes, DebitNotes, AdvancePaymentApplications

**CustomerInvoiceItemsRelationManager** — mirror SupplierInvoiceItemsRelationManager:
- "Import from SO" action: loads SO items with `remainingInvoiceableQuantity() > 0`

**PDF Template**: `resources/views/pdf/customer-invoice.blade.php`
- Mirror existing supplier invoice PDF structure if one exists, otherwise build from quotation PDF pattern
- Header action "Print Invoice" on ViewCustomerInvoice (visible when Confirmed)
- Uses `barryvdh/laravel-dompdf`

---

## Sub-task 3.2.7 — CustomerCreditNote + CustomerDebitNote Resources ✅ DONE (2026-04-14, 453 tests)

### CustomerCreditNoteService — mirror `SupplierCreditNoteService` exactly
- `recalculateItemTotals()`, `recalculateDocumentTotals()` — identical pattern

### CustomerDebitNoteService — same structure as CreditNote service
- `recalculateItemTotals()`, `recalculateDocumentTotals()`

### CustomerCreditNote Resource
- Mirror: `app/Filament/Resources/SupplierCreditNotes/`
- NavigationGroup: `Sales`, navigationSort: 5
- Form: `customer_invoice_id` replaces `supplier_invoice_id`, partner `scopeCustomers()`
- `CreateCustomerCreditNote`: `SeriesType::CreditNote`, mount reads `?customer_invoice_id`
- Items RM: quantity-constrained with `lockForUpdate()` in `DB::transaction()`, links to CustomerInvoiceItem

### CustomerDebitNote Resource
- NavigationGroup: `Sales`, navigationSort: 6
- Form: similar to CreditNote but uses `DebitNoteReason`, `debit_note_number`
- `CreateCustomerDebitNote`: `SeriesType::DebitNote`
- Items RM: NO quantity constraint (amount-only), `product_variant_id` optional

---

## Sub-task 3.2.8 — SalesReturn Resource ✅ DONE (2026-04-14, 460 tests)

### SalesReturnService — mirror `PurchaseReturnService`
- Dependencies: `StockService`
- `confirm(SalesReturn)`: for each item → `StockService::receive(variant, warehouse, qty, $return, MovementType::SalesReturn)`. Set status = Confirmed, `returned_at` = today.
- `cancel(SalesReturn)`: only Draft. Set status = Cancelled.

### Filament Resource
- Mirror: `app/Filament/Resources/PurchaseReturns/`
- NavigationGroup: `Sales`, navigationSort: 7

**ViewSalesReturn** — header actions:
- Confirm Return: calls `SalesReturnService::confirm()`
- **After confirmation notification**: suggest creating a Credit Note. Action button navigates to CustomerCreditNote create. If SO has one invoice → pass `?customer_invoice_id=` directly. If multiple invoices → modal to select which invoice.
- Create Return From DN: links via `?delivery_note_id=`
- Cancel

**SalesReturnItemsRelationManager** — mirror PurchaseReturnItemsRelationManager:
- "Import from DN" action: loads DN items with `remainingReturnableQuantity() > 0`
- Quantity validation with `lockForUpdate()` in `DB::transaction()`

---

## Sub-task 3.2.9 — AdvancePayment Resource ✅ DONE (2026-04-14, 476 tests)

### AdvancePaymentService (no purchase mirror)

Methods:
1. `confirm(AdvancePayment)` — set status = Confirmed. If cash → dispatch `FiscalReceiptRequested` (with advance invoice if one exists)
2. `createAdvanceInvoice(AdvancePayment): CustomerInvoice` — creates CustomerInvoice with `invoice_type = Advance`, single line item (description: "Advance payment", qty: 1, unit_price: amount). Auto-confirms. Links payment → invoice via `customer_invoice_id`.
3. `applyToFinalInvoice(AdvancePayment, CustomerInvoice, float $amount): AdvancePaymentApplication` — validates amount ≤ remainingAmount(), creates pivot record, updates `amount_applied`, auto-transitions to PartiallyApplied/FullyApplied. **Also adds negative deduction row(s) to the final invoice**: quantity=-1, unit_price=amount, carrying the **same VAT rate as the original advance invoice item** (producing negative `vat_amount`). This ensures net VAT on the final invoice is correct. Do NOT set deduction VAT to 0%.
4. `refund(AdvancePayment)` — only if status is Open/PartiallyApplied. Transitions to Refunded.

### Filament Resource
- NavigationGroup: `Sales`, navigationSort: 8
- No items relation manager (single-amount document)

**AdvancePaymentForm**: `ap_number` disabled+dehydrated, partner `scopeCustomers()`, `sales_order_id` nullable Select, amount decimal, payment_method, currency, exchange_rate, received_at, notes

**AdvancePaymentsTable**: ap_number, partner.name, status badge, amount, amount_applied, remaining (computed), received_at

**ViewAdvancePayment** — header actions:
- Confirm (Open→Confirmed)
- Issue Advance Invoice: calls `AdvancePaymentService::createAdvanceInvoice()`, redirects
- Refund (Open/PartiallyApplied→Refunded)
- Related: AdvancePaymentApplications list

---

## Sub-task 3.2.10 — Tests ✅ DONE (2026-04-14, 513 tests)

All tests use Pest syntax in `tests/Feature/`. Mirror patterns from `tests/Feature/PurchaseOrderTest.php` and `tests/Feature/GoodsReceivedNoteTest.php`.

| Test File | Key Scenarios |
|-----------|--------------|
| `StockReservationTest.php` | reserve increases reserved_qty; reserve throws on insufficient; unreserve decreases; `issueReserved()` atomic decrement of both qty and reserved_qty; issueReserved throws on insufficient; creates StockMovement with MovementType::Sale |
| `QuotationTest.php` | CRUD, status transitions, convert to SO copies all data, partner-must-be-customer |
| `SalesOrderTest.php` | CRUD, status transitions, confirm reserves stock, cancel unreserves, partial delivery status update, invoice status update, partner-must-be-customer |
| `DeliveryNoteTest.php` | CRUD, confirm issues reserved stock (not regular stock), SO qty_delivered update, import from SO, confirmed = immutable |
| `CustomerInvoiceTest.php` | CRUD, confirm updates SO qty_invoiced, import from SO, service lines set qty_delivered, FiscalReceiptRequested dispatched on cash confirm |
| `ReverseChargeTest.php` | Intra-EU B2B triggers reverse charge (VAT→0%); domestic no trigger; non-EU no trigger; B2C no trigger (OSS instead) |
| `EuOssTest.php` | Accumulation tracking; threshold detection at €10,000 across all countries; OSS VAT rate lookup; shouldApplyOss logic |
| `CustomerCreditNoteTest.php` | Quantity-constrained crediting with lockForUpdate; single CN, multiple CNs; CRUD |
| `CustomerDebitNoteTest.php` | CRUD, amount-only (no quantity constraint), confirm increases invoice amount_due |
| `SalesReturnTest.php` | Confirm receives stock back (MovementType::SalesReturn); quantity validation; CRUD |
| `AdvancePaymentTest.php` | CRUD, confirm, create advance invoice, apply to final invoice (negative deduction rows), fully applied status, cannot cancel with applications |
| `SalesPolicyTest.php` | sales-manager, accountant, warehouse-manager permissions for all Sales documents |

---

## Sub-task 3.2.11 — Docs Update + Pint + Final Test Run ✅ DONE (2026-04-14, 513 tests)

- Update `docs/STATUS.md` — Phase 3.2 complete, test count
- Update `docs/UI_PANELS.md` — add Sales navigation group with all 8 resources
- Run `vendor/bin/pint --dirty --format agent`
- Run `./vendor/bin/sail artisan test --parallel --compact`

---

## Sub-task 3.2.12 — Refactor Phase

Structured review → `tasks/phase-3.2-refactor.md` (mirrors Phase 3.1.12 approach from `tasks/phase-3.1-refactor.md`). This is a post-implementation review, not part of the initial build.

---

### Review Context — Read This Before Starting

**Who is driving this review:**
The product owner has deep knowledge of Bulgarian commercial and tax law (ZDDS — Закон за данъка върху добавената стойност, and Bulgarian Accounting Act). His perspective is the primary input for Bulgarian-specific requirements but is explicitly **not** the final word on EU-wide behavior. He has acknowledged this and asked to be challenged.

**The AI assistant's role in this review:**
- Hold the ERP industry standard (reference models: SAP, Oracle, Dynamics). If our implementation deviates, name it.
- Validate Bulgarian requirements against the EU VAT Directive 2006/112/EC. Bulgaria's ZDDS is a transposition of that directive — most rules are directionally correct for the EU, but details differ by country.
- When a requirement is Bulgaria-specific (not in the EU Directive), flag it explicitly and ensure it goes behind a config flag, not hardcoded.
- Push back when a suggested "fix" would create a bigger problem or violates ERP convention. Do not agree by default.

**The working principle on EU compliance:**
> Rules from the EU VAT Directive 2006/112/EC are hardcoded. Rules from national transpositions (e.g. Bulgarian ZDDS, Italian SDI, German GoBD) go behind country/config flags.

**Fiscal compliance scope:**
Bulgaria uses SUPTO fiscal printers — this is unique to Bulgaria. Other EU countries have different obligations (Italy: mandatory e-invoicing via SDI; Germany: GoBD + planned e-invoicing mandate; France: Chorus Pro for B2G). Never hardcode Bulgarian fiscal device behavior as if it is EU-wide.

**Review methodology:**
1. One Sales nav item at a time (Quotations → SalesOrders → DeliveryNotes → CustomerInvoices → CreditNotes → DebitNotes → SalesReturns → AdvancePayments)
2. AI reads code and states what it DOES vs. what it SHOULD do
3. Product owner manually tests in the running app
4. Discussion — AI challenges, product owner pushes back, both sides must convince each other
5. Agreed findings written to `tasks/phase-3.2-refactor.md` (fix) or `tasks/backlog.md` (out of scope improvement)
6. AI confirms all Sales functionality has been covered before closing the review

**Advisor tool:** Call it at the start of the review (before writing anything) and at the end (before finalizing the refactor doc). This is core app functionality — use the advisor proactively whenever a finding is architecturally significant.

---

### Pre-session findings (collected before full structured review)

---

---

### Sales View Pages — Infrastructure (INFRA-V)

---

#### INFRA-V1: Replace custom Blade view with `content()` override + proper infolists on all Sales view pages

**What:** All 8 Sales resource View pages override `protected string $view` to point to the shared Blade template `view-document-with-items.blade.php`. That template hardcodes a fixed layout: empty-items warning, then `{{ $this->content }}` (which renders a **disabled form** — no `infolist()` is defined on any Sales resource), then Related Documents fixed at the bottom. This bypasses Filament v5's native layout system entirely.

**Why:**
1. No `infolist()` defined → the View page shows a **disabled form** (edit fields greyed out), not a read-optimised display. ERP document views should show status badges, formatted money, prominent totals — not disabled input fields.
2. Custom Blade template → layout is not under schema control. Related Documents is hardcoded at the bottom when it belongs near the top (user needs navigation context immediately after identifying the document).
3. Filament v5 provides `content()` override on page classes and a dedicated `infolist()` API — the framework's intended solution.

**How:**
1. Add `infolist(Schema $schema)` to each of the 8 Sales resources.
2. **Infolist section order** — consistent across all 8, adapted per resource:
   - **Identity**: document number, status badge (with icon + color), customer/partner, issued date
   - **Related Documents**: linked upstream/downstream documents with status badges and direct links — rendered as a `View` schema component (keeps existing badge/link HTML, just repositioned)
   - **Financial Summary**: subtotal, VAT, total — formatted as money with dynamic currency
   - **Secondary Details**: currency, exchange rate, payment terms, delivery date, warehouse (collapsed or toggleable)
   - **Notes**: customer-facing notes, internal notes (collapsed by default)
3. **Line items** stay as a `RelationManager` — rendered via `getRelationManagersContentComponent()` in a `content()` override on the page class, positioned after the infolist.
4. **Empty items warning**: move from Blade to a conditional infolist `Section` component with `->visible(fn ($record) => $record->isEditable() && !$record->items()->exists())`.
5. Override `content()` on each View page class: `[$this->getInfolistContentComponent(), $this->getRelationManagersContentComponent()]`. No custom `$view` property needed.
6. Once all 8 resources are migrated, **delete** `view-document-with-items.blade.php` and `view-document.blade.php`.

**Resources affected:** QuotationResource, SalesOrderResource, DeliveryNoteResource, CustomerInvoiceResource, CustomerCreditNoteResource, CustomerDebitNoteResource, SalesReturnResource, AdvancePaymentResource.

---

### Sales List Views — Infrastructure (INFRA-L)

---

The following issues appear identically in every Sales resource list view. They are documented once here. When implementing, fix all 8 resources in one pass.

**Affected resources:** QuotationResource, SalesOrderResource, DeliveryNoteResource, CustomerInvoiceResource, CustomerCreditNoteResource, CustomerDebitNoteResource, SalesReturnResource, AdvancePaymentResource.

---

#### INFRA-L1: Total column hardcoded to EUR on all 8 list views

**What:** Every Sales list table column for `total` (or `amount`) uses `->money('EUR')` hardcoded. All Sales documents carry `currency_code`.

**Why:** An invoice issued in USD shows the amount labelled as EUR. Wrong currency on a customer-facing document list is a data integrity problem, not just a display bug.

**How:** Change each `total` column to `->money(fn ($record) => $record->currency_code)`. Add a `currency_code` column (toggleable, hidden by default) next to `total` so the currency is visible per row. Apply to all 8 resources.

---

#### INFRA-L2: Default sort is `issued_at desc` — NULLs sink in PostgreSQL

**What:** List views sort by `issued_at desc`. `issued_at` is nullable on all Sales documents. In PostgreSQL, NULLs sort last in descending order, so draft documents with no issue date sink to the bottom of the list.

**Why:** A draft Sales Order or draft Invoice has not been formally issued yet — `issued_at` is intentionally null. The list should show the most recently created records first, regardless of whether they have been issued.

**How:** Change default sort to `->defaultSort('created_at', 'desc')` on all 8 resources. `created_at` is never null (set by Eloquent on insert). Add `issued_at` as a toggleable column so it remains filterable/sortable on demand.

---

#### INFRA-L3: No customer/partner filter on any Sales list view

**What:** All 8 Sales list views have status + trashed filters only. None has a partner/customer filter.

**Why:** "Show me all open invoices for Customer X" and "Show me all quotations for this partner" are the most common daily operations in any sales module.

**How:** Add to all 8 resources: `SelectFilter::make('partner_id')->label('Customer')->relationship('partner', 'name')->searchable()->preload()`.

---

### Quotations — List View (QUO-L)

---

#### QUO-L1: Total column hardcoded to EUR

**What:** The `total` column in the Quotations list uses `->money('EUR')` hardcoded. The `Quotation` model has a `currency_code` field.

**Why:** A quotation issued in USD displays the amount labelled as EUR. Wrong currency on a customer-facing document is a data integrity problem.

**How:** Change to `->money(fn (Quotation $record) => $record->currency_code)`. Add a `currency_code` column (toggleable, hidden by default) next to `total` so the currency is visible in the row.

---

#### QUO-L2: `issued_at` — DB nullable but form required; default sort is wrong

**What:** The DB column `issued_at` is nullable. The form marks it `->required()` with a default of today. The default sort is `issued_at desc` — NULLs sort last in PostgreSQL, so any record with a null `issued_at` sinks to the bottom.

**Why:** `issued_at` is the date you send the quotation to the customer — not the same as the creation date. A draft may legitimately exist without an issue date (created today, sent tomorrow). DB nullable is correct. The form requiring it forces the issue date to equal the creation date, which is wrong.

**How:** Remove `->required()` from `issued_at` in the form (keep `->default(now())` as a convenience pre-fill only). Change default sort to `created_at desc` so newest records always appear at the top regardless of issue date.

---

#### QUO-L3: Expired quotations have no visual indicator

**What:** The `valid_until` column shows the date but applies no visual treatment when the date is in the past. The `Quotation` model has `isExpired()`. The `QuotationStatus::Expired` enum case exists but is never auto-set.

**Why:** A Sent quotation past its `valid_until` date is commercially dead but still shows neutral styling. A sales rep reviewing their list cannot distinguish live from stale offers at a glance.

**How:** On the `valid_until` TextColumn add `->color(fn (Quotation $record) => $record->isExpired() ? 'danger' : null)`. This is a display-only fix. The auto-expiry job (status transition) is a separate backlog item.

---

#### QUO-L4: No customer/partner filter

**What:** The list has a status filter and a trashed filter. No filter for customer/partner.

**Why:** "Show me all quotations for Customer X" is the most common daily operation in any sales module.

**How:** Add `SelectFilter::make('partner_id')->label('Customer')->relationship('partner', 'name')->searchable()->preload()`.

---

#### QUO-L5: Bulk delete has no guard against quotations linked to a Sales Order

**What:** `DeleteBulkAction` can delete quotations that have been converted to a Sales Order. The SO's `quotation_id` is `nullOnDelete` — so no DB error, but the SO silently loses its source document link.

**Why:** Historical traceability — the audit trail from SO back to the originating quotation is permanently destroyed. No warning is shown.

**How:** Override the bulk delete action to exclude quotations that have `salesOrders()->exists()`. Show a warning notification listing how many records were skipped and why.

---

#### QUO-L6: No item count column

**What:** The list has no column showing how many line items a quotation has. A Draft with 0 items and a Draft with 15 items look identical.

**Why:** A sales rep working through a list needs to know at a glance which drafts are empty (need work) vs. populated (ready to send).

**How:** Add `TextColumn::make('items_count')->counts('items')->label('Items')->sortable()`.

---

### Quotations — Create / Edit View (QUO-C)

---

#### QUO-C1: Edit page has no editable guard

**What:** `EditQuotation` has header actions (View, Delete, ForceDelete, Restore) but no guard against editing a non-Draft quotation. A Sent, Accepted, or Rejected quotation can be fully edited by navigating directly to `/quotations/{id}/edit`.

**Why:** `isEditable()` is tied to `Draft` status only. Any other status means the quotation has been acted on — a customer has seen it (Sent), responded to it (Accepted/Rejected), or been issued a Sales Order from it. Editing a Sent quotation and re-sending it would silently change the document the customer received.

**How:** In `EditQuotation`, override `authorizeAccess()` to redirect with a warning notification if `!$record->isEditable()`. Add `->hidden(fn (Quotation $record) => !$record->isEditable())` to the Edit header action on `ViewQuotation` as well.

---

#### QUO-C2: Inactive partner not available in edit form

**What:** `partner_id` in `QuotationForm` uses `Partner::customers()->where('is_active', true)` as the options query. If a partner was active when the quotation was created but was deactivated later, the edit form silently drops the current value from the options list.

**Why:** The Select field does not show the current (now inactive) partner, which can cause silent data loss if the form is saved — the `partner_id` field submits null or fails validation.

**How:** Change the options query to include the currently-selected partner regardless of active status:
```php
->options(fn (?string $state) => Partner::customers()
    ->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $state))
    ->pluck('name', 'id'))
```
Or use Filament's `->getOptionLabelUsing()` pattern so the saved value always resolves its label even if not in the base query.

---

#### QUO-C3: `valid_until` accepts dates in the past

**What:** The `valid_until` date field in `QuotationForm` has no minimum date constraint. A user can set `valid_until` to yesterday, which means the quotation is expired before it is sent.

**Why:** An immediately-expired quotation is commercially meaningless and will be flagged as expired (QUO-L3 visual fix, SALES-1 auto-expiry job) the moment it is created.

**How:** Add `->minDate(today())` to the `valid_until` DatePicker. On **Edit** only (not Create), allow the existing date to be seen even if already in the past — use `->minDate(fn (?Carbon $state) => $state?->isPast() ? $state : today())` so editing an already-expired quotation doesn't invalidate the field on open.

---

#### QUO-C5: Items table shows net total only — gross missing

**What:** The `QuotationItemsRelationManager` table has a `line_total` column labelled "Net Total" but no `line_total_with_vat` column. The `quotation_items` table has `line_total_with_vat` populated by `QuotationService`.

**Why:** A sales rep issuing a quotation needs to see the gross (VAT-inclusive) amount per line — that is the amount the customer pays. Showing only the net total forces the rep to do mental arithmetic.

**How:** Add `TextColumn::make('line_total_with_vat')->label('Total (incl. VAT')->numeric(2)` after `line_total`. Keep `line_total` (renamed label to "Net") and `vat_amount` as toggleable columns — they're useful for VAT-registered customers who buy net.

---

### Quotations — View Page (QUO-V)

---

#### QUO-V1: "Convert to Sales Order" button stays visible after conversion — duplicate SO risk

**What:** `convert_to_so` header action is always visible when `status === Accepted`. `QuotationService::convertToSalesOrder()` deliberately does not change the quotation status (full copy of all items, no partial-conversion scenario). After the first SO is created, the button remains active and clicking it again creates a full duplicate order with no warning.

**Why:** Without a guard, N SOs can be generated from one accepted quotation. There is no partial-conversion use case in the current implementation — every call copies all items, so a second SO is always a duplicate.

**How:** Change the action's `->visible()` to `fn (Quotation $record): bool => $record->status === QuotationStatus::Accepted && !$record->salesOrders()->exists()`. No "View SO" button is added to the header — the Related Documents section (repositioned near the top by INFRA-V1) provides direct navigation to the existing SO.

---

#### QUO-V2: Print actions too restrictive — terminal statuses have zero actions

**What:** `print_offer` is visible only for `Sent`. `print_proforma` is visible only for `Sent` or `Accepted`. A quotation in `Expired` or `Rejected` status has **zero header actions** — including no way to print the historical document.

**Why:** Both print actions are read-only (PDF generation, no state change). Restricting them by status serves no business purpose. A sales rep working a `Draft` needs an offer preview before sending. A manager auditing a `Rejected` deal needs the original offer for the paper trail.

**How:**
- `print_offer`: remove the status condition — visible for all statuses (or at minimum: `$record->status !== QuotationStatus::Cancelled`).
- `print_proforma`: expand to all non-Draft, non-Cancelled statuses: `!in_array($record->status, [QuotationStatus::Draft, QuotationStatus::Cancelled])`.

---

### Sales Orders (SO)

---

#### SO-B1: `issued_at` null not enforced at service layer — auto-set on `Draft → Confirmed` transition

**What:** `SalesOrderForm` marks `issued_at` as `->required()` with `->default(now())`, but `QuotationService::convertToSalesOrder()` creates the SO without `issued_at` in the payload — bypassing form validation. SOs created from the conversion flow always have `issued_at = null`.

**Why:** `->required()` on a form field is a UI-only constraint. Service-layer calls (conversion, future API, tinker) bypass it. An Invoiced SO with `issued_at = null` is an accounting data integrity problem.

**How:** In `SalesOrderService::transitionStatus()`, when transitioning from `Draft → Confirmed`, auto-set `issued_at = today()` **if the current value is null**: `$order->issued_at ??= today()->toDateString()`. Do NOT set it in `convertToSalesOrder()` — the SO may be drafted for days before formal issuance. Do NOT set it at creation time.

---

#### SO-L1: `expected_delivery_date` overdue — no visual indicator

**What:** `expected_delivery_date` column shows the date with no visual treatment when the date is in the past and the SO is still in an open status (`Draft`, `Confirmed`, `PartiallyDelivered`).

**Why:** An open SO past its expected delivery date is a fulfilment risk. Operations needs to see overdue orders at a glance without sorting/filtering.

**How:** Add `->color(fn (SalesOrder $record) => $record->expected_delivery_date && $record->expected_delivery_date->isPast() && !in_array($record->status, [SalesOrderStatus::Delivered, SalesOrderStatus::Invoiced, SalesOrderStatus::Cancelled]) ? 'danger' : null)` to the `expected_delivery_date` column.

---

#### SO-C1: Edit page accessible via direct URL regardless of order status (systemic)

**What:** `EditSalesOrder` returns `[ViewAction, DeleteAction, ForceDeleteAction, RestoreAction]` with no `isEditable()` guard. The `EditAction` on the View page correctly gates access with `->visible(fn ($record) => $record->isEditable())`, but a user navigating directly to `/admin/sales-orders/{id}/edit` bypasses this gate entirely and can edit a Confirmed or Invoiced order.

**Why:** Same systemic issue as QUO-C1. Applies to all 8 Sales Edit page classes.

**How:** Override `getHeaderActions()` in `EditSalesOrder` (and all other Sales edit pages) to redirect non-editable records back to the View page. Or: add `mount()` guard: `if (!$this->getRecord()->isEditable()) { $this->redirect(static::getResource()::getUrl('view', ['record' => $this->getRecord()])); }`.

**Note:** This is a cross-cutting fix — apply to all 8 Sales edit page classes in one pass.

---

#### SO-C2: Dead `mount()` pre-fill code in `CreateSalesOrder` — remove

**What:** `CreateSalesOrder::mount()` reads `?quotation_id=` from the URL and pre-fills the form with the quotation's partner, currency, and pricing. The "Convert to SO" action on the Quotation View page calls `QuotationService::convertToSalesOrder()` directly — it never navigates to the create page. No current UI path passes `quotation_id` in the URL.

**Why:** Dead code with no UI entry point. If a future flow needs URL-based pre-fill, it should be added when that use case is defined.

**How:** Remove the entire `mount()` override from `CreateSalesOrder`. Keep `beforeCreate()` and `mutateFormDataBeforeCreate()`.

---

#### SO-C3: `quotation_id` dropdown shows `Sent` quotations — should be `Accepted` only

**What:** The `quotation_id` Select in `SalesOrderForm` uses `whereIn('status', ['accepted', 'sent'])`. A Sent quotation is still awaiting the customer's response.

**Why:** Linking an SO to a Sent quotation bypasses the `Accepted` status transition. The quotation officially remains "pending" while an SO already exists against it. The correct flow is: mark quotation `Accepted` → convert to SO.

**How:** Change to `->where('status', 'accepted')`. If the user wants to create an SO manually against an accepted quotation, the field remains available. Quotations in any other status are not eligible.

---

#### SO-V1: `import_to_po` has no duplicate guard — re-triggering duplicates all PO items

**What:** The `import_to_po` action on the SO View page iterates `$record->items` and calls `$po->items()->create(...)` for each. If the action is triggered twice against the same existing PO (user clicks it, misreads the confirmation, triggers again), every SO item is duplicated silently. No check for "has this SO's items already been added to this PO."

**Why:** Silent data duplication on a procurement document. The PO receives double quantities before the buyer notices.

**How:** Before adding items, check: `$po->items()->where('sales_order_item_id', $item->id)->exists()`. Skip (or warn) items already present. Alternatively, add a unique constraint on `(purchase_order_id, sales_order_item_id)` in the migration.

---

#### SO-V2: SO view page missing upstream link to source Quotation

**What:** `getRelatedDocuments()` on `ViewSalesOrder` includes `deliveryNotes` and `customerInvoices` but not the parent `Quotation`. An SO created via `convertToSalesOrder()` has `quotation_id` set — the link exists in the DB but is invisible in the UI.

**Why:** Navigation is one-directional: you can go SO → DN, SO → Invoice, but not SO → Quotation. A sales rep reviewing an SO cannot quickly check what was originally quoted — price, validity date, original notes.

**How:** When building the SO infolist as part of INFRA-V1, include the source Quotation in the Related Documents section (nullable — only rendered when `quotation_id` is set). Downstream links (Delivery Notes, Customer Invoices) remain as-is. See INFRA-V1 for the infolist implementation pattern.

---

### Delivery Notes — List View (DN-L)

---

#### DN-L1: No item count column

**What:** The DN list has no column showing how many line items a delivery note has. A draft with 0 items and one with 10 items look identical.

**Why:** A warehouse operator working through a list needs to know at a glance which drafts are empty (need work) vs. populated (ready to confirm).

**How:** Add `TextColumn::make('items_count')->counts('items')->label('Items')->sortable()`.

---

#### DN-L2: No `created_at` column

**What:** The list has no `created_at` column. Once the default sort is changed to `created_at desc` (INFRA-L2), users have no visible reference for when a DN was created.

**How:** Add `TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true)`.

---

### Delivery Notes — Create / Edit View (DN-C)

---

#### DN-C1: `warehouse_id` not locked when SO is selected

**What:** When a `sales_order_id` is selected in the form, `partner_id` is correctly disabled+dehydrated (locked to the SO's partner). But `warehouse_id` remains editable — the user can change it to a different warehouse than the one on the SO.

**Why:** Stock was reserved against the SO's warehouse via `SalesOrderService::reserveAllItems()`. `DeliveryNoteService::confirm()` calls `StockService::issueReserved()` using the DN's `warehouse_id`. If these differ, the issue hits the wrong warehouse — the reservation is never consumed and stays locked indefinitely.

**How:** Add `->disabled(fn (Get $get): bool => !empty($get('sales_order_id')))->dehydrated()` to the `warehouse_id` field, mirroring the existing `partner_id` pattern.

---

#### DN-C2: `delivered_at` should have no default value on create

**What:** `delivered_at` DatePicker has `->default(now()->toDateString())` on the create form. A DN can legitimately sit in Draft for hours or days before goods are dispatched.

**Why:** Pre-filling today's date implies delivery happened now, which is factually wrong for a draft. It will mislead anyone reading the draft DN.

**How:** Remove `->default(now()->toDateString())` from `delivered_at`. `DeliveryNoteService::confirm()` already sets `delivered_at = today()` on confirmation — that is the correct and only place this date should be set automatically.

---

#### DN-V1: ViewDeliveryNote missing "Create Invoice" action

**What:** The DN view page has no "Create Invoice" header action. After confirming a delivery, the user must navigate back to the SO to invoice.

**Why:** The natural goods flow is SO → DN → CI. Once a DN is confirmed, stock has been issued and it's the right moment to invoice. Forcing the user back to the SO to create the CI breaks this flow.

**How:** Add a `create_invoice` header action to `ViewDeliveryNote`, visible only when `$record->status === DeliveryNoteStatus::Confirmed && $record->sales_order_id !== null`. URL: `CustomerInvoiceResource::getUrl('create') . '?sales_order_id=' . $record->sales_order_id`. This lands on the same CI create form already used from the SO view — no new logic needed.

**Guard note:** The "Import from SO" action in `CustomerInvoiceItemsRelationManager` already filters by `remainingInvoiceableQuantity() > 0` (qty_ordered − qty_invoiced). A sequential duplicate (CI already confirmed from SO, then user navigates to DN and clicks this button) will land on the create form and see "No remaining items to import" — no duplicate is possible in the sequential flow.

---

### Customer Invoices — List View (CI-L)

---

#### CI-L1: Missing `invoice_type` column and insufficient filters

**What:** The CI list has no `invoice_type` column and only two filters (status, trashed). Standard and Advance invoices are visually indistinguishable in the list. There is no way to filter by type, by customer, or by issue date range.

**Why:** Customer Invoice is the primary financial document reviewed by accounting. Accounting workflows are period-based — "show me all invoices issued this month" is the most common daily query. Mixing Standard and Advance invoices without distinction makes the list harder to read. Customer filtering and date range filtering are essential for AR management.

**How:**

Add `invoice_type` badge column (after `status`):
```php
TextColumn::make('invoice_type')->badge()->sortable(),
```

Add these filters:

```php
SelectFilter::make('invoice_type')
    ->options(InvoiceType::class),

SelectFilter::make('partner_id')
    ->label('Customer')
    ->relationship('partner', 'name')
    ->searchable()
    ->preload(),

Filter::make('issued_at')
    ->form([
        Select::make('preset')
            ->label('Period')
            ->options([
                'today'      => 'Today',
                'this_week'  => 'This week',
                'this_month' => 'This month',
                'custom'     => 'Custom range',
            ])
            ->live(),
        DatePicker::make('from')
            ->label('From')
            ->visible(fn (Get $get): bool => $get('preset') === 'custom'),
        DatePicker::make('to')
            ->label('To')
            ->visible(fn (Get $get): bool => $get('preset') === 'custom'),
    ])
    ->query(function (Builder $query, array $data): Builder {
        return match ($data['preset'] ?? null) {
            'today'      => $query->whereDate('issued_at', today()),
            'this_week'  => $query->whereBetween('issued_at', [now()->startOfWeek(), now()->endOfWeek()]),
            'this_month' => $query->whereBetween('issued_at', [now()->startOfMonth(), now()->endOfMonth()]),
            'custom'     => $query
                ->when($data['from'], fn ($q) => $q->whereDate('issued_at', '>=', $data['from']))
                ->when($data['to'],   fn ($q) => $q->whereDate('issued_at', '<=', $data['to'])),
            default => $query,
        };
    })
    ->indicateUsing(function (array $data): ?string {
        return match ($data['preset'] ?? null) {
            'today'      => 'Period: Today',
            'this_week'  => 'Period: This week',
            'this_month' => 'Period: This month',
            'custom'     => 'Period: ' . ($data['from'] ?? '…') . ' → ' . ($data['to'] ?? '…'),
            default      => null,
        };
    }),
```

**Note on invoice number search:** `invoice_number` is already `->searchable()` on the column — covered by the global table search box. No dedicated filter needed.

**Note on INFRA:** `partner_id` filter is the same as INFRA-L3 but confirmed required for CI. The implementing agent should ensure INFRA-L3 does not add a duplicate when CI-L1 is applied.

---

#### CI-1: Confirming a Customer Invoice without a prior Delivery Note leaves stock in limbo

**What:** A Customer Invoice for stock-type items can be confirmed without any corresponding confirmed Delivery Note. No stock is issued, `qty_delivered` stays 0, no `StockMovement` is created, and the SO's reserved stock is locked permanently.

**Why:** `CustomerInvoiceService::confirm()` correctly never touches `StockService` — stock issuance belongs to the DN. But the pipeline doesn't enforce that a DN must be confirmed before invoicing stock items. The "Create Invoice" button is visible on a `Confirmed` SO with zero confirmed DNs.

**How:** On CI confirmation, check if any line items are stock-type with `sales_order_item_id` set and `qty_delivered < quantity_being_invoiced`. If so, surface a **warning notification** (not a block — advance invoicing and deposits are valid business cases): *"X line item(s) have not been shipped yet. Confirm a Delivery Note to update stock levels."* Also add a **"Create Delivery Note" navigation button** in the notification or as a separate action so the user can jump directly to DN creation without manually navigating to the SO first.

**Backlog — SALES-5:** A future tenant setting `sales.express_delivery` (off by default) would mirror Express Purchasing: skip the standalone DN document, issue stock directly on CI confirmation for tenants that invoice and deliver simultaneously (e.g. counter sales). Bulgarian Счетоводен закон requires a delivery document for goods transport, so this setting must be opt-in and clearly labelled as non-default. Added to `tasks/backlog.md`.

---

#### CI-2: Concurrent Draft invoices can over-invoice the same SO items

**What:** Two Draft CIs linked to the same SO can each import the full remaining quantity of the same SO item (because `remainingInvoiceableQuantity()` reads `qty_invoiced` which is only updated on CONFIRM). When both are confirmed, `updateInvoicedQuantities()` sums all confirmed CI items — resulting in `qty_invoiced > qty_ordered`.

**Why:** The guard lives at confirm-time, not at draft-creation time. There is no lock preventing a second draft from claiming quantities already claimed by a pending first draft.

**How:** In `CustomerInvoiceService::confirm()`, after `updateInvoicedQuantities()` runs, assert that no `SalesOrderItem.qty_invoiced > SalesOrderItem.quantity`. If over-invoicing is detected, throw and rollback with a clear error: *"Cannot confirm: invoice quantity exceeds remaining order quantity for [item]. Another invoice may have already been confirmed."*

---

#### CI-C1: `is_reverse_charge` is never set — every EU B2B cross-border invoice has incorrect VAT

**What:** The `is_reverse_charge` boolean exists on `CustomerInvoice` but no code path ever writes it. The form toggle is `->disabled()->dehydrated()` with no computation. `CreateCustomerInvoice::mount()` does not set it. `CustomerInvoiceService::confirm()` does not set it. Every invoice is confirmed with `is_reverse_charge = false` regardless of the partner's EU VAT status.

**Why:** An EU B2B cross-border invoice to a partner with a valid VAT number must carry 0% VAT and a reverse charge notation (VAT Directive 2006/112/EC, Art. 196). Charging standard-rate VAT on these invoices is non-compliant and exposes tenants to penalties from their national tax authority. This is not a UI polish issue — it is an app-breaking correctness gap.

**How:** See **VAT-DETERMINATION-1** in `tasks/backlog.md` (marked URGENT — APP-BREAKING). That item specifies the full design: `Tenant::hasValidVatNumber()` accessor, `ViesValidationService` extraction, VAT type determination locked at confirmation time, and UX partner-select hint.

---

#### CI-C2: `invoice_type` not pre-filled and not locked in the SO→CI workflow

**What:** `CreateCustomerInvoice::mount()` calls `$this->form->fill([...])` with SO data but omits `invoice_type`. Filament's `fill()` overwrites form state, so the schema default (`InvoiceType::Standard`) is lost and the field renders blank. Additionally, the field is not disabled in the SO→CI context, so the user can change it — which is meaningless, since CI from SO is always a Standard invoice (Advance CIs are auto-created and confirmed directly from `AdvancePaymentService`, never via this form).

**How:**
- `CreateCustomerInvoice::mount()` — add `'invoice_type' => InvoiceType::Standard->value` to the `fill()` array when filling from SO.
- `CustomerInvoiceForm.php` — on the `invoice_type` Select, add `->disabled(fn (Get $get): bool => filled($get('sales_order_id')))->dehydrated()` so the field is locked (but still submitted) when the CI is linked to an SO.

---

#### CI-V1: Advance Payment missing from Related Documents on the CI view page

**What:** `ViewCustomerInvoice::getRelatedDocuments()` links to the source SO, credit notes, and debit notes. When an `AdvancePayment` has been applied to the invoice (via `AdvancePaymentService::applyToFinalInvoice()`), there is no link to it. The user landing on the CI view page has no trace of which advances are applied or their amounts.

**How:** When INFRA-V1 rebuilds the CI view page as a proper infolist, the Related Documents section must include applied advance payments. Query via the `AdvancePaymentApplication` table (`where customer_invoice_id = $record->id`), load the linked `AdvancePayment`, and render each as a linked badge showing the AP number and status.

---

#### CI-V2: Cancel on Confirmed CI bypasses service layer — no data reversal

**What:** The Cancel action is visible for both `Draft` and `Confirmed` status. Its action body writes `$record->status = DocumentStatus::Cancelled; $record->save()` directly — no service call. When a Confirmed CI is cancelled, `qty_invoiced` on linked SO items stays elevated (set by `updateInvoicedQuantities()` at confirm, never reversed), `remainingInvoiceableQuantity()` is permanently understated blocking re-invoicing, and any EU OSS accumulations are not reversed.

**Why the action must stay:** Bulgarian law (Наредба №Н-18, ЗДДС) allows annulment (анулиране) of a confirmed invoice when it carries invalid mandatory data (wrong trade name, EIK, or VAT number). This is distinct from a Credit Note and is used by SMBs to avoid НАП audit triggers. Blocking Cancel on Confirmed would remove a valid legal escape hatch.

**How:** Implement `CustomerInvoiceService::cancel(CustomerInvoice $invoice): void` inside a `DB::transaction()`:
1. Assert `$invoice->status === Confirmed` (guard — Draft cancel needs no reversal).
2. If SO-linked: reverse `qty_invoiced` for each CI item — recompute from remaining confirmed CIs excluding this one (call `SalesOrderService::updateInvoicedQuantities($so)` after marking this CI cancelled).
3. Reverse EU OSS accumulation: delete or negate the `EuOssAccumulation` row created by this invoice's confirm (match on `customer_invoice_id`).
4. Set `status = Cancelled` and save.

Route the Cancel action through this service method. Add a firm warning to the confirmation modal: *"Cancelling a confirmed invoice is only valid for invoices with incorrect mandatory data. For all other corrections, issue a Credit Note instead."*

---

#### CI-V3: Annulled invoices cannot be printed

**What:** The "Print Invoice" action is gated to `status === Confirmed` only. A cancelled (annulled) invoice cannot be printed. Bulgarian law requires the annulled document to be retained and available with a clear "АНУЛИРАН" mark — the issuer must keep it for their records and it may be requested by НАП.

**How:** Extend the print action visibility to include `DocumentStatus::Cancelled`. In the `pdf/customer-invoice.blade.php` template, conditionally render a prominent "АНУЛИРАН" banner (large diagonal watermark or bold header text) when `$invoice->status === Cancelled`. Annulled documents are always single-copy only — no ОРИГИНАЛ/КОПИЕ split.

---

#### CI-V4: Tax documents printed as single copy — must be two copies (ЗДДС Art. 112)

**What:** The current Print action produces one page. Bulgarian ЗДДС Art. 112 requires tax documents to be issued in at least two copies: one for the customer (ОРИГИНАЛ), one for the issuer (КОПИЕ) for accounting and НАП records.

**Scope:** Customer Invoice, Customer Credit Note, Customer Debit Note — tax documents only. Quotation, Sales Order, Delivery Note are commercial documents and are not subject to this requirement.

**How:** Restructure the PDF templates for CI, CCN, and CDN so that each document renders two pages with identical content — first page labelled "ОРИГИНАЛ", second page labelled "КОПИЕ". DomPDF supports multi-page output natively. When INFRA-V1 rebuilds the CCN and CDN view pages, apply the same two-page treatment there.

---

### Customer Credit Note + Customer Debit Note — Review (CCN / CDN)

CCN and CDN are structural mirrors. All findings below apply equally to both resources unless noted.

---

#### CCN-F1 / CDN-F1: Invoice status filter uses raw strings instead of enum values

**What:** Both `CustomerCreditNoteForm` and `CustomerDebitNoteForm` filter linkable invoices with `CustomerInvoice::whereIn('status', ['confirmed', 'paid'])`. Raw strings are fragile — if enum backing values change, the filter silently returns nothing.

**How:** Replace with `[DocumentStatus::Confirmed->value, DocumentStatus::Paid->value]` in both forms.

---

#### CCN-V1 / CDN-V1: No Print action — tax documents cannot be printed

**What:** Neither `ViewCustomerCreditNote` nor `ViewCustomerDebitNote` has a Print action. Both are tax documents subject to ЗДДС Art. 112 and the two-copy requirement established in CI-V4.

**How:** Add a Print action to each view page (visible for `Confirmed` status) and create PDF templates for CCN and CDN following the same two-page ОРИГИНАЛ/КОПИЕ structure as the CI PDF (CI-V4). Annulled CCN/CDN (Cancelled status) follow the same rule as CI-V3: single-copy print with "АНУЛИРАН" mark.

---

#### CCN-V2 / CDN-V2: Confirm and Cancel bypass the service layer

**What:** Both Confirm and Cancel actions on both view pages write `$record->status = ...` directly, bypassing `CustomerCreditNoteService` and `CustomerDebitNoteService`. Impact is lower than CI-V2 (no qty_invoiced or OSS accumulations to reverse), but the pattern is structurally inconsistent and leaves no room to add side effects later.

**How:** Add `confirm()` and `cancel()` methods to `CustomerCreditNoteService` and `CustomerDebitNoteService`. Route the view page actions through them. At minimum, `cancel()` should guard that a Confirmed note is not cancelled if downstream processes depend on it (reserved for future payment reconciliation).

---

#### CCN-F2 / CDN-F2: CI select options include fully-credited invoices

**What:** The `customer_invoice_id` dropdown on both CCN and CDN forms loads all `confirmed/paid` invoices without filtering out ones where every line is already fully credited or debited. A user can select such an invoice, land in the line items relation manager, and find nothing addable — a silent dead end with no explanation.

**How:** Scope the options query to exclude CIs where `remainingCreditableQuantity()` is zero on all lines. Alternatively, add a `hasRemainingCorrectableLines()` scope on `CustomerInvoice` and apply it to the select.

---

#### CCN-F3 / CDN-F3: No per-line direction lock — credit and debit can coexist on the same CI line

**What:** Once a CI line has a CCN against it, nothing prevents a CDN from being created against that same line, and vice versa. This opens the door to an infinite credit→debit→credit loop per line, which is legally and financially incoherent.

**How:** Add a method to `CustomerInvoiceItem` (e.g. `existingCorrectionDirection()`) that returns `'credit'`, `'debit'`, or `null`. The CCN item relation manager must reject adding a line that already has a debit correction; the CDN item relation manager must reject adding a line that already has a credit correction. Surface a clear validation message, not a silent failure.

---

#### CCN-F4: Return reason does not enforce a linked Sales Return

**What:** When `reason = Return` on a CCN, the confirmation path has no guard on `sales_return_id`. A user can confirm a financial return document with no physical stock movement behind it — a legally and operationally incomplete document.

**How:** In the CCN confirm action (once routed through `CustomerCreditNoteService` per CCN-V2): if `reason = Return` and `sales_return_id` is null, block confirmation with a clear message — e.g. "A linked Sales Return is required before confirming a Return credit note." The message should include a link to create or link an SR.

---

#### CCN-E1 / CDN-E1: Edit is unrestricted on confirmed documents

**What:** `EditCustomerCreditNote` and `EditCustomerDebitNote` have no status guard. A confirmed CCN or CDN is a tax document — it must be immutable after confirmation. The Edit action is also visible in the table row for confirmed records, allowing unrestricted mutation of issued tax documents.

**How:** Same pattern as CI-C2 — hide/disable the Edit action (both table row and edit page header) when `status` is not `draft`. A confirmed CCN/CDN should only be editable while in Draft state.

---

#### CCN-T1 / CDN-T1: `total` column hardcoded to EUR

**What:** Both `CustomerCreditNotesTable` and `CustomerDebitNotesTable` render `TextColumn::make('total')->money('EUR')`. CCN and CDN documents can be issued in any currency, so the hardcoded EUR will display incorrect currency symbols for non-EUR documents.

**How:** `->money(fn ($record) => $record->currency_code)` — same fix applied to CI and other document tables.

---

#### SR-CCN-1: No structural link between Sales Return and Credit Note

**What:** `customer_credit_notes` has no `sales_return_id` FK. The SR↔CCN relationship is implicit at best. As a result: (a) CCN view shows no linked SR; (b) SR view shows no linked CCN; (c) CCN-F4 cannot be enforced without this column; (d) per-line direction guards cannot walk from SR back to CI lines.

**How:** Migration adding nullable `sales_return_id` (FK → `sales_returns`, nullOnDelete). Update `CustomerCreditNote` and `SalesReturn` models with the inverse `belongsTo`/`hasMany`. CCN view: add SR to related documents infolist section. SR view: show linked CCN(s) in related documents.

---

#### SR-CCN-2: "Create CCN" button on SR view is always visible regardless of creditability

**What:** The "Create CCN" action on the SR view page is shown unconditionally — even when the linked CI has no creditable lines remaining, or when no CI is linked at all. Pressing it leads to a form that either pre-selects a dead-end invoice or has no invoice pre-filled.

**How:** Gate the action: hidden when `sales_return_id` already links a confirmed CCN (one SR, one CCN), or when the originating CI has no remaining creditable lines. Show a tooltip explaining why it is disabled rather than silently hiding it.

---

#### SR-F1: DN options use raw string status filter

**What:** `SalesReturnForm` filters linkable delivery notes with `DeliveryNote::where('status', 'confirmed')` — a raw string. Same fragility as CCN-F1/CDN-F1: if the enum backing value changes the filter silently returns nothing.

**How:** Replace with `DeliveryNote::where('status', DeliveryNoteStatus::Confirmed)`.

---

#### SR-F2: DN options include fully-returned delivery notes

**What:** The `delivery_note_id` dropdown shows all confirmed DNs, including ones where every item has already been fully returned. User selects it, lands in the items relation manager, finds nothing addable — a silent dead end with no explanation.

**How:** Filter out DNs where `remainingReturnableQuantity() == 0` on all lines. Add a `hasRemainingReturnableLines()` scope on `DeliveryNote` and apply it to the select options query.

---

#### SR-V1: "Create CCN" redirect does not pass `sales_return_id`

**What:** The "Create CCN" action on `ViewSalesReturn` redirects to CCN create with `?customer_invoice_id=X` only. No `sales_return_id` is included in the URL. The CCN form has no way to know which SR originated it — so even after SR-CCN-1 adds the FK column, the link will never be auto-populated through this flow.

**How:** Append `&sales_return_id={$record->id}` to the redirect URL. Handle it in `CreateCustomerCreditNote::mount()`: if `sales_return_id` is present in the query string, set it on the form alongside `customer_invoice_id`.

---

#### SR-V2: Invoice options in "Create CCN" modal do not filter fully-credited CIs

**What:** `getLinkedInvoiceOptions()` walks DN → SO → customerInvoices and returns all confirmed CIs, without checking remaining creditable quantity. A fully-credited invoice appears in the list, leading to the same dead end as CCN-F2.

**How:** Apply a `hasRemainingCorrectableLines()` scope (shared with CCN-F2 fix) before returning the options array.

---

#### SR-T1: Edit action in table visible for non-editable records

**What:** The table row `EditAction` is shown for all SR records regardless of status. `EditSalesReturn::mount()` correctly redirects confirmed/cancelled SRs back to the view page, but the clickable button still appears — misleading UX.

**How:** Add `->visible(fn ($record) => $record->isEditable())` to the `EditAction` in `SalesReturnsTable`.

---

## Phase 3.2 Code Review — Advance Payment Findings

### AP-F1: `received_at` not required in form

**What:** `AdvancePaymentForm` sets `->default(now()->toDateString())` on the `received_at` field but does NOT call `->required()`. A user can clear the field and save without it. Under ЗДДС Art. 25(3) the chargeable event date for an advance payment is the date the money is received. This date is legally mandatory — it controls when VAT becomes due and must appear on the advance invoice.

**How:** Add `->required()` to the `received_at` DatePicker in `AdvancePaymentForm`.

---

### AP-F2: `payment_method` nullable — cash advances silently skip fiscal receipt dispatch

**What:** `AdvancePaymentForm` declares `payment_method` as `->nullable()`. In `AdvancePaymentService::confirm()` (line ~106) `FiscalReceiptRequested` is dispatched only inside `if ($ap->payment_method === PaymentMethod::Cash)`. When `payment_method` is null, no dispatch occurs — not even a log entry. A cashier who forgets to select Cash on a cash advance silently skips the fiscal receipt, which is a ЗДДС violation.

**How:** Remove `->nullable()` so `payment_method` becomes `->required()`. Update `mutateFormDataBeforeCreate` if needed to ensure it is always persisted. If `null` must remain representable (e.g. for migrated data), add an explicit null-guard in `AdvancePaymentService::confirm()` that halts with a notification instead of silently skipping.

---

### AP-F3: Sales Order options use raw string status filter

**What:** `AdvancePaymentForm` builds the `sales_order_id` options with `SalesOrder::whereNotIn('status', ['cancelled', 'invoiced'])`. The raw strings `'cancelled'` and `'invoiced'` are not backed by `SalesOrderStatus` enum values — a future enum rename silently breaks the filter without a compile-time error.

**How:** Replace with `SalesOrderStatus::Cancelled->value` and `SalesOrderStatus::Invoiced->value` (or use `->whereIn('status', ...)` with the enum list of permitted statuses, which is often more explicit and easier to audit).

---

### AP-T1: Money columns hardcode EUR

**What:** The `AdvancePaymentsTable` renders `amount`, `amount_applied`, and `remaining` with `->money('EUR')` hardcoded. Advance payments may be received in a foreign currency (e.g. a USD advance for an export customer).

**How:** Replace with `->money(fn ($record) => $record->currency_code ?? 'EUR')` — identical pattern to the fix applied to CustomerInvoice and CustomerDebitNote tables (CCN-T1 / CDN-T1).

---

### AP-T2: Edit action in table not gated on status

**What:** The `EditAction` in `AdvancePaymentsTable::recordActions()` is shown unconditionally. `EditAdvancePayment::mount()` correctly redirects non-editable records, but the button still appears for Confirmed/PartiallyApplied/FullyApplied/Refunded advances — misleading UX, identical to SR-T1, CCN-E1, CDN-E1.

**How:** Add `->visible(fn ($record) => $record->isEditable())` to the `EditAction`.

---

### AP-T3: Bulk delete does not check AP status

**What:** `AdvancePaymentsTable` includes `DeleteBulkAction` with no status guard. Soft-deleting a `PartiallyApplied` or `FullyApplied` advance payment from the table leaves the linked `AdvancePaymentApplication` rows and the `CustomerInvoice.advance_payment_id` pointer intact — the CI's deduction rows point to a deleted AP, breaking financial reconciliation.

**How:** Extend `DeleteBulkAction` with a `->before()` hook that aborts with a notification if any selected record is not in `AdvancePaymentStatus::Open` status. Identical guard pattern to the one used on `PurchaseOrderResource`.

---

### AP-V1: `refund()` does not cancel the confirmed advance invoice (VAT collected, never reversed)

**What:** `AdvancePaymentService::refund()` sets `$ap->status = AdvancePaymentStatus::Refunded; $ap->save()` — three lines, nothing else. If the advance has a confirmed advance invoice (`customer_invoice_id` is set and the CI status is Confirmed), the VAT already declared to НАП is never reversed. The advance invoice stays confirmed and outstanding in the system while the AP shows Refunded — financial records are inconsistent.

**How (two-step gate):**
1. Before setting status: check `$ap->advanceInvoice` exists and its status is `DocumentStatus::Confirmed`. If so, block the refund with a notification: "Issue a credit note on the advance invoice before refunding." — require the user to cancel the advance invoice's VAT obligation first (via a CCN on the advance CI), then retry refund.
2. If no confirmed advance invoice exists (never issued, or already cancelled): allow refund, set status = Refunded, dispatch `FiscalReceiptRequested` for refund receipt if `payment_method = Cash`.

This mirrors how SAP Business One handles advance payment reversal: the advance invoice must be fully credited before the AP document can be closed.

---

### AP-V2: Cancelled Customer Invoice leaves AP `amount_applied` stale

**What:** When a Draft `CustomerInvoice` that has an advance applied to it is cancelled, `AdvancePaymentService` is not called to reverse the application. The `AdvancePaymentApplication` row and `$ap->amount_applied` remain unchanged — the AP continues to show as `PartiallyApplied` or `FullyApplied` with no live invoice behind it. Attempting to apply the AP to a second invoice would fail because `remaining` reports 0 or less.

**How:** In `CustomerInvoiceService::cancel()`, after transitioning status to Cancelled, check for any `AdvancePaymentApplication` rows linked to this invoice and call a new `AdvancePaymentService::reverseApplication(CustomerInvoice $invoice)` method that: deletes the application rows, recomputes `$ap->amount_applied`, and updates AP status back to `Open` or `PartiallyApplied` as appropriate.

**Note:** This is the reverse-direction counterpart of `applyToFinalInvoice()`. It should be a single DB transaction. Add a test that confirms AP status reverts correctly after CI cancellation.

---

## Verification Plan

1. **Migrations**: `sail artisan migrate:fresh --seed` in a test tenant — all tables created
2. **RBAC**: `sail artisan tenants:seed --class=RolesAndPermissionsSeeder` — new permissions visible
3. **Stock reservation**: Unit test for atomic `issueReserved()` + manual test: create SO → confirm → check stock_items.reserved_quantity increased → create DN → confirm → check both quantity and reserved_quantity decreased
4. **Full pipeline**: Quotation → SO (convert) → DN (confirm, stock issued) → Invoice (confirm) → verify all qty_delivered/qty_invoiced updated, SO status transitions
5. **Reverse charge**: Create partner with EU country ≠ tenant country + valid VAT → create invoice → verify is_reverse_charge=true, VAT=0%
6. **OSS**: Seed accumulations to just under threshold → create B2C cross-border invoice → verify threshold crossing logged, VAT rate changed to destination country
7. **Advance payment**: Create advance → confirm → issue advance invoice → create final invoice → apply advance → verify negative deduction rows with correct amounts
8. **Tests**: `./vendor/bin/sail artisan test --parallel --compact` — all pass
9. **Pint**: `vendor/bin/pint --dirty --format agent` — no violations

---

## Key Risk Areas

1. **`issueReserved()` race condition**: MUST use single atomic SQL UPDATE, not PHP check-then-update. Test with concurrent access.
2. **Advance deduction VAT**: Deduction rows added by `applyToFinalInvoice()` MUST carry the **same `vat_rate_id`** as the original advance invoice item (NOT 0%). VatCalculationService handles negatives naturally via multiplication (`negative_net * rate/100 = negative_vat`). This ensures the final invoice's net VAT is correct after deductions.
3. **EU OSS €10,000 threshold**: Cumulative across ALL EU countries, not per-country. `isThresholdExceeded()` must sum all countries for the year.
4. **Reverse charge vs OSS exclusivity**: B2B (valid VAT) → reverse charge. B2C (no valid VAT) → OSS. Never both.
5. **Migration on existing table**: `purchase_order_items.sales_order_item_id` is an ALTER on an existing table with data — must be nullable with nullOnDelete.
