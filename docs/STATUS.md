# Project Status

> **For AI assistants:** Read this file first. It tells you where we are, what's done, and what's next. Then check `tasks/` for detailed specs and `docs/` for architecture/business logic.

## Current State

**VAT/VIES Area 1 (tenant.md) complete.** 541/541 tests pass. Next: implement `tasks/vat-vies/partner.md` then `invoice.md` then `blocks.md`. See `tasks/vat-vies/spec.md` for full agreed design.

The app is a multi-tenant SaaS ERP (HMO) built with Laravel 13 + Filament v5 + stancl/tenancy. Target market is the **entire EU**. Current implementation targets Bulgarian SMEs first (SUPTO/NRA fiscal compliance). Architecture is designed for EU-wide rollout. Landlord is the SaaS operator.

---

## What Works Today

### Phase 1 Foundation (complete)
- Multi-tenant architecture: central DB (landlord) + per-tenant PostgreSQL databases
- Subdomain routing: `hmo.localhost` = landlord, `{slug}.hmo.localhost` = tenant
- Self-registration: 3-step wizard (account → company → plan), 14-day trial auto-started
- Tenant admin panel (`/admin`): CRM (partners, contracts, tags), Settings (currencies, VAT rates, number series, users, roles), company settings
- Landlord panel (`/landlord`): tenant management, plan management, lifecycle actions
- Tenant lifecycle: Active → Suspended → MarkedForDeletion → ScheduledForDeletion → auto-deleted
- Plan management: Free/Starter/Professional with limits (max_users, max_documents)
- Stripe Checkout + bank transfer billing; `Cashier v16` on `Tenant`
- Tenant lifecycle emails (suspended/marked/scheduled/reactivated/deleted) — queued via Redis
- Role-based access control via spatie/laravel-permission (10 roles, 50 permissions per tenant)

### Phase 2 Catalog + Warehouse (complete)

**Catalog (NavigationGroup::Catalog):**
- `Category` — hierarchical product categories, max 3 levels deep (enforced in model boot). Translatable name/description. Soft deletes. `CategoryResource` with full CRUD. Supports `default_vat_rate_id` and `default_unit_id` (CATALOG-3): selecting a category on the product create form auto-fills VAT rate and unit via parent-chain resolution.
- `Unit` — units of measure (Mass/Volume/Length/Area/Time/Piece/Other). Translatable name. Seeded with 13 standard units (pcs, kg, g, t, l, ml, m, cm, mm, m², h, day, month). `UnitResource` (simple ManageRecords page).
- `Product` — goods and services. Translatable name/description. Types: Stock/Service/Bundle. Status: Draft/Active/Discontinued (`ProductStatus` enum, replaces `is_active`). Auto-creates default `ProductVariant` on creation. `ProductResource` with `ProductVariantsRelationManager`. Soft deletes. ActivityLog on key fields.
- `ProductVariant` — named variants (size/color/material etc.). Each variant tracks own SKU, prices (falls back to product), barcode. Default variant is hidden in UI. Translatable name. Soft deletes.

**Warehouse (NavigationGroup::Warehouse):**
- `Warehouse` — physical locations. Single `is_default` enforced via model boot. JSON address. `WarehouseResource` with `StockLocationsRelationManager`. Soft deletes.
- `StockLocation` — bins/shelves within a warehouse. Soft deletes.
- `StockItem` — current stock level per variant per warehouse (+ optional location). Computed `available_quantity = quantity - reserved_quantity`. Read-only in `StockItemResource`.
- `StockMovement` — immutable audit log of every stock change. Signed quantity (positive = in, negative = out). Polymorphic `reference` morph for future Invoice/PO linking. `moved_by` FK records who triggered the movement. `StockMovementResource` (read-only, filterable, shows "By" column).

