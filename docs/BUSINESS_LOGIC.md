# Business Logic: Services, RBAC & Automation

This document details the core business logic systems: role-based access control, financial services, stock management, purchasing pipeline, tenant lifecycle management, plan/subscription handling, automated commands, and mail notifications.

---

## 1. RBAC System (Role-Based Access Control)

### 11 Roles

All roles are registered in tenant databases via `RolesAndPermissionsSeeder` (re-run on existing tenants with `sail artisan tenants:seed --class=RolesAndPermissionsSeeder`):

1. **super-admin** — Bypasses all permission gates (via `Gate::before()` in `AppServiceProvider`). No specific permissions assigned.
2. **admin** — Full CRUD on all models (synced to `$allPermissions` — automatically includes new permissions added in future phases).
3. **sales-manager** — Full CRUD on all sales documents (quotation, sales_order, delivery_note, customer_invoice, customer_credit_note, customer_debit_note, sales_return, advance_payment, and their items) + view catalog/warehouse/partners.
4. **sales-agent** — Create/update partners (no delete), view contracts, view tags.
5. **accountant** — View partners/contracts, CRUD currencies/VAT rates, view number series; view POs + GRNs; full CRUD on supplier invoices + credit notes. Phase 3.2 extension: view+CRUD on customer_invoice, customer_credit_note, customer_debit_note, advance_payment and their items; view on quotation, sales_order, delivery_note, sales_return.
6. **viewer** — View-only access to all models.
7. **warehouse-manager** — Full CRUD on warehouses/locations/movements/stock items; view catalog; full CRUD on GRNs + purchase returns; view POs. Phase 3.2 extension: full CRUD on delivery_note, delivery_note_item, sales_return, sales_return_item; view on sales_order.
8. **field-technician** — Minimal access (reserved for field service phase).
9. **finance-manager** — View-only on all models.
10. **purchasing-manager** — Full CRUD on all purchase documents (PO, GRN, supplier invoice, supplier credit note, purchase return) + view catalog/warehouse/partners.
11. **report-viewer** — `view_any_*` permissions only (list pages, no detail views).

### Permission Naming Convention

All permissions follow the pattern: `{action}_{model}`

**Actions** (5 per model):
- `view_any_{model}` — List/browse multiple records
- `view_{model}` — View a single record
- `create_{model}` — Create a new record
- `update_{model}` — Edit a record
- `delete_{model}` — Delete a record

**Models by phase:**

| Phase | Models | Permissions |
|-------|--------|-------------|
| Phase 1 | partner, contract, currency, exchange_rate, vat_rate, number_series, tenant_user, tag, company_settings, role | 50 |
| Phase 2 | category, unit, product, product_variant, warehouse, stock_location, stock_item, stock_movement | +40 |
| Phase 3.1 | purchase_order, purchase_order_item, goods_received_note, goods_received_note_item, supplier_invoice, supplier_invoice_item, supplier_credit_note, supplier_credit_note_item, purchase_return, purchase_return_item | +50 |
| Phase 3.2 | quotation, quotation_item, sales_order, sales_order_item, delivery_note, delivery_note_item, customer_invoice, customer_invoice_item, customer_credit_note, customer_credit_note_item, customer_debit_note, customer_debit_note_item, sales_return, sales_return_item, advance_payment, advance_payment_application | +80 |

**Total: ~220 permissions per tenant**

### Gate::before Super-Admin Bypass

Location: `/app/Providers/AppServiceProvider.php` lines 27-32

```php
Gate::before(function ($user, $ability) {
    if (method_exists($user, 'hasRole') && $user->hasRole('super-admin')) {
        return true;
    }
});
```

When a policy is checked, if the authenticated user has the `super-admin` role, all abilities return `true` immediately, bypassing all individual permission checks.

### 9 Policies

Each policy is located in `/app/Policies/` and guards a specific resource:

| Policy | Model | Methods | Guard |
|--------|-------|---------|-------|
| `CompanySettingsPolicy` | CompanySettings | viewAny, view, create, update, delete | Permission-based (spatie/permission) |
| `ContractPolicy` | Contract | viewAny, view, create, update, delete, restore, forceDelete | Permission-based |
| `CurrencyPolicy` | Currency | viewAny, view, create, update, delete, restore, forceDelete | Permission-based |
| `DocumentSeriesPolicy` | DocumentSeries | viewAny, view, create, update, delete, restore, forceDelete | Permission-based |
| `PartnerPolicy` | Partner | viewAny, view, create, update, delete, restore, forceDelete | Permission-based |
| `RolePolicy` | Role (Spatie Role) | viewAny, view, create, update, delete | Permission-based |
| `TagPolicy` | Tag | viewAny, view, create, update, delete, restore, forceDelete | Permission-based |
| `TenantUserPolicy` | TenantUser | viewAny, view, create, update, delete, restore, forceDelete | Permission-based |
| `VatRatePolicy` | VatRate | viewAny, view, create, update, delete, restore, forceDelete | Permission-based |
| `TenantPolicy` | Tenant (landlord DB) | viewAny, view, create, update, delete, suspend, markForDeletion, scheduleForDeletion, reactivate | is_landlord flag (NOT permission-based) |

#### TenantPolicy (Landlord-Only)

Location: `/app/Policies/TenantPolicy.php`

Unique to the central/landlord database — checks `is_landlord` flag directly:

- `viewAny()`, `view()`, `create()`, `update()` → `$user->is_landlord`
- `delete()` → **always `false`** (direct deletion forbidden; only automated script can delete)
- `suspend()` → `is_landlord` AND `tenant->isActive()`
- `markForDeletion()` → `is_landlord` AND `tenant->isSuspended()`
- `scheduleForDeletion()` → `is_landlord` AND `tenant->status === TenantStatus::MarkedForDeletion`
- `reactivate()` → `is_landlord` AND `!tenant->isActive()`

#### Permission-Based Policies

All other policies follow this pattern:

```php
public function viewAny(User $user): bool
{
    return $user->hasPermissionTo('view_any_{model}');
}
```

Methods like `restore()` and `forceDelete()` reuse the `delete_{model}` permission.

---

## 2. Services

### VatCalculationService

Location: `/app/Services/VatCalculationService.php`

Handles VAT calculations for Bulgarian (and EU) fiscal compliance. All methods return an associative array:

```
['net' => float, 'vat' => float, 'gross' => float, 'rate' => float]
```

**Methods:**

- `fromNet(float $net, float $rate): array`
  - Calculates VAT from an exclusive (pre-tax) amount.
  - Formula: `vat = net × (rate / 100)`
  - Returns net, vat, gross, rate.

- `fromGross(float $gross, float $rate): array`
  - Calculates VAT from an inclusive (post-tax) amount.
  - Formula: `net = gross / (1 + rate / 100)`
  - Returns net, vat, gross, rate.

