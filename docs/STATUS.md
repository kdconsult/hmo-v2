# Project Status

> **For AI assistants:** Read this file first. It tells you where we are, what's done, and what's next. Then check `tasks/` for detailed specs and `docs/` for architecture/business logic.

## Current State

**Phase 3.1 — Refactor in progress (Tier 1+2+3 of 5 done).** 348/348 tests pass. Remaining: Tier 4 (SI-2, SI-3, INFRA-1), Tier 5 (PR-1).

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
- `Category` — hierarchical product categories, max 3 levels deep (enforced in model boot). Translatable name/description. Soft deletes. `CategoryResource` with full CRUD.
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

**Services:**
- `PurchaseOrderService` — item total calculation (via `VatCalculationService`), document total aggregation, status transition guard, `updateReceivedQuantities()` called from GoodsReceiptService.
- `GoodsReceiptService` — `confirm()` in DB transaction: validates Draft state + has items, calls `StockService::receive()` per line, updates PO if linked; `cancel()` for Draft only.
- `SupplierInvoiceService` — per-item VAT/discount/line-total calculation (mirrors PO service), document total aggregation with amount_due update.
- `SupplierCreditNoteService` — per-item VAT/line-total calculation, document total aggregation.

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
- 8 new models → 40 new permissions per tenant (now ~130 total).
- `purchasing-manager` role: full CRUD on all purchase documents + view catalog/warehouse/partners.
- `accountant` role updated: view POs/GRNs + full CRUD supplier invoices/credit notes.
- `warehouse-manager` role updated: full CRUD on GRNs + view POs.

**Infrastructure:**
- Morph map extended: `purchase_order`, `goods_received_note`, `supplier_invoice`, `supplier_credit_note`.
- `StockService::receive()` now uses `$reference->getMorphClass()` (morph alias, not full class name).
- `supplier_invoices.due_date` is nullable (date not always known at creation).

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

**Phase 3.1 Refactor — implement findings from structured review**

See `tasks/phase-3.1-refactor.md` for the full plan (17 findings):
- **INFRA-1–5** (cross-cutting): Currency Rate Manager, Number Series auto-resolution, Related Documents panel, empty draft banner, redirect-after-action on all view pages
- **PO-1–6**: label fixes, empty-PO guard, cancel cascade, service-layer cancel block, VAT auto-fill, default variant filtering
- **CATALOG-BUG-1**: default variant name stored as raw JSON (data + code fix)
- **GRN-1**: context-driven field visibility (linked vs standalone)
- **SI-1–3**: currency/VAT locking, items form import + filtering, Express Purchasing tenant setting
- **PR-1**: Purchase Return — new document type (GRN link, stock issue, full document stack)

**Phase 3.2 — Sales/Invoicing (not yet planned)**

See `tasks/phase-3.md` for the sub-phase breakdown:
- Sales module: Quote → SalesOrder → Invoice → CreditNote (Phase 3.2)
- SUPTO/fiscal: ErpNet.FP REST API for Bulgarian fiscal printer compliance (Phase 3.3)
- Document generation: Blade + DomPDF, NumberSeries for sequential numbering

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
| Purchases resources | `app/Filament/Resources/{PurchaseOrders,GoodsReceivedNotes,SupplierInvoices,SupplierCreditNotes}/` |
| Settings resources | `app/Filament/Resources/{Currencies,VatRates,NumberSeries,TenantUsers,Roles}/` |
| Phase 2 models | `app/Models/{Category,Unit,Product,ProductVariant,Warehouse,StockLocation,StockItem,StockMovement}.php` |
| Phase 3.1 models | `app/Models/{PurchaseOrder,PurchaseOrderItem,GoodsReceivedNote,GoodsReceivedNoteItem,SupplierInvoice,SupplierInvoiceItem,SupplierCreditNote,SupplierCreditNoteItem}.php` |
| Policies | `app/Policies/` (22 total: 9 Phase 1 + 8 Phase 2 + 4 Phase 3.1 + 1 landlord) |
| Migrations (tenant) | `database/migrations/tenant/` |

---

## Environment

- PHP 8.5, Laravel 13, Filament v5, Livewire v4, Pest v4
- PostgreSQL 17 via Docker (`hmo-postgres` container — only accessible inside Docker network)
- DB migrations: `database/migrations/` (central), `database/migrations/tenant/` (per-tenant)
- `APP_DOMAIN=hmo.localhost` — central domain
- Artisan must be run inside Docker or via Sail: `./vendor/bin/sail artisan ...`
- Tests: `./vendor/bin/sail artisan test --parallel --compact` (~86s with 12 workers)

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