**Services:**
- `StockService` — single entry point for all stock mutations: `receive()`, `issue()`, `adjust()`, `transfer()`. All wrapped in DB transactions. Uses bcmath for decimal precision. Throws `InsufficientStockException` when stock is insufficient.

**RBAC:**
- 8 new models added: `category`, `unit`, `product`, `product_variant`, `warehouse`, `stock_location`, `stock_item`, `stock_movement` → 40 new permissions per tenant (now 90 total).
- `warehouse-manager` role: full CRUD on warehouses/locations/movements, create/update stock items, view catalog.
- `sales-manager` role: view catalog + stock levels.

**Infrastructure:**
- `NavigationGroup` enum adopted across all resources (Catalog, Warehouse, Crm, Settings).
- Translatable fields via `lara-zeus/spatie-translatable` v2.0. Tenant-configured locales via `TranslatableLocales::forTenant()`.
- Morph map registered in `AppServiceProvider`: `product`, `product_variant`, `warehouse`, `stock_movement`.
- `UnitSeeder` runs at tenant onboarding. Default `MAIN` warehouse created at onboarding.

### Phase 3.1 Purchases (complete)

**Purchases (NavigationGroup::Purchases):**
- `PurchaseOrder` — orders sent to suppliers. Status pipeline: Draft → Sent → Confirmed → PartiallyReceived → Received (with Cancelled exit). `PurchaseOrderResource` with `PurchaseOrderItemsRelationManager`. Cross-document actions create GRN and SupplierInvoice. Soft deletes. ActivityLog.
- `GoodsReceivedNote` — records physical receipt of goods into a warehouse. Confirming a GRN calls `StockService::receive()` for each line (stock goes up), sets morph reference `goods_received_note` on `StockMovement`, and auto-updates linked PO status (PartiallyReceived/Received). `GoodsReceivedNoteResource` with confirm action (irreversible). Soft deletes. ActivityLog.
- `SupplierInvoice` — supplier's billing document. `internal_number` auto-generated from NumberSeries. Composite unique on `(partner_id, supplier_invoice_number)`. `SupplierInvoiceResource` with `SupplierInvoiceItemsRelationManager` (supports free-text lines without variant). "Create Credit Note" action. Soft deletes. ActivityLog.
- `SupplierCreditNote` — partial or full credit against a confirmed supplier invoice. `SupplierCreditNoteItemsRelationManager` validates quantity against `remainingCreditableQuantity()` with `lockForUpdate()` for race safety. Soft deletes. ActivityLog.
- `PurchaseReturn` — reversal of a GRN: records goods physically returned to a supplier. Linked to a GRN (nullable for future standalone use). Confirming calls `StockService::issue()` per item (`MovementType::PurchaseReturn`) — stock goes down. No auto-SCN created (financial flow is separate). `PurchaseReturnItemsRelationManager` with "Import from GRN" bulk action; GRN item selector auto-fills variant/qty/cost; quantity validated against `remainingReturnableQuantity()` with `lockForUpdate()`. Soft deletes. ActivityLog.

**Services:**
- `PurchaseOrderService` — item total calculation (via `VatCalculationService`), document total aggregation, status transition guard, `updateReceivedQuantities()` called from GoodsReceiptService.
- `GoodsReceiptService` — `confirm()` in DB transaction: validates Draft state + has items, calls `StockService::receive()` per line, updates PO if linked; `cancel()` for Draft only.
- `SupplierInvoiceService` — per-item VAT/discount/line-total calculation (mirrors PO service), document total aggregation with amount_due update; `confirmAndReceive()` for Express Purchasing (confirm SI + create + confirm GRN in one transaction).
- `SupplierCreditNoteService` — per-item VAT/line-total calculation, document total aggregation.
- `CurrencyRateService` — per-request rate resolution via `ExchangeRate` table (exact-match + fallback to most-recent); static closure factories for form `afterStateUpdated` hooks (currency change, date change); bookmark suffix action for saving manually-entered rates.
- `PurchaseReturnService` — `confirm()` in DB transaction: validates Draft state + has items, calls `StockService::issue()` per line with `MovementType::PurchaseReturn`; `cancel()` for Draft only. `InsufficientStockException` bubbles to UI action handler.