- `calculate(float $amount, float $rate, PricingMode $mode): array`
  - Routes to `fromNet()` or `fromGross()` based on `PricingMode` enum.
  - `PricingMode::VatExclusive` → `fromNet()`
  - `PricingMode::VatInclusive` → `fromGross()`

- `calculateDocument(array $lines): array`
  - Multi-line document calculation.
  - Input: `[['amount' => float, 'rate' => float, 'mode' => PricingMode], ...]`
  - Sums all line VATs and groups by rate percentage.
  - Returns: `['net' => float, 'vat' => float, 'gross' => float, 'vat_breakdown' => ['20.00%' => 100.00, ...]]`

All monetary values are rounded to 2 decimal places.

### ViesValidationService

Location: `/app/Services/ViesValidationService.php`

Validates EU VAT numbers via the official VIES (VAT Information Exchange System) SOAP service.

**Cache:**
- 24-hour TTL per country+VAT number combination.
- Key format: `vies_validation_{countryCode}_{vatNumber}`

**Method:**

- `validate(string $countryCode, string $vatNumber): array`
  - Returns cached result if available, otherwise calls VIES.
  - Input: ISO 2-letter country code (e.g., `BG`, `DE`), VAT number (cleaned of non-alphanumeric).
  - Returns: `['valid' => bool, 'name' => ?string, 'address' => ?string, 'country_code' => string, 'vat_number' => string]`
  - On SOAP failure: logs warning, returns `valid: false` with nulled name/address.

**SOAP Configuration:**
- WSDL: `https://ec.europa.eu/taxation_customs/vies/checkVatService.wsdl`
- 10-second connection timeout.

### PlanLimitService

Location: `/app/Services/PlanLimitService.php`

Enforces tenant plan limits for users and documents.

**Methods:**

- `canAddUser(Tenant $tenant): bool`
  - Checks if tenant can create another user based on plan's `max_users`.
  - Runs query in tenant database context.
  - Returns `true` if `max_users` is null (unlimited) or if current count < limit.

- `canCreateDocument(Tenant $tenant, int $currentMonthCount): bool`
  - Checks if tenant can create a document this month.
  - Parameter: current month's document count for the tenant.
  - Returns `true` if `max_documents` is null (unlimited) or if passed count < limit.
  - Caller must query and pass the month's document count; service only compares.

### TenantDeletionGuard

Location: `/app/Services/TenantDeletionGuard.php`

Enforces lifecycle preconditions before a tenant database is permanently deleted. Called by the `DeletingTenant` event listener in `TenancyServiceProvider` (skip in testing environment).

**Method:**

- `static check(Tenant $tenant): void`
  - Throws `RuntimeException` if:
    1. Tenant status is NOT `ScheduledForDeletion`
    2. `deletion_scheduled_for` timestamp is in the future
  - Used to prevent accidental/early deletion via the UI; only the `DeleteScheduledTenantsCommand` scheduled job can delete.

### TenantOnboardingService

Location: `/app/Services/TenantOnboardingService.php`

Sets up a newly created tenant's database and owner user record.

**Method:**

- `onboard(Tenant $tenant, User $ownerUser): void`
  - Runs inside the tenant's database context.
  - Executes seeders in order: `RolesAndPermissionsSeeder`, `CurrencySeeder`, `VatRateSeeder`, **`UnitSeeder`** (Phase 2).
  - Creates or retrieves a `TenantUser` record linking the owner.
  - Assigns the `admin` role to the owner if not already assigned.
  - **Creates default `MAIN` warehouse** (is_default=true) if not already present (Phase 2).
  - **Sets `locale_en = '1'`** in CompanySettings `localization` group (Phase 2).

---

### StockService

Location: `/app/Services/StockService.php`

Single entry point for all stock mutations. **Never write directly to `stock_items` or `stock_movements`.**

All methods are wrapped in `DB::transaction()` and use `bcmath` for decimal precision.

**Methods:**

- `receive(ProductVariant $variant, Warehouse $warehouse, string $quantity, ?StockLocation $location, ?Model $reference, MovementType $type): StockMovement`
  - Adds stock: increments `stock_items.quantity`, creates positive `StockMovement`.
  - `$reference` is the source document (e.g., GoodsReceivedNote). Stored via `$reference->getMorphClass()` (alias, not class name).

- `issue(ProductVariant $variant, Warehouse $warehouse, string $quantity, ?StockLocation $location, ?Model $reference, MovementType $type): StockMovement`
  - Removes stock: decrements `stock_items.quantity`. Throws `InsufficientStockException` if result < 0.

- `adjust(ProductVariant $variant, Warehouse $warehouse, string $newQuantity, ?StockLocation $location, string $notes): StockMovement`
  - Sets stock to an absolute value (positive or negative signed movement created for the delta). Uses `MovementType::Adjustment`.

- `transfer(ProductVariant $variant, Warehouse $from, Warehouse $to, string $quantity, ?StockLocation $fromLoc, ?StockLocation $toLoc): array`
  - Issues from source (TransferOut), receives at destination (TransferIn). Returns `[StockMovement, StockMovement]`.

- `reserve(ProductVariant $variant, Warehouse $warehouse, string $quantity, Model $reference): void`
  - Books stock for a future issue: increments `stock_items.reserved_quantity`.
  - Throws `InsufficientStockException` if `available_quantity (= quantity - reserved_quantity) < $quantity`.
  - Does **not** create a `StockMovement` — reservations are bookings, not physical movements.
  - Called by `SalesOrderService::reserveAllItems()` when an SO is confirmed.

- `unreserve(ProductVariant $variant, Warehouse $warehouse, string $quantity, Model $reference): void`
  - Releases a prior reservation: decrements `reserved_quantity` (floor at 0).
  - Does **not** create a `StockMovement`.
  - Called by `SalesOrderService::unreserveRemainingItems()` when an SO is cancelled.

- `issueReserved(ProductVariant $variant, Warehouse $warehouse, string $quantity, Model $reference, ?User $by = null): StockMovement`
  - **Single atomic SQL UPDATE** for race-condition safety: decrements both `quantity` AND `reserved_quantity` in one statement, guarded by `reserved_quantity >= qty AND quantity >= qty`.
  - If 0 rows affected, throws `InsufficientStockException`.
  - Creates `StockMovement` with `MovementType::Sale` and negative quantity.
  - Called by `DeliveryNoteService::confirm()` per stock-type item.

**Design Notes:**
- `StockItem` is upserted (create-or-update) on every mutation — no pre-creation needed.
- `StockMovement` rows are immutable (boot throws `RuntimeException` on update/delete).
- `moved_by` set to `Auth::id()` — null in CLI/queue contexts (acceptable).

---

### PurchaseOrderService

Location: `/app/Services/PurchaseOrderService.php`

Handles arithmetic and state transitions for purchase orders.

**Methods:**

