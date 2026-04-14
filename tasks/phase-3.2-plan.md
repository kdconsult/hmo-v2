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

## Sub-task 3.2.10 — Tests

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

## Sub-task 3.2.11 — Docs Update + Pint + Final Test Run

- Update `docs/STATUS.md` — Phase 3.2 complete, test count
- Update `docs/UI_PANELS.md` — add Sales navigation group with all 8 resources
- Run `vendor/bin/pint --dirty --format agent`
- Run `./vendor/bin/sail artisan test --parallel --compact`

---

## Sub-task 3.2.12 — Refactor Phase

Structured review → `tasks/phase-3.2-refactor.md` (mirrors Phase 3.1.12 approach from `tasks/phase-3.1-refactor.md`). This is a post-implementation review, not part of the initial build.

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