**Task 3.1.10 UX wiring (added after initial build):**
- `po_number`, `grn_number`, `credit_note_number` auto-generated from NumberSeries (were blocked by `required()` validation — now `disabled()->dehydrated()->placeholder()`).
- `currency_code` is now a `Select` backed by the `Currency` model (was free-text `TextInput`).
- `mount()` on Create pages now eagerly loads parent document and fills all cascaded fields (partner, warehouse, currency, exchange_rate, pricing_mode) in a single `fill()` call, not relying on `afterStateUpdated`.
- SI `afterStateUpdated` on PO selector now also cascades `exchange_rate` and `pricing_mode`.
- SCN form now includes `partner_id` (hidden/dehydrated), `exchange_rate`, and `pricing_mode` fields; `mutateFormDataBeforeCreate` has safety-net partner_id fallback.
- GRN items RM now has "Import from PO" header action — bulk-creates items with `purchase_order_item_id` set, enabling `updateReceivedQuantities()` to actually work.
- GRN items RM manual form now has a PO line item selector (when GRN is linked to a PO) that auto-fills variant/qty/cost and sets `purchase_order_item_id`.
- SI/SCN items RMs now call `SupplierInvoiceService`/`SupplierCreditNoteService` in after hooks (previously called only model-level `recalculateTotals()` which summed zeroes).
- `SupplierInvoiceItem::creditedQuantity()` now excludes items from cancelled CNs.
- `lockForUpdate()` in SCN quantity validation now wrapped in `DB::transaction()`.
- PO items RM `isReadOnly()` now correctly checks `isEditable()` (was hardcoded `return false`).
- `Currency::scopeActive()` added for consistent query patterns.

**RBAC (Phase 3.1 additions):**
- 10 new models → 50 new permissions per tenant (now ~140 total).
- `purchasing-manager` role: full CRUD on all purchase documents (including PR) + view catalog/warehouse/partners.
- `accountant` role updated: view POs/GRNs/PRs + full CRUD supplier invoices/credit notes.
- `warehouse-manager` role updated: full CRUD on GRNs + PRs + view POs.

**Infrastructure:**
- Morph map extended: `purchase_order`, `goods_received_note`, `supplier_invoice`, `supplier_credit_note`, `purchase_return`.
- `StockService::receive()` now uses `$reference->getMorphClass()` (morph alias, not full class name).
- `supplier_invoices.due_date` is nullable (date not always known at creation).
- `goods_received_notes.supplier_invoice_id` FK (nullable) — set when GRN is auto-created by "Confirm & Receive"; enables cross-navigation between SI and GRN without a shared PO.
- `PurchaseOrderItem::invoicedQuantity()` / `remainingInvoiceableQuantity()` — computed (no migration); mirrors `creditedQuantity()` pattern; used to filter SI import and suggest quantities.
- Express Purchasing tenant setting (`purchasing.express_purchasing`, default off) — when on, "Confirm & Receive" button visible on `ViewSupplierInvoice`.

### Phase 3.2 Sales / Invoicing (in progress)

**Data layer (3.2.1):**
- All enums: `QuotationStatus`, `SalesOrderStatus`, `DeliveryNoteStatus`, `SalesReturnStatus`, `AdvancePaymentStatus`, `InvoiceType`. `SeriesType` + `MovementType` extended.
- 20 tenant migrations covering the full outbound pipeline: `quotations`, `quotation_items`, `sales_orders`, `sales_order_items`, `delivery_notes`, `delivery_note_items`, `customer_invoices`, `customer_invoice_items`, `customer_credit_notes`, `customer_credit_note_items`, `customer_debit_notes`, `customer_debit_note_items`, `sales_returns`, `sales_return_items`, `advance_payments`, `advance_payment_applications`, `eu_country_vat_rates`, `eu_oss_accumulations`.
- 18 models + 15 factories for all of the above.