- `recalculateItemTotals(PurchaseOrderItem $item): void`
  - Computes `discount_amount`, `vat_amount`, `line_total`, `line_total_with_vat` from `quantity × unit_price`, discount, and VAT rate.
  - Delegates VAT math to `VatCalculationService::calculate()` based on `PurchaseOrder.pricing_mode`.

- `recalculateDocumentTotals(PurchaseOrder $po): void`
  - Sums all items → sets `subtotal`, `tax_amount`, `total` on the PO.

- `transitionStatus(PurchaseOrder $po, PurchaseOrderStatus $newStatus): void`
  - Validates against `$validTransitions` map; throws `InvalidArgumentException` on invalid transition.
  - **Valid transitions:**
    - Draft → {Sent, Cancelled}
    - Sent → {Draft, Confirmed, Cancelled}
    - Confirmed → {PartiallyReceived, Received, Cancelled}
    - PartiallyReceived → {Received, Cancelled}
    - Received, Cancelled → (terminal — no transitions)

- `updateReceivedQuantities(PurchaseOrder $po): void`
  - Called by `GoodsReceiptService` after GRN confirmation.
  - Sums `quantity_received` from all confirmed GRN items per PO item.
  - Auto-updates PO status: PartiallyReceived (some items full) or Received (all items full).

---

### GoodsReceiptService

Location: `/app/Services/GoodsReceiptService.php`

Orchestrates GRN confirmation: stock in, PO status update, audit trail.

**Methods:**

- `confirm(GoodsReceivedNote $grn): void`
  - Pre-checks: `$grn->isEditable()` (throws if not Draft); at least one item; warehouse set.
  - Inside `DB::transaction()`:
    1. For each GRN item: calls `StockService::receive($variant, $warehouse, $qty, null, $grn, MovementType::Purchase)`.
    2. Sets `$grn->status = Confirmed`, `$grn->received_at = today`.
    3. If GRN has a linked PO: calls `PurchaseOrderService::updateReceivedQuantities($po)`.

- `cancel(GoodsReceivedNote $grn): void`
  - Sets status to Cancelled. Only valid from Draft — confirmed GRNs cannot be cancelled (stock already received).

---

### SupplierInvoiceService

Location: `/app/Services/SupplierInvoiceService.php`

Handles arithmetic and status transitions for supplier invoices.

**Methods:**

- `recalculateItemTotals(SupplierInvoiceItem $item): void`
  - Computes `discount_amount`, `vat_amount`, `line_total`, `line_total_with_vat` from `quantity × unit_price`, discount, and VAT rate.
  - Delegates VAT math to `VatCalculationService::calculate()` based on `SupplierInvoice.pricing_mode`.

- `recalculateDocumentTotals(SupplierInvoice $invoice): void`
  - Sums all items → sets `subtotal`, `discount_amount`, `tax_amount`, `total`, `amount_due` on the invoice.

- `confirmAndReceive(SupplierInvoice $invoice, Warehouse $warehouse): GoodsReceivedNote`
  - **Express Purchasing fast-track** — used by the "Confirm & Receive" action on `ViewSupplierInvoice`.
  - Requires SI to be in Draft state; throws `InvalidArgumentException` otherwise.
  - Requires at least one stockable item (line with `product_variant_id`); throws if all lines are free-text.
  - Requires a default `GoodsReceivedNote` NumberSeries to exist; throws if missing.
  - Inside `DB::transaction()`:
    1. Sets SI status to Confirmed.
    2. Creates a `GoodsReceivedNote` linked to `partner_id`, the given warehouse, today's date, and `supplier_invoice_id`. If SI is linked to a PO, also sets `purchase_order_id`.
    3. For each stockable SI item: creates a `GoodsReceivedNoteItem` (variant, quantity, unit_cost).
    4. Calls `app(GoodsReceiptService::class)->confirm($grn)` — stock moves in.
    5. Returns the created and confirmed GRN.
  - Free-text SI lines (no variant) are skipped — no stock to receive.

---

### SupplierCreditNoteService

Location: `/app/Services/SupplierCreditNoteService.php`

Handles arithmetic for supplier credit notes.

**Methods:**

- `recalculateItemTotals(SupplierCreditNoteItem $item): void`
  - Computes `vat_amount`, `line_total`, `line_total_with_vat` from `quantity × unit_price` and VAT rate.
  - Delegates VAT math to `VatCalculationService::calculate()` based on `SupplierCreditNote.pricing_mode`.

- `recalculateDocumentTotals(SupplierCreditNote $creditNote): void`
  - Sums all items → sets `subtotal`, `tax_amount`, `total` on the credit note.

---

### QuotationService

Location: `/app/Services/QuotationService.php`

Handles arithmetic and state transitions for quotations.

**Methods:**

- `recalculateItemTotals(QuotationItem $item): void`
  - Computes `discount_amount`, `vat_amount`, `line_total`, `line_total_with_vat` from `quantity × unit_price`, discount, and VAT rate. Delegates VAT math to `VatCalculationService`.

- `recalculateDocumentTotals(Quotation $quotation): void`
  - Sums all items → sets `subtotal`, `discount_amount`, `tax_amount`, `total`.

- `transitionStatus(Quotation $quotation, QuotationStatus $newStatus): void`
  - Valid transitions: Draft → {Sent, Cancelled}; Sent → {Accepted, Rejected, Expired, Cancelled}; Accepted → {Cancelled}.
  - Throws `InvalidArgumentException` on invalid transition; also blocks Sent→Accepted when quotation has no items.

- `convertToSalesOrder(Quotation $quotation, Warehouse $warehouse): SalesOrder`
  - Creates a `SalesOrder` copying partner, currency, exchange_rate, pricing_mode, and all items (with `quotation_item_id` back-link).
  - Generates `so_number` from `SeriesType::SalesOrder` NumberSeries (falls back to `SO-{random8}`).
  - Does NOT change quotation status (stays Accepted even after conversion).

---

### SalesOrderService

Location: `/app/Services/SalesOrderService.php`

Handles arithmetic, state transitions, and stock operations for sales orders.

**Methods:**

- `recalculateItemTotals(SalesOrderItem $item): void` — standard pattern (mirrors PO service)

- `recalculateDocumentTotals(SalesOrder $order): void` — standard pattern

- `transitionStatus(SalesOrder $order, SalesOrderStatus $newStatus): void`
  - Valid transitions: Draft → {Confirmed, Cancelled}; Confirmed → {PartiallyDelivered, Delivered, Invoiced, Cancelled}; PartiallyDelivered → {Delivered, Cancelled}; Delivered → {Invoiced, Cancelled}.
  - On **Confirmed**: calls `reserveAllItems()` (stock reservation).
  - On **Cancelled**: calls `unreserveRemainingItems()` + cascades cancellation to draft DNs and draft invoices.

- `reserveAllItems(SalesOrder $order): void`
  - Iterates stock-type items only (skips Service/Bundle types).
  - Calls `StockService::reserve()` per item inside `DB::transaction`.

- `unreserveRemainingItems(SalesOrder $order): void`
  - For each stock-type item: unreserves `qty - qty_delivered` (only undelivered portion remains reserved).

- `updateDeliveredQuantities(SalesOrder $order): void`
  - Sums confirmed DN item quantities per SO item → updates `qty_delivered`.
  - Auto-transitions SO to `PartiallyDelivered` (some items partially delivered) or `Delivered` (all items fully delivered).
  - Called by `DeliveryNoteService::confirm()` when DN has a linked SO.

- `updateInvoicedQuantities(SalesOrder $order): void`
  - Sums confirmed customer invoice item quantities per SO item → updates `qty_invoiced`.
  - Auto-transitions SO to `Invoiced` when all items fully invoiced.
  - Called by `CustomerInvoiceService::confirm()` (Phase 3.2.6).

---

### DeliveryNoteService

Location: `/app/Services/DeliveryNoteService.php`

Orchestrates delivery note confirmation: reserved stock out, SO status update, audit trail.

**Methods:**

- `confirm(DeliveryNote $dn): void`
  - Pre-checks: `$dn->isEditable()` (throws if not Draft); at least one item.
  - Loads `items.productVariant.product` and `warehouse` via `loadMissing`.
  - Inside `DB::transaction()`:
    1. For each DN item: skips non-stock items (Service/Bundle); calls `StockService::issueReserved($variant, $warehouse, $qty, $dn)`.
    2. Sets `$dn->status = Confirmed`, `$dn->delivered_at = today`.
    3. If DN has a linked SO: calls `SalesOrderService::updateDeliveredQuantities($so)`.
  - Throws `InvalidArgumentException` if preconditions fail; lets `InsufficientStockException` bubble to the UI handler.

- `cancel(DeliveryNote $dn): void`
  - Sets status to Cancelled. Only valid from Draft — confirmed DNs cannot be cancelled (stock already issued).

**Key difference from GoodsReceiptService:** Uses `issueReserved()` (atomic decrement of both `quantity` and `reserved_quantity`) rather than `receive()`. Also skips service-type items.

---

### CustomerInvoiceService

Location: `/app/Services/CustomerInvoiceService.php`

Orchestrates customer invoice item totals, document totals, and invoice confirmation for the outbound sales pipeline.

**Methods:**

- `recalculateItemTotals(CustomerInvoiceItem $item): void`
  - Mirrors `SupplierInvoiceService::recalculateItemTotals()`. Handles negative quantities correctly (advance deduction rows produce negative `vat_amount` naturally via multiplication).
  - Loads `customerInvoice.pricing_mode` and `vatRate.rate` from item relations.

- `recalculateDocumentTotals(CustomerInvoice $invoice): void`
  - Sums `line_total` and `vat_amount` across all items. Sets `amount_due = total - amount_paid`.

- `confirm(CustomerInvoice $invoice): void`
  - Inside `DB::transaction()`:
    1. Sets `status = Confirmed`.
    2. If SO-linked: calls `SalesOrderService::updateInvoicedQuantities($so)`.
    3. For each invoice item linked to a service-type SO item (non-Stock): sets `soItem->qty_delivered = soItem->qty_invoiced` (services are "delivered" when invoiced, not via DN).
    4. Refreshes SO items and transitions SO to `Delivered` if now fully delivered.
  - After transaction: dispatches `FiscalReceiptRequested` if `payment_method === PaymentMethod::Cash`.
  - Calls `EuOssService::accumulate($invoice)` to track cross-border B2C EU totals.

---

### EuOssService

Location: `/app/Services/EuOssService.php`

Handles EU One-Stop Shop (OSS) VAT compliance for cross-border B2C digital/goods sales.

**Methods:**

- `shouldApplyOss(Partner $partner): bool`
  - Returns `true` when: partner has no valid EU VAT (B2C), partner country is EU, partner country ≠ tenant country, AND the annual €10,000 threshold has been exceeded (`EuOssAccumulation::isThresholdExceeded(year)`).

- `accumulate(CustomerInvoice $invoice): void`
  - Guards: partner must be non-null, EU country, cross-border (≠ tenant country), B2C (no valid EU VAT).
  - Converts invoice total to EUR using `exchange_rate`. Calls `EuOssAccumulation::accumulate(countryCode, year, totalEur)`.
  - Called on every invoice confirmation; `EuOssAccumulation` tracks cumulative totals and records `threshold_exceeded_at` when the €10,000 cross-border threshold is first crossed.

- `getDestinationVatRate(string $countryCode): float`
  - Looks up `EuCountryVatRate::getStandardRate($countryCode)`. Returns `0.0` if country not found.

---

### CustomerCreditNoteService

Location: `/app/Services/CustomerCreditNoteService.php`

Mirrors `SupplierCreditNoteService` exactly — same calculation logic, different model types.

**Methods:**

- `recalculateItemTotals(CustomerCreditNoteItem $item): void`
  - Loads `customerCreditNote.pricing_mode` and `vatRate.rate`. Computes `vat_amount`, `line_total`, `line_total_with_vat` via `VatCalculationService`. Saves item.

- `recalculateDocumentTotals(CustomerCreditNote $creditNote): void`
  - Sums `line_total` and `vat_amount` across all items. Sets `subtotal`, `tax_amount`, `total`. Saves document.

---

### CustomerDebitNoteService

Location: `/app/Services/CustomerDebitNoteService.php`

Same structure as `CustomerCreditNoteService` — applies to debit notes.

**Methods:**

- `recalculateItemTotals(CustomerDebitNoteItem $item): void`
- `recalculateDocumentTotals(CustomerDebitNote $debitNote): void`

---

### CurrencyRateService

Location: `/app/Services/CurrencyRateService.php`

Centralised exchange rate resolution. All document forms (PO, SI, SCN, and future Sales documents) use this service to auto-fill and save exchange rates.

**Methods:**

- `getBaseCurrencyCode(): string`
  - Returns the code of the currency marked `is_default = true` in the `currencies` table.
  - Cached per request via `??=`.

- `getRate(string $currencyCode, ?Carbon $date = null): ?string`
  - Returns `'1.000000'` when `$currencyCode` equals the base currency.
  - Looks up `exchange_rates` table: exact match for `(currency_id, base_currency_code, date)` first.
  - Falls back to the most recent rate on or before `$date` if no exact match.
  - Returns `null` if no rate has ever been recorded for this currency.

- `makeAfterCurrencyChanged(string $dateField): Closure` *(static)*
  - Returns a Filament `afterStateUpdated` closure for the `currency_code` select field.
  - On currency change: calls `getRate()` for the document date and sets `exchange_rate`.
  - If no rate found: clears `exchange_rate` to null and sends a persistent warning notification prompting manual entry.