**Infrastructure (3.2.2):**
- Morph map extended (+8 entries): `quotation`, `sales_order`, `delivery_note`, `customer_invoice`, `customer_credit_note`, `customer_debit_note`, `sales_return`, `advance_payment`.
- `FiscalReceiptRequested` event.
- `StockService::reserve()`, `unreserve()`, `issueReserved()`.
- 8 new policy files for all Phase 3.2 models.
- `RolesAndPermissionsSeeder` extended (+16 models, ~80 new permissions); `sales-manager`/`accountant`/`warehouse-manager` roles updated.
- `EuCountryVatRatesSeeder` (27 EU member states) wired into tenant onboarding.

**Quotation Resource (3.2.3):**
- `QuotationResource` — full CRUD, `NavigationGroup::Sales`, sort 1.
- `QuotationService` — item total recalc (VAT + discount via `VatCalculationService`), document total aggregation, status transition guard (no-items check + valid-transition check), `convertToSalesOrder(Quotation, Warehouse): SalesOrder` (copies all items with `quotation_item_id` back-link, generates `so_number` from `SeriesType::SalesOrder` with `Str::random(8)` fallback).
- Status pipeline: Draft → Sent → Accepted / Rejected / Expired / Cancelled.
- `ViewQuotation` header actions: Edit (if editable), Mark as Sent, Accept, Reject, Convert to SO (warehouse picker modal), Cancel, Print as Offer (Sent only), Print as Proforma (Sent + Accepted).
- PDF templates: `quotation-offer.blade.php` + `quotation-proforma.blade.php` (DomPDF via `streamDownload`).
- `QuotationItemsRelationManager` — auto-fills `sale_price` on variant select, `isReadOnly()` tied to `isEditable()`, after hooks call `QuotationService` to recalculate totals.

**CustomerInvoice Resource (3.2.6):**
- `CustomerInvoiceResource` — full CRUD, `NavigationGroup::Sales`, sort 4.
- `CustomerInvoiceService` — `recalculateItemTotals` (handles negative advance-deduction rows), `recalculateDocumentTotals` (amount_due = total - amount_paid), `confirm(bool $treatAsB2c = false)` (determines EU VAT scenario, overrides item rates, updates SO qty_invoiced, sets service-item qty_delivered, dispatches `FiscalReceiptRequested` on cash payment, accumulates EU OSS).
- `VatScenario` enum — 5 cases: `Domestic`, `EuB2bReverseCharge`, `EuB2cUnderThreshold`, `EuB2cOverThreshold`, `NonEuExport`. `determine(Partner, tenantCountry, ignorePartnerVat=false)` centralises all branching logic. `requiresVatRateChange()` drives whether item VAT rates are overridden at confirm time.
- `EuOssService` — `shouldApplyOss` (B2C + cross-border EU + threshold check), `accumulate` (tracks cross-border B2C totals in EUR per country/year), `getDestinationVatRate`.
- `ViesValidationService` — validates EU VAT via VIES SOAP API. Returns `available` (reachable) + `valid` (confirmed valid). Results cached 24h; transient failures (`available: false`) are NOT cached so next call retries immediately.
- Status pipeline: Draft → Confirmed / Cancelled.
- `ViewCustomerInvoice` header actions: Edit (Draft), Confirm (with three-way VIES check for EU B2B: unavailable → warn + proceed, valid → proceed with reverse charge, explicitly invalid → halt + set `$viesInvalidDetected`), Confirm with Standard VAT (visible when `$viesInvalidDetected` — passes `treatAsB2c: true` so OSS threshold check still runs naturally), Print Invoice (Confirmed), Create Credit Note, Create Debit Note, Cancel.
- PDF template: `customer-invoice.blade.php` — line items + VAT breakdown + totals + amount due + reverse charge note.
- `CustomerInvoiceItemsRelationManager` — `sales_order_item_id` selector auto-fills from SO item remaining invoiceable quantity; "Import from SO" header action; `sale_price` for variant auto-fill.
- `ViewSalesOrder` "Create Invoice" button and invoice list links wired to `CustomerInvoiceResource`.