- `makeAfterDateChanged(string $currencyField = 'currency_code'): Closure` *(static)*
  - Returns a Filament `afterStateUpdated` closure for document date pickers.
  - On date change: re-fetches rate for the new date. Same clear + warn behaviour when no rate exists (skipped for base currency).

- `makeSaveRateAction(string $dateField): Action` *(static)*
  - Returns a Filament suffix `Action` (bookmark icon) for use on the `exchange_rate` TextInput.
  - Visible whenever the selected currency is non-base (regardless of whether a rate value is entered).
  - On click: saves the current rate value to `exchange_rates` via `updateOrCreate` keyed on `(currency_id, base_currency_code, date)`. Source recorded as `'manual'`.
  - Validates that a rate value is present before saving; sends a warning if empty.

---

### PurchaseReturnService

Location: `/app/Services/PurchaseReturnService.php`

Handles stock issuance and status transitions for purchase returns (physical return of goods to supplier).

**Methods:**

- `confirm(PurchaseReturn $pr): void`
  - Guards: must be Draft state and must have at least one item.
  - Loads `items.productVariant` and `warehouse` via `loadMissing`.
  - Wraps in `DB::transaction`: calls `StockService::issue()` per line with `MovementType::PurchaseReturn`.
  - Sets status to `Confirmed` and `returned_at` to today.
  - Does **not** catch `InsufficientStockException` — bubbles to the UI action handler which shows a danger notification.
  - Does **not** auto-create a Supplier Credit Note — physical and financial flows are separate.

- `cancel(PurchaseReturn $pr): void`
  - Guards: must be Draft state.
  - Sets status to `Cancelled`.

**Key constraint:** `GoodsReceivedNoteItem::remainingReturnableQuantity()` tracks how much of a GRN line is still returnable (`qty_received − sum(active return items)`). Cancelled returns are excluded. The items RM uses `lockForUpdate()` inside `DB::transaction` when validating quantity to prevent race conditions.

---

## 3. Tenant Lifecycle Management

### TenantStatus State Machine

Location: `/app/Enums/TenantStatus.php`

Four states with strict transition rules:

| State | Meaning |
|-------|---------|
| **Active** | Tenant can use the system normally. |
| **Suspended** | Access blocked (due to non-payment or admin action). |
| **MarkedForDeletion** | Suspended for ~3+ months; final grace period before auto-delete. |
| **ScheduledForDeletion** | Ready for automated permanent deletion. |

**Valid Transitions** (enforced by `canTransitionTo()` method):

```
Active
  ├─→ Suspended (admin deactivation or non-payment)
  └─→ ScheduledForDeletion (tenant-initiated deletion, skips mark step)

Suspended
  ├─→ Active (reactivation after payment)
  └─→ MarkedForDeletion (3+ months unpaid)

MarkedForDeletion
  ├─→ Active (reactivation during grace period)
  └─→ ScheduledForDeletion (5+ months unpaid)

ScheduledForDeletion
  └─→ Active (emergency reactivation before auto-delete runs)
```

Attempting an invalid transition throws `RuntimeException`.

### Tenant Lifecycle Methods

Location: `/app/Models/Tenant.php` lines 174–245

- `suspend(User $by, string $reason): void`
  - Sets status to `Suspended`, records `deactivated_at`, `deactivated_by`, `deactivation_reason`.
  - Reason: `'non_payment'` | `'tenant_request'` | `'other'`

- `markForDeletion(): void`
  - Sets status to `MarkedForDeletion`, records `marked_for_deletion_at`.

- `scheduleForDeletion(?Carbon $deleteOn = null): void`
  - Sets status to `ScheduledForDeletion`, records `scheduled_for_deletion_at`, `deletion_scheduled_for`.
  - Default: 30 days from now if `$deleteOn` not provided.

- `reactivate(): void`
  - Sets status back to `Active`, clears all deactivation/deletion fields.

- **Helper methods:**
  - `isActive(): bool` — `status === Active`
  - `isSuspended(): bool` — `status === Suspended`
  - `isPendingDeletion(): bool` — `status === MarkedForDeletion || ScheduledForDeletion`

- **Query scopes:**
  - `scopeActive()`, `scopeSuspended()`, `scopeMarkedForDeletion()`, `scopeScheduledForDeletion()`, `scopeDueForDeletion()`

### TenancyServiceProvider Safety Guard

Location: `/app/Providers/TenancyServiceProvider.php` lines 45–54

The `DeletingTenant` event listener invokes `TenantDeletionGuard::check()` unless in testing environment:

```php
Events\DeletingTenant::class => [
    function (Events\DeletingTenant $event) {
        if (app()->environment('testing')) {
            return;
        }
        TenantDeletionGuard::check($event->tenant);
    },
],
```

This ensures:
- No accidental deletions via `$tenant->delete()` in the UI.
- Only tenants with `status = ScheduledForDeletion` and `deletion_scheduled_for ≤ now()` can be deleted.
- Tests can delete freely for cleanup.

### DeleteScheduledTenantsCommand

Location: `/app/Console/Commands/DeleteScheduledTenantsCommand.php`

**Signature:** `hmo:delete-scheduled-tenants`

**Description:** Permanently delete tenants whose deletion date has passed.

**Behavior:**
1. Queries: `Tenant::scheduledForDeletion()->dueForDeletion()->get()`
2. Attempts to delete each tenant via `$tenant->delete()`.
3. If `TenantDeletionGuard` check passes, the tenant database and row are deleted.
4. Logs success/failure per tenant; outputs summary.

**Scheduled:** Daily (any time).

---

## 4. Plans & Subscriptions

### Plan Model

Location: `/app/Models/Plan.php`

Central (landlord) database model. Fields:

| Field | Type | Notes |
|-------|------|-------|
| `id` | int | PK |
| `name` | string | Display name (e.g., "Professional") |
| `slug` | string | URL-safe identifier (e.g., "professional") |
| `price` | decimal(10,2) | Monthly price in EUR |
| `billing_period` | string nullable | Billing cadence (e.g., "monthly") |
| `max_users` | int nullable | User limit; null = unlimited |
| `max_documents` | int nullable | Monthly document limit; null = unlimited |
| `features` | json | Feature flags (e.g., `{"invoicing": "true", "api_access": "true"}`) |
| `is_active` | boolean | Soft-disable without deletion |
| `sort_order` | int | Display order in UI |

**Methods:**

- `tenants(): HasMany` — Tenants on this plan.
- `isFree(): bool` — True if `price === 0.0`.

**Seeded Plans:**

1. **Free** — $0, max_users=2, max_documents=100, basic features, inactive by default.
2. **Professional** — €49/month, unlimited users/documents, all features including fiscal + API access.

### SubscriptionStatus Enum

Location: `/app/Enums/SubscriptionStatus.php`

Tenant subscription state. Values:

| Status | Meaning |
|--------|---------|
| **Trial** | 14-day free trial (default for new tenants) |
| **Active** | Paid subscription; access granted |
| **PastDue** | Subscription expired or trial ended; access blocked |
| **Suspended** | Administratively suspended (reserved for future) |
| **Cancelled** | Subscription cancelled by tenant (reserved for future) |

**Method:**

- `isAccessible(): bool`
  - Returns `true` only for `Trial` and `Active` states.
  - Used by `Tenant->isSubscriptionAccessible()` to gate access.

Each status has Filament UI metadata:
- `getLabel()` — Localized label
- `getColor()` — Color name for Filament badge (info, success, warning, danger, gray)
- `getIcon()` — Heroicon (Clock, CheckCircle, ExclamationCircle, Pause, XCircle)

### Tenant Subscription Fields

Location: `/app/Models/Tenant.php` lines 23–32

| Field | Type | Notes |
|-------|------|-------|
| `plan_id` | int | FK to plans table |
| `subscription_status` | string (enum) | SubscriptionStatus |
| `trial_ends_at` | datetime | When trial expires (14 days from created_at) |
| `subscription_ends_at` | datetime | When paid subscription expires |

**Subscription Helper Methods** (lines 138–154):

- `onTrial(): bool` — True if status is Trial AND trial_ends_at is in future.
- `hasActiveSubscription(): bool` — True if status is Active.
- `isSubscriptionAccessible(): bool` — True if subscription_status->isAccessible() (i.e., Trial or Active).

### EnsureActiveSubscription Middleware

Location: `/app/Http/Middleware/EnsureActiveSubscription.php`

Blocks access to the application for tenants with inaccessible subscriptions.

**Behavior:**
1. Get current tenant from tenancy.
2. If tenant is null (central request), allow through.
3. If `!tenant->isSubscriptionAccessible()`, redirect to `subscription.expired` route (except for logout and the expired page itself).
4. Otherwise, allow request through.

**Exempted Routes:**
- `filament.admin.auth.logout`
- `subscription.expired`

### CheckTrialExpirations Command

Location: `/app/Console/Commands/CheckTrialExpirations.php`

**Signature:** `app:check-trial-expirations`

**Description:** Mark expired trials as past_due and send warning emails for trials expiring soon.

**Behavior:**

1. **Warning emails** (3 days before expiry):
   - Queries: `subscription_status = Trial` AND `trial_ends_at` between now+2d to now+3d.
   - Sends `TrialExpiringSoon` mail to tenant owner.

2. **Expiration**:
   - Queries: `subscription_status = Trial` AND `trial_ends_at ≤ now`.
   - Updates status to `PastDue`.
   - Sends `TrialExpired` mail to tenant owner.

**Scheduled:** Daily at 06:00.

### CheckSubscriptionExpirations Command

Location: `/app/Console/Commands/CheckSubscriptionExpirations.php`

**Signature:** `app:check-subscription-expirations`

**Description:** Mark paid subscriptions as past_due when their subscription_ends_at has passed.

**Behavior:**
- Queries: `subscription_status = Active` AND `subscription_ends_at ≤ now`.
- Updates status to `PastDue` for each expired subscription.
- Logs each transition.

**Scheduled:** Daily at 06:05 (5 minutes after trial checks).

---

## 5. Mail Classes

All mail classes implement Filament's `Mailable` pattern. Queued (ShouldQueue) mail is processed asynchronously.

### Subscription/Trial Emails

| Class | Queueable | Trigger | Recipients | Template |
|-------|-----------|---------|------------|----------|
| `WelcomeTenant` | No | New tenant created, trial starts | Tenant owner | `mail.tenant.welcome` (markdown) |
| `TrialExpiringSoon` | No | Trial expires in 3 days | Tenant owner | `mail.tenant.trial-expiring` (markdown) |
| `TrialExpired` | No | Trial expired | Tenant owner | `mail.tenant.trial-expired` (markdown) |
| `ProformaInvoice` | No | Plan purchase initiated | Tenant owner | `mail.tenant.proforma-invoice` (markdown) |

### Tenant Lifecycle Emails

| Class | Queueable | Trigger | Recipients | Template |
|-------|-----------|---------|------------|----------|
| `TenantSuspendedMail` | Yes | Tenant suspended | N/A | `mail.tenant.suspended` (Blade) |
| `TenantMarkedForDeletionMail` | Yes | Tenant marked for deletion | N/A | `mail.tenant.marked-for-deletion` (Blade) |
| `TenantScheduledForDeletionMail` | Yes | Tenant scheduled for deletion | N/A | `mail.tenant.scheduled-for-deletion` (Blade) |
| `TenantReactivatedMail` | Yes | Tenant reactivated | N/A | `mail.tenant.reactivated` (Blade) |
| `TenantDeletedMail` | Yes | Tenant permanently deleted | N/A | `mail.tenant.deleted` (Blade) |

**Note:** Lifecycle emails have TODO stubs for view templates. Only subscription/trial emails have implemented markdown templates.

### Mail Method Signatures

**Subscription Emails:**
```php
// WelcomeTenant, TrialExpiringSoon, TrialExpired
__construct(Tenant $tenant, User $user)

// ProformaInvoice
__construct(Tenant $tenant, User $user, Plan $plan)
```

**Lifecycle Emails:**
```php
// All tenant lifecycle emails
__construct(Tenant $tenant)
```

---

## 6. Scheduled Commands

Location: `/routes/console.php`

Three commands run on a daily schedule:

| Command | Signature | Time | Frequency | Purpose |
|---------|-----------|------|-----------|---------|
| `DeleteScheduledTenantsCommand` | `hmo:delete-scheduled-tenants` | Any (daily) | Daily | Permanently delete tenants due for deletion |
| `CheckTrialExpirations` | `app:check-trial-expirations` | 06:00 | Daily | Warn/expire trials, send emails |
| `CheckSubscriptionExpirations` | `app:check-subscription-expirations` | 06:05 | Daily | Expire paid subscriptions, mark past_due |

**Execution:**
```php
Schedule::command(DeleteScheduledTenantsCommand::class)->daily();
Schedule::command(CheckTrialExpirations::class)->dailyAt('06:00');
Schedule::command(CheckSubscriptionExpirations::class)->dailyAt('06:05');
```

Use `php artisan schedule:run` to execute all scheduled commands (typically called by a cron job every minute).

---

## 7. Seeders

All seeders are located in `/database/seeders/`. Seeds run in tenant context when onboarding a new tenant.

### DatabaseSeeder (Central DB)

Location: `/database/seeders/DatabaseSeeder.php`

Runs on `php artisan migrate:fresh --seed` or `php artisan db:seed`. Creates:

1. **Plans** (via PlanSeeder):
   - Free plan (2 users, 100 docs/month)
   - Professional plan (unlimited users/docs, €49/month)

2. **Landlord admin user:**
   - Email: `admin@hmo.localhost`
   - Password: `password`
   - Flag: `is_landlord = true`