**CustomerCreditNote + CustomerDebitNote Resources (3.2.7):**
- `CustomerCreditNoteResource` — full CRUD, `NavigationGroup::Sales`, sort 5. Items RM enforces `remainingCreditableQuantity()` with `lockForUpdate()` — requires a linked invoice item.
- `CustomerDebitNoteResource` — full CRUD, `NavigationGroup::Sales`, sort 6. Items RM allows free-form lines (invoice item link optional, `product_variant_id` optional).
- `CustomerCreditNoteService` — `recalculateItemTotals`, `recalculateDocumentTotals` (exact mirror of `SupplierCreditNoteService`).
- `CustomerDebitNoteService` — same structure as `CustomerCreditNoteService`.
- Both forms auto-fill currency/exchange_rate/pricing_mode from linked `CustomerInvoice` on select.
- `ViewCustomerInvoice` Credit Note and Debit Note related-document links and header action URLs now wired to real resource routes.
- Migration `200022` adds `default(0)` to computed columns on both note items tables.

---

## Deferred / Not Done

| Item | Status |
|------|--------|
| Task 2.7 — Barcode scanning UI (BarcodeDetector API + Alpine.js) | Deferred — barcode `varchar` field present on Product/ProductVariant, scanning UI not built |
| Opening balances / stock import | Deferred — `StockService::adjust()` is available; formal inventory audit (WAREHOUSE-2) is the correct long-term path |

---

## Key Technical Decisions

| Decision | Choice | Why |
|----------|--------|-----|
| Tenancy | stancl/tenancy (separate DBs) | True data isolation per tenant |
| Billing | Stripe Checkout + bank transfer | App owns subscription state; Bulgarian market prefers bank transfer |
| Stock tracking | Always at variant level via FK (not polymorphic) | Every Product has a default hidden variant; simpler queries, simpler StockService |
| Decimal precision | `decimal(15,4)` for catalog prices + stock qty | 4dp for unit cost precision; 15-digit width consistent across columns |
| MovementType enum | Business-context names (Purchase/Sale/TransferOut/TransferIn) | More descriptive audit trail vs generic Receipt/Issue |
| Translatable fields | JSON columns + spatie/laravel-translatable | Document-facing fields (name, description) support per-tenant locales |
| Navigation | NavigationGroup enum (not plain strings) | Type safety, icon+label support, consistent across all resources |

---

## What's Next

**Phase 3.2 — Sales/Invoicing (in progress)**

Sub-task 3.2.1 complete: all enums, migrations (20), models (18), factories (15) for the full outbound pipeline — Quotation → SalesOrder → DeliveryNote → CustomerInvoice → CreditNote/DebitNote → SalesReturn → AdvancePayment + EU OSS helpers. `PurchaseOrderItemsRelationManager` extended with `sales_order_item_id` selector.

Sub-task 3.2.2 complete: morph map extended (+8 entries), `FiscalReceiptRequested` event, `StockService::reserve()/unreserve()/issueReserved()`, 8 new policy files, `RolesAndPermissionsSeeder` extended (+16 models, sales-manager/accountant/warehouse-manager role updates), `EuCountryVatRatesSeeder` (27 EU member states) wired into tenant onboarding.

Sub-task 3.2.3 complete: `QuotationResource` (full CRUD, status pipeline: Draft→Sent→Accepted/Rejected/Expired/Cancelled, PDF actions, Convert to SO action). `QuotationService` mirrors `PurchaseOrderService` (item + document total recalc, status transition guard with no-items check, `convertToSalesOrder(Quotation, Warehouse): SalesOrder`). PDF templates: `quotation-offer.blade.php` + `quotation-proforma.blade.php`. `QuotationItemsRelationManager` auto-fills `sale_price` on variant select. 12 new tests.

Sub-task 3.2.4 complete: `SalesOrderResource` (full CRUD, status pipeline: Draft→Confirmed/PartiallyDelivered/Delivered/Invoiced/Cancelled, Confirm triggers stock reservation, Import to PO modal action, Cancel with cascade + unreservation). `SalesOrderService` (7 methods: recalculateItemTotals, recalculateDocumentTotals, transitionStatus, reserveAllItems, unreserveRemainingItems, updateDeliveredQuantities, updateInvoicedQuantities). `SalesOrderItemsRelationManager` shows `qty_delivered`/`qty_invoiced` columns. `CreateSalesOrder` handles `?quotation_id` query param for pre-fill. `ViewQuotation` Convert to SO now redirects to new SO and related SO links are live. 17 new tests.

Sub-task 3.2.5 complete: `DeliveryNoteResource` (full CRUD, Confirm calls `DeliveryNoteService::confirm()` — issues reserved stock via `StockService::issueReserved()`, updates SO `qty_delivered`, auto-transitions SO to PartiallyDelivered/Delivered, Print Delivery Note PDF). `DeliveryNoteService::confirm/cancel`. `DeliveryNoteItemsRelationManager` with "Import from SO" header action. `ViewSalesOrder` wired to DeliveryNoteResource URLs. 10 new tests.

Sub-task 3.2.6 complete: `CustomerInvoiceResource` (full CRUD, Draft→Confirmed/Cancelled, Confirm calls `CustomerInvoiceService::confirm()` — determines EU VAT scenario via `VatScenario::determine()`, overrides item rates, updates SO qty_invoiced, sets service item qty_delivered, dispatches `FiscalReceiptRequested` on cash payment, accumulates EU OSS totals, Print Invoice PDF, stub Credit/Debit Note actions). `CustomerInvoiceService` (recalculateItemTotals, recalculateDocumentTotals, confirm with `treatAsB2c` flag, determineVatType). `VatScenario` enum (5 scenarios, determine(), requiresVatRateChange()). `ViesValidationService` (VIES SOAP validation, available/valid distinction, transient-safe caching). `EuOssService` (shouldApplyOss, accumulate, getDestinationVatRate). PDF template `pdf/customer-invoice.blade.php`. `ViewSalesOrder` "Create Invoice" button and invoice list links wired. Migration `200021` adds `default(0)` to `customer_invoice_items` computed columns. Company Settings country_code field added. Partner form country_code field added.

Sub-task 3.2.7 complete: `CustomerCreditNoteResource` (NavigationGroup::Sales sort 5, items RM with `lockForUpdate()` quantity guard on `remainingCreditableQuantity()`). `CustomerDebitNoteResource` (sort 6, free-form items — invoice item link optional). `CustomerCreditNoteService` + `CustomerDebitNoteService` (mirror SupplierCreditNoteService). `ViewCustomerInvoice` Credit/Debit Note action URLs and related-document links wired to real resource routes. Migration `200022` adds `default(0)` to note items computed columns. 8 new tests.

Sub-task 3.2.9 complete: `AdvancePaymentResource` (NavigationGroup::Sales sort 8, no items RM). `AdvancePaymentService` (3 methods: `createAdvanceInvoice` — confirmed CustomerInvoice with invoice_type=Advance, auto-calculates totals, links back via customer_invoice_id; `applyToFinalInvoice` — negative deduction row with same vat_rate_id as advance invoice item; `refund`). `ViewAdvancePayment` header actions: Issue Advance Invoice, Apply to Invoice (modal picker), Refund. `view-document.blade.php` — reusable related-documents template for single-amount documents. 16 new tests.