3. **Tenant admin user (central DB):**
   - Email: `tenant-admin@hmo.localhost`
   - Password: `password`

4. **Demo tenant:**
   - Slug: `demo`
   - Name: `Demo Company`
   - Country: `BG` (Bulgaria)
   - Locale: `bg_BG`
   - Timezone: `Europe/Sofia`
   - Currency: EUR (default)
   - Plan: Free
   - Subscription: Trial, expires 14 days from now
   - Domain: `demo` (subdomain only; full URL is `demo.{app_domain}`)

5. **Tenant onboarding:**
   - Calls `TenantOnboardingService::onboard()` to seed tenant DB and create TenantUser.

### RolesAndPermissionsSeeder (Tenant DB)

Location: `/database/seeders/RolesAndPermissionsSeeder.php`

Creates all 10 roles and 50 permissions (5 actions × 10 models). Permission assignment:

- **super-admin**: No permissions (bypasses all via Gate::before).
- **admin**: All 50 permissions.
- **sales-manager**: CRUD partners, view contracts, CRUD tags.
- **sales-agent**: Create/update partners, view contracts, view tags.
- **accountant**: View partners/contracts, CRUD currencies/exchange rates/VAT rates, view document series.
- **viewer**: All `view_*` and `view_any_*` permissions.
- **warehouse-manager**: No permissions (Phase 1).
- **field-technician**: No permissions (Phase 1).
- **finance-manager**: All `view_*` and `view_any_*` permissions (Phase 2 expansion planned).
- **purchasing-manager**: No permissions (Phase 1).
- **report-viewer**: All `view_any_*` permissions only.

### CurrencySeeder (Tenant DB)

Location: `/database/seeders/CurrencySeeder.php`

Creates 7 currencies for EU operations:

| Code | Name | Symbol | Default |
|------|------|--------|---------|
| EUR | Euro | € | ✓ |
| USD | US Dollar | $ | |
| GBP | British Pound | £ | |
| RON | Romanian Leu | lei | |
| CZK | Czech Koruna | Kč | |
| PLN | Polish Zloty | zł | |
| HUF | Hungarian Forint | Ft | |

All marked `is_active = true`.

### VatRateSeeder (Tenant DB)

Location: `/database/seeders/VatRateSeeder.php`

Creates Bulgarian VAT rates (country_code = BG):

| Rate | Type | Name | Default |
|------|------|------|---------|
| 20% | standard | Standard Rate | ✓ |
| 9% | reduced | Reduced Rate (Accommodation) | |
| 0% | zero | Zero Rate | |

All marked `is_active = true`. Can be synced with EU rates via `SyncEuVatRatesCommand`.

### SyncEuVatRatesCommand

Location: `/app/Console/Commands/SyncEuVatRatesCommand.php`

**Signature:** `hmo:sync-eu-vat-rates`

**Description:** Sync EU VAT rates from ibericode/vat-rates JSON.

**Source:** `https://raw.githubusercontent.com/ibericode/vat-rates/master/vat-rates.json`

**Behavior:**
1. Fetches JSON of all EU country VAT rates.
2. For each country, upserts standard rate and reduced rates.
3. Rate type: `standard` or `reduced`.
4. Skipped fields in JSON (e.g., super-reduced) are not synced.

**Usage:** `php artisan hmo:sync-eu-vat-rates` (manual, not scheduled).

---

## Summary Table: Business Logic Artifacts

| Artifact | Location | Purpose |
|----------|----------|---------|
| **QuotationService** | `/app/Services/QuotationService.php` | Quotation item/document totals, status transitions, convertToSalesOrder |
| **SalesOrderService** | `/app/Services/SalesOrderService.php` | SO item/document totals, status transitions, stock reservation/unreservation, qty tracking |
| **DeliveryNoteService** | `/app/Services/DeliveryNoteService.php` | DN confirmation: issueReserved per stock item, SO qty update |
| **VatCalculationService** | `/app/Services/VatCalculationService.php` | VAT math (net/gross) |
| **ViesValidationService** | `/app/Services/ViesValidationService.php` | EU VAT number validation + 24h cache |
| **PlanLimitService** | `/app/Services/PlanLimitService.php` | User/document limit enforcement |
| **TenantDeletionGuard** | `/app/Services/TenantDeletionGuard.php` | Lifecycle precondition checks |
| **TenantOnboardingService** | `/app/Services/TenantOnboardingService.php` | Tenant DB setup + seeders |
| **10 Policies** | `/app/Policies/*.php` | Resource authorization |
| **TenantStatus Enum** | `/app/Enums/TenantStatus.php` | Tenant lifecycle state machine |
| **SubscriptionStatus Enum** | `/app/Enums/SubscriptionStatus.php` | Subscription states + `isAccessible()` |
| **Plan Model** | `/app/Models/Plan.php` | Plan records (free/pro) |
| **Tenant Model** | `/app/Models/Tenant.php` | Extended with subscription + lifecycle methods |
| **EnsureActiveSubscription Middleware** | `/app/Http/Middleware/EnsureActiveSubscription.php` | Blocks inaccessible tenants |
| **CheckTrialExpirations** | `/app/Console/Commands/CheckTrialExpirations.php` | Daily trial expiry + warnings (06:00) |
| **CheckSubscriptionExpirations** | `/app/Console/Commands/CheckSubscriptionExpirations.php` | Daily subscription expiry (06:05) |
| **DeleteScheduledTenantsCommand** | `/app/Console/Commands/DeleteScheduledTenantsCommand.php` | Daily tenant deletion |
| **SyncEuVatRatesCommand** | `/app/Console/Commands/SyncEuVatRatesCommand.php` | Manual EU VAT rate sync |
| **RolesAndPermissionsSeeder** | `/database/seeders/RolesAndPermissionsSeeder.php` | 10 roles + 50 permissions per tenant |
| **PlanSeeder** | `/database/seeders/PlanSeeder.php` | 2 plans (Free, Professional) |
| **CurrencySeeder** | `/database/seeders/CurrencySeeder.php` | 7 EU currencies |
| **VatRateSeeder** | `/database/seeders/VatRateSeeder.php` | 3 BG VAT rates |
| **DatabaseSeeder** | `/database/seeders/DatabaseSeeder.php` | Central DB setup + demo tenant |
| **TenancyServiceProvider** | `/app/Providers/TenancyServiceProvider.php` | Lifecycle events + deletion guard |
| **AppServiceProvider** | `/app/Providers/AppServiceProvider.php` | `Gate::before()` super-admin bypass |
| **Scheduled Commands** | `/routes/console.php` | Daily schedule configuration |

---

## Related Files (Phase 1)