Sub-task 3.2.10–3.2.11 complete: 513 tests passing (SalesPolicyTest + resource coverage + docs update).

Sub-task 3.2.12 complete: Full structured refactor — 5 tiers, 50+ items. Key changes: infolist() added to all 8 Sales Resources (Quotation, SalesOrder, DeliveryNote, CustomerInvoice, CustomerCreditNote, CustomerDebitNote, SalesReturn, AdvancePayment), shared Blade view templates deleted, `getRelatedDocuments()` bridge methods removed, plus form fixes, service hardening, and table improvements. CI-1 advance deductions, CI-V3 tax breakdown, CI-V4 payment status all in infolist.

VAT/VIES Area 1 (tenant.md) complete: Migration adds `is_vat_registered` + `vies_verified_at` to `tenants` table. `CompanyVatService` enforces invariant (registered ↔ VAT number). CompanySettingsPage VAT section: VIES-verified toggle + lookup + confirmed field; country change resets all; save guard blocks unverified VAT. 8 tests (`CompanyVatServiceTest` + `CompanyVatSetupTest`).

Next: `tasks/vat-vies/partner.md` (Area 2 — Partner VAT setup)

See `tasks/phase-3.2-plan.md` for full spec.

---

## File Map for New Sessions

| What to check | Where |
|---------------|-------|
| Phase 1 history | `tasks/phase-1.md` |
| Phase 2 tasks + decisions | `tasks/phase-2.md`, `tasks/phase-2-plan.md` |
| Phase 2.5 tasks | `tasks/phase-2.5.md` |
| Phase 3.1 refactor plan | `tasks/phase-3.1-refactor.md` |
| Post-phase backlog | `tasks/backlog.md` |
| Architecture & models | `docs/ARCHITECTURE.md` |
| Test infrastructure (template DB, parallel setup, forced rebuild) | `docs/ARCHITECTURE.md` § 10 |
| Business logic & services | `docs/BUSINESS_LOGIC.md` |
| Filament panels & resources | `docs/UI_PANELS.md` |
| Features & test coverage | `docs/FEATURES.md` |
| Tenant routes | `routes/tenant.php` |
| Central routes | `routes/web.php` |
| Enums | `app/Enums/` |
| Services | `app/Services/` (StockService, VatCalculationService, TenantOnboardingService, ...) |
| Landlord panel | `app/Filament/Landlord/` (feature-complete) |
| Tenant panel | `app/Filament/` (non-Landlord) |
| Catalog resources | `app/Filament/Resources/{Categories,Units,Products}/` |
| Warehouse resources | `app/Filament/Resources/{Warehouses,StockItems,StockMovements}/` |
| Purchases resources | `app/Filament/Resources/{PurchaseOrders,GoodsReceivedNotes,SupplierInvoices,SupplierCreditNotes,PurchaseReturns}/` |
| Settings resources | `app/Filament/Resources/{Currencies,VatRates,NumberSeries,TenantUsers,Roles}/` |
| Phase 2 models | `app/Models/{Category,Unit,Product,ProductVariant,Warehouse,StockLocation,StockItem,StockMovement}.php` |
| Phase 3.1 models | `app/Models/{PurchaseOrder,PurchaseOrderItem,GoodsReceivedNote,GoodsReceivedNoteItem,SupplierInvoice,SupplierInvoiceItem,SupplierCreditNote,SupplierCreditNoteItem,PurchaseReturn,PurchaseReturnItem}.php` |
| Policies | `app/Policies/` (23 total: 9 Phase 1 + 8 Phase 2 + 5 Phase 3.1 + 1 landlord) |
| Migrations (tenant) | `database/migrations/tenant/` |

---

## Environment

- PHP 8.5, Laravel 13, Filament v5, Livewire v4, Pest v4
- PostgreSQL 17 via Docker (`hmo-postgres` container — only accessible inside Docker network)
- DB migrations: `database/migrations/` (central), `database/migrations/tenant/` (per-tenant)
- `APP_DOMAIN=hmo.localhost` — central domain
- Artisan must be run inside Docker or via Sail: `./vendor/bin/sail artisan ...`
- Tests: `./vendor/bin/sail artisan test --parallel --compact` (~86s with 12 workers)
- **After adding new permissions to `RolesAndPermissionsSeeder`**, existing tenant DBs need re-seeding: `./vendor/bin/sail artisan tenants:seed --class=RolesAndPermissionsSeeder`. New resources won't appear in the UI until this is run (0 matching permissions = Filament hides the nav entry).

---

## Test Count History

| Milestone | Tests |
|-----------|-------|
| Phase 1 baseline | 87 |
| Phase 1 complete (1.1–1.18) | 211 |
| Post-release hardening audit | 232 |
| Phase 2 complete | 293 |
| Phase 2.5 complete | 293 |
| Phase 3.1 complete | **326** |
| Phase 3.1 UX wiring + services | 335 |
| Phase 3.1.12 Tier 1+2 refactor | **344** |
| Phase 3.1.12 Tier 3 refactor | **348** |
| Phase 3.1.12 INFRA-1 (Currency Rate Manager) | **355** |
| Phase 3.1.12 SI-2 (SI items import + PO-filtered form) | **362** |
| Phase 3.1.12 SI-3 (Express Purchasing — Confirm & Receive) | **368** |
| Phase 3.1.12 PR-1 (Purchase Return — full document stack) | **377** |
| Backlog Session 1 (CATALOG-2, CATALOG-5, WAREHOUSE-3, WAREHOUSE-6, WAREHOUSE-8) | **391** |
| CATALOG-3 (Category inheritable defaults — vat_rate + unit auto-fill on product create) | **398** |
| Phase 3.2.1 (enums + 20 migrations + 18 models + 15 factories — full outbound pipeline data layer) | **398** |
| Phase 3.2.2 (morph map, FiscalReceiptRequested, StockService reserve/unreserve/issueReserved, 8 policies, RBAC + EU VAT seeder) | **406** |
| Phase 3.2.3 (QuotationResource, QuotationService, PDF templates — offer + proforma) | **418** |
| Phase 3.2.4 (SalesOrderResource, SalesOrderService, 7 methods, stock reservation/unreservation, ViewQuotation redirect fixed) | **435** |
| Phase 3.2.5 (DeliveryNoteResource, DeliveryNoteService, issueReserved integration, SO qty update, PDF template) | **445** |
| Phase 3.2.6 (CustomerInvoiceResource, CustomerInvoiceService, EuOssService, PDF template, ViewSalesOrder wired) | **445** |
| Phase 3.2.7 (CustomerCreditNoteResource, CustomerDebitNoteResource, both services, ViewCustomerInvoice wired) | **453** |
| Phase 3.2.8 (SalesReturnResource, SalesReturnService, SalesReturnItemsRelationManager, ViewDeliveryNote wired) | **460** |
| Phase 3.2.9 (AdvancePaymentResource, AdvancePaymentService, view-document.blade.php template) | **476** |
| Phase 3.2.10–3.2.11 (tests: SalesPolicyTest, remaining resource coverage; docs update) | **513** |
| Phase 3.2.12 refactor (Tiers 0–5: migrations, service hardening, form fixes, view actions, infolist conversions — Blade deleted) | **513** |
| VAT-DETERMINATION-1 (VatScenario enum, determineVatType, VIES three-way branch, transient-safe caching, Company Settings + Partner country fields) | **533** |
| VAT/VIES Area 1 — tenant.md (migration, CompanyVatService, CompanySettingsPage VAT section, TenantFactory, 8 tests) | **541** |