- **Models:** `/app/Models/Tenant.php`, `/app/Models/Plan.php`, `/app/Models/User.php`
- **Enums:** `/app/Enums/TenantStatus.php`, `/app/Enums/SubscriptionStatus.php`, `/app/Enums/PricingMode.php`
- **Mail Views:** `/resources/views/mail/tenant/*.blade.php`
- **Configuration:** `/config/tenancy.php`
- **Tests:** `/tests/Feature/` and `/tests/Unit/`

---

---

# Phase 2 Business Logic: Catalog + Warehouse/WMS

---

## 4. Phase 2 RBAC Extensions

### 8 New Models → 40 New Permissions

Added to `RolesAndPermissionsSeeder` in Phase 2:

```php
'category', 'unit', 'product', 'product_variant',
'warehouse', 'stock_location', 'stock_item', 'stock_movement'
```

Total tenant permissions: **90** (50 Phase 1 + 40 Phase 2). Actions per model: `view_any`, `view`, `create`, `update`, `delete`.

### Role Permission Updates

| Role | Phase 2 Additions |
|------|------------------|
| `admin` | Full access to all 8 new models (automatic via `$allPermissions`) |
| `warehouse-manager` | Full CRUD: warehouse, stock_location, stock_movement; create+update stock_item; view_any+view: product, product_variant, category, unit |
| `sales-manager` | view_any+view: product, product_variant, category, unit, stock_item |
| `viewer` | view_any+view on all Phase 2 models (automatic via filter) |
| `finance-manager` | view_any+view on all Phase 2 models (automatic via filter) |

### Special Policy Rules (Phase 2)

| Policy | Special Rule |
|--------|-------------|
| `StockItemPolicy` | `delete`, `forceDelete`, `restore` always return `false` — stock items cannot be deleted |
| `StockMovementPolicy` | `update`, `delete`, `forceDelete`, `restore` always return `false` — movements are immutable |

---

## 5. StockService

Location: `app/Services/StockService.php`

The **single entry point** for all stock mutations. Never update `StockItem` directly.

All methods:
- Wrapped in `DB::transaction()`
- Use `bcmath` for decimal arithmetic (4dp precision — no floating-point errors)
- Accept an optional `StockLocation` for bin-level tracking
- Accept an optional `Model $reference` (for future Invoice/PO linking via `StockMovement.reference` morph)

### Methods

**`receive(ProductVariant, Warehouse, string $qty, ?StockLocation, ?Model $reference, MovementType $type = Purchase): StockItem`**
- Finds or creates a `StockItem` for the variant/warehouse/location combination
- Increments `quantity` by `$qty`
- Creates a `StockMovement` with positive `$qty`
- Default type: `Purchase` (can override for `Return`, `Opening`, `InitialStock`)

**`issue(ProductVariant, Warehouse, string $qty, ?StockLocation, ?Model $reference, MovementType $type = Sale): StockItem`**
- Checks `available_quantity >= $qty` via `bccomp`
- Throws `InsufficientStockException` if insufficient (carries structured data: variant, warehouse, requested, available)
- Decrements `quantity`, creates `StockMovement` with negative `$qty`
- Default type: `Sale`

**`adjust(ProductVariant, Warehouse, string $qty (signed), string $reason, ?StockLocation): StockItem`**
- Adds signed `$qty` to `quantity` (positive = add stock, negative = remove)
- Creates `StockMovement(Adjustment, $qty, notes=$reason)`
- Used by `StockAdjustmentPage`

**`transfer(ProductVariant, Warehouse $from, Warehouse $to, string $qty, ?StockLocation $fromLoc, ?StockLocation $toLoc): array`**
- Checks source `available_quantity` (throws `InsufficientStockException` if insufficient)
- Calls `issue()` on source → `StockMovement(TransferOut, negative)`
- Calls `receive()` on destination → `StockMovement(TransferIn, positive)`
- Returns `[fromStockItem, toStockItem]`

### InsufficientStockException

Location: `app/Exceptions/InsufficientStockException.php`

```php
public function __construct(
    public readonly ProductVariant $productVariant,
    public readonly Warehouse $warehouse,
    public readonly string $requestedQuantity,
    public readonly string $availableQuantity,
)
```

Message format: `'Insufficient stock for "{name}" (SKU: {sku}) in warehouse "{name}". Requested: {qty}, Available: {qty}.'`

---

## 6. TranslatableLocales Support

Location: `app/Support/TranslatableLocales.php`

Reads tenant locale configuration from `CompanySettings` group `localization`. Keys: `locale_en`, `locale_bg`, `locale_de`, etc. (9 supported languages). Returns `['en']` as fallback when tenancy is not initialized or no locale is enabled.

**Used by:** Filament resources with translatable fields — `getTranslatableLocales()` calls `TranslatableLocales::forTenant()` to provide the tenant's configured locale list to the `LocaleSwitcher` header action.

**Models with translatable fields:** `Product` (name, description), `ProductVariant` (name), `Category` (name, description), `Unit` (name).

---

## Phase 2 File Reference

| Component | Path | Purpose |
|-----------|------|---------|
| **StockService** | `/app/Services/StockService.php` | All stock mutations |
| **InsufficientStockException** | `/app/Exceptions/InsufficientStockException.php` | Thrown on insufficient stock |
| **TranslatableLocales** | `/app/Support/TranslatableLocales.php` | Tenant locale configuration |
| **UnitSeeder** | `/database/seeders/UnitSeeder.php` | Seeds 13 standard units |
| **8 Phase 2 Policies** | `/app/Policies/{Category,Unit,Product,ProductVariant,Warehouse,StockLocation,StockItem,StockMovement}Policy.php` | Resource authorization |
| **StockAdjustmentPage** | `/app/Filament/Pages/StockAdjustmentPage.php` | Standalone stock adjustment form |
| **CategoryResource** | `/app/Filament/Resources/Categories/` | CRUD for product categories |
| **UnitResource** | `/app/Filament/Resources/Units/` | CRUD for units of measure |
| **ProductResource** | `/app/Filament/Resources/Products/` | CRUD for products + variants relation manager |
| **WarehouseResource** | `/app/Filament/Resources/Warehouses/` | CRUD for warehouses + locations relation manager |
| **StockItemResource** | `/app/Filament/Resources/StockItems/` | Read-only stock levels view |
| **StockMovementResource** | `/app/Filament/Resources/StockMovements/` | Read-only movement audit log |
| **Phase 2 Models** | `/app/Models/{Category,Unit,Product,ProductVariant,Warehouse,StockLocation,StockItem,StockMovement}.php` | |
| **Phase 2 Factories** | `/database/factories/` | CategoryFactory, UnitFactory, ProductFactory, ProductVariantFactory, WarehouseFactory, StockLocationFactory, StockItemFactory, StockMovementFactory |
| **Phase 2 Tests** | `/tests/Feature/` | CategoryTest, ProductCatalogTest, StockServiceTest, StockMovementTest, WarehouseTest, CatalogPolicyTest, WarehousePolicyTest, TenantOnboardingServicePhase2Test |
