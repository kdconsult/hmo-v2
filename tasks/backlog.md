# Backlog — Unscheduled Improvements

Items identified during design review and brainstorming. Not yet scheduled to a phase.

> Items completed in Phase 2.5 (CATALOG-7, WAREHOUSE-1, WAREHOUSE-5, CORE-1) and Phase 3.1 (WAREHOUSE-4) have been removed — they are tracked in their respective phase task files. CATALOG-3 completed 2026-04-14 and removed.

---

## Easy — Quick wins, isolated changes

### ~~VAT-VIES-1: VIES lookup button on Partner form~~ → absorbed into `tasks/vat-vies/partner.md`

Superseded by the full VAT/VIES feature redesign (2026-04-16). See `tasks/vat-vies/spec.md` Area 2.

---

### SALES-1: Quotation auto-expiry scheduler

When `valid_until` passes and a quotation is still in `Draft` or `Sent` status, automatically transition it to `QuotationStatus::Expired`.

- Scheduled job (daily, off-peak) — query quotations where `valid_until < today` and `status IN (draft, sent)`
- Transition to `Expired` in bulk
- Notify the `created_by` user (the sales rep who issued it) — one notification per expired quotation, or a daily digest if multiple expire on the same day
- If the `created_by` user is no longer active (deleted/deactivated), skip the notification — the reassignment flow (SYSTEM-1) should have already moved the document to an active rep

**Note:** The visual indicator on `valid_until` (QUO-L3 in the refactor plan) is a separate, immediate fix. This job is the background complement.

---

### SALES-2: Document-level discount field on sales documents

Allow a header-level discount amount to be entered on Quotations (and, by extension, Sales Orders and Customer Invoices).

**Why this is non-trivial — EU VAT Directive 2006/112/EC, Article 79:**
Article 79(b) states that the taxable base does NOT include price reductions and rebates granted to the customer at the time of supply. This means a discount reduces the **taxable base** (net amount) before VAT is calculated — VAT must be computed on `net - discount`, not `net`.

**The multi-VAT-rate problem:**
A typical SME sales document mixes standard-rate items (e.g. 20% VAT) and zero-rate items (e.g. exported goods, 0%). A single discount amount entered at document level cannot be applied uniformly — it must be **proportionally allocated per VAT rate group** (the discount reduces each group's taxable base in proportion to that group's share of the total net). The same arithmetic applies to the item rows: each `QuotationItem.discount_amount` must be recomputed from the header-level allocation.

This is the approach used by SAP Business One and Microsoft Dynamics Business Central.

**Compound model (correct approach when eventually implemented):**
1. User enters `discount_amount` at document level (or `discount_percent` — both should be supported)
2. Service layer distributes the discount proportionally across VAT groups
3. Per-item `discount_amount` is recomputed: `item_share = item_line_total / document_subtotal * header_discount`
4. `recalculateItemTotals()` runs on each item with the new per-item discount
5. `recalculateDocumentTotals()` aggregates the result

**Long-term answer:** Customer price lists with contract prices per product/customer combination. A price list eliminates most use cases for header discounts because each line already carries the negotiated price.

**Constraint:** Do NOT implement as a simple `total - discount` deduction at document level without redistributing to items — that approach produces wrong VAT amounts and is non-compliant with Article 79.

**Scope when implemented:** Quotation → Sales Order → Customer Invoice (all three, atomically — same service-layer pattern).

---

### SALES-3: Clone / Duplicate quotation action

Add a "Clone to New Draft" header action on the Quotation View page, visible for all statuses.

- Creates a new `Quotation` in `Draft` status with the same `partner_id`, `currency_code`, `exchange_rate`, `pricing_mode`, `valid_until` (reset to null or today + 30 days), and `notes`
- Copies all `QuotationItem` rows (same variant, quantity, unit_price, discount_percent, vat_rate_id) — totals recalculated fresh by `QuotationService`
- A new `quotation_number` is generated from the default series
- Redirects to the new quotation's Edit page immediately after creation
- Primary use case: expired or rejected quotation where the customer calls back — historical record is preserved, new process starts clean

---

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

### SALES-4: Quick Sale ⚠️ PLACEHOLDER — fill after full Sales module review

Similar in concept to Express Purchasing. Details TBD — discuss once all 8 Sales nav items have been reviewed.

---

### SALES-5: Express Delivery tenant setting

A tenant setting `sales.express_delivery` (off by default) that issues stock directly on Customer Invoice confirmation, skipping the standalone Delivery Note document.

**Use case:** Counter sales, retail, or any business model where delivery and invoicing happen simultaneously at the point of sale.

**Constraint:** Bulgarian Счетоводен закон requires a delivery document for goods transport. This setting must be opt-in, clearly labelled as non-default, and should display a warning on activation. It is NOT appropriate for B2B goods deliveries that require a separate delivery document.

**Mirror:** Mirrors the `purchases.express_receiving` setting introduced for Express Purchasing (`SupplierInvoiceService::confirmAndReceive()`).

**Dependency:** CI-1 (the warning + "Create DN" button) is the prerequisite — implement CI-1 first. SALES-5 adds the escape hatch for tenants that want to skip the DN entirely.

---

### SALES-CURRENCY-1: Customer Invoice — Art. 91 EU VAT compliance for foreign-currency invoices

EU VAT Directive 2006/112/EC, Article 91 requires that when an invoice is issued in a currency other than the Member State's functional currency (EUR), the VAT amounts **must be expressed in EUR** on the invoice using the exchange rate published by the European Central Bank (or equivalent national bank) applicable on the date of the chargeable event (`issued_at`).

This applies to **Customer Invoice only** — not Quotations, Sales Orders, or Delivery Notes (commercial documents, not tax documents).

**Exchange rate convention confirmed:** `exchange_rate` is stored as "1 EUR = X document_currency" (ECB convention). Conversion: `amount_EUR = amount_document_currency / exchange_rate`. Confirmed from `EuOssService::calculateOssLiability()` line 67.

**What needs changing when implemented:**
- Customer Invoice PDF: when `currency_code ≠ EUR`, show a "VAT in EUR" column or footer alongside the document-currency column — `vat_amount_eur = vat_amount / exchange_rate`, `total_eur = total / exchange_rate`.
- Customer Invoice view page (infolist): show EUR-equivalent totals in the Financial Summary section when foreign currency.
- The `exchange_rate` on the invoice must be the rate for `issued_at`, not the current rate. The existing `issued_at`-scoped rate lookup in `CurrencyRateService::getRate()` already handles this correctly.

**SUPTO fiscal printers note:** Bulgarian fiscal printers (SUPTO) always issue receipts in local currency (EUR). For foreign-currency sales, the fiscal receipt is always in EUR at the stored `exchange_rate`. No change to the document data model is needed for this — it is a fiscal receipt rendering concern. Tag this for the Fiscal module when SUPTO integration is implemented.

**Constraint:** Do NOT implement for Quotations or Sales Orders — they are commercial, not tax, documents.

---

## Medium — Multi-file, some design needed

### VAT-DETERMINATION-1: VAT type determination at Customer Invoice confirmation 🔄 IN PROGRESS → `tasks/vat-vies/`

**Implemented.** All five EU VAT scenarios are now handled at confirmation. Key design decisions below for reference.

**Five VAT scenarios (implemented):**

| Scenario | Condition | Tax treatment |
|----------|-----------|---------------|
| 1. Domestic | Partner country = tenant country | Standard VAT rate per item |
| 2. EU B2B (reverse charge) | Partner EU country ≠ tenant country + valid VAT number | 0% VAT, `is_reverse_charge = true`, reverse charge notation on PDF |
| 3. EU B2C — under OSS threshold | Partner EU country ≠ tenant country, no valid VAT, cumulative B2C < €10,000 | Tenant's domestic VAT rate |
| 4. EU B2C — over OSS threshold | Same, cumulative B2C ≥ €10,000 | Destination country VAT rate (via `EuOssService`) |
| 5. Non-EU export | Partner country outside EU | 0% VAT, no reverse charge |

**Final design:**

- `VatScenario` enum — `determine(Partner, tenantCountry, ignorePartnerVat: bool = false)`. `ignorePartnerVat = true` skips `hasValidEuVat()` check while still running OSS threshold check (used when VIES rejects the VAT number at confirm time).
- `CustomerInvoiceService::confirm(bool $treatAsB2c = false)` — passes `ignorePartnerVat: $treatAsB2c` to `VatScenario::determine()`.
- `ViesValidationService` — returns `available` (reachable) + `valid` (confirmed valid). Transient failures (`available: false`) are NOT cached so next call retries.
- **Three-way VIES branch at confirm (EU B2B only):**
  - VIES unavailable → warn + proceed with reverse charge (stored VAT data is the source of truth)
  - VIES confirms valid → proceed with reverse charge
  - VIES explicitly invalid → halt, set `$viesInvalidDetected` on page, user sees "Confirm with Standard VAT" action
- **"Confirm with Standard VAT"** — calls `confirm(treatAsB2c: true)`. Correctly re-runs OSS threshold check; does NOT hardcode under-threshold scenario.
- Company Settings country_code field + Partner form country_code field added.
- 20 tests in `tests/Feature/VatDeterminationTest.php`.

**Remaining gap → VAT-VIES-1:** VIES lookup button on Partner form to pre-validate VAT numbers and store `vies_verified_at`/`vies_valid`. See VAT-VIES-1 above.

---

### CI-PAID-1: Customer Invoice `Paid` status + Advance Payment module — Phase 4 forward reference

`DocumentStatus::Paid` is already referenced in `ViewCustomerInvoice` action visibility conditions (Create Credit Note, Create Debit Note show on `Confirmed | Paid`). The status itself is defined on the enum but is currently unreachable — there is no payment workflow in Phase 3.2.

The full payment module (Phase 4 — Payments/Reconciliation) will introduce: `Payment` model, `PaymentAllocation` (morphMany on invoices), `InstallmentSchedule`, bank import/reconciliation, and the `amount_paid` update path that transitions a CI to `Paid` status.

**Advance Payment module — items to address in Phase 4:**

1. **Bank account + payment reference field on AdvancePayment.** For `PaymentMethod::BankTransfer`, НАП and ЗДДС Art. 72(1)(6) require a reference that allows matching the advance to a bank statement. Add `bank_reference` (nullable text) shown only when `payment_method = BankTransfer`. Needed for bank reconciliation in Phase 4.

2. **Advance invoice VAT determination blocked on VAT-DETERMINATION-1.** `AdvancePaymentService::createAdvanceInvoice()` currently uses the default system VAT rate, not the scenario-aware determination from VAT-DETERMINATION-1 (reverse charge, OSS, non-EU export). Do NOT fix this in isolation — implement VAT-DETERMINATION-1 first, then wire the same `determineVatType()` call into `createAdvanceInvoice()`. Tag as a dependency when VAT-DETERMINATION-1 is scheduled.

3. **Percentage-based advance shortcut on SO-linked advances.** When `sales_order_id` is set, allow the user to enter an advance as X% of the SO total (e.g. "30% deposit") rather than a raw amount. The service computes `amount = so.total * percent / 100`. Common in B2B contracts. SAP Business One calls this a "Percentage Advance."

4. **Refund flow design — confirmed advance invoice must be credited first.** If an advance has a confirmed advance invoice (VAT declared to НАП), the refund cannot simply flip `status = Refunded`. The correct flow: (a) user issues a CCN on the advance invoice to reverse the VAT, (b) advance invoice transitions to Credited, (c) then and only then `refund()` may proceed. This is the legal requirement under ЗДДС — VAT declared must be reversed before the money is returned. See AP-V1 in `tasks/phase-3.2-plan.md` for the implementation detail.

**No action needed in Phase 3.2 beyond the refactor findings already documented.** The visibility conditions in ViewCustomerInvoice are correct future-proofing. Revisit when Phase 4 is planned.

---

### PURCH-1: Apply infolist + `content()` pattern to all Purchases view pages

The `view-document-with-items.blade.php` pattern was introduced in Phase 3.1 (Purchases). After Phase 3.2 establishes the correct pattern (INFRA-V1 — proper `infolist()` + `content()` override on all 8 Sales resources), review all Purchases view pages and apply the same treatment.

**Scope:** PurchaseOrderResource, GoodsReceivedNoteResource, SupplierInvoiceResource, SupplierCreditNoteResource, PurchaseReturnResource.

**Work:** Add `infolist()` to each resource following the same section order (Identity → Related Documents → Financial Summary → Secondary Details → Notes), override `content()` on each view page, remove the shared Blade template if no other resources still use it.

**Dependency:** Complete INFRA-V1 in Phase 3.2 first — use the Sales infolists as the reference implementation.

---

### CATALOG-1: Brands / Manufacturers resource

Add a `Brand` entity to the Catalog navigation group.

- Model: `Brand` — `name` (translatable), `description`, `is_active`, soft deletes
- Relationship: `Product` belongs to `Brand` (nullable FK `brand_id`)
- Filament resource: `BrandResource` under `NavigationGroup::Catalog`
- RBAC: add `brand` permissions to seeder and roles

---

### CATALOG-6: Auto-generated product codes via NumberSeries
Product codes auto-generated from a configurable series, per `ProductType`.

- Reuses the `NumberSeries` model with `SeriesType::Product`
- Configured per ProductType (Stock, Service, Bundle get separate series if desired)
- Auto-generates on product creation; user can always manually override
- Company setting `product_code_auto` (default: `true`) — when `false`, code field is fully manual
- CATALOG-3 dependency is met (category defaults implemented 2026-04-14)

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

### SYSTEM-1: Global "Reassign user documents" tool

When a user is deactivated or removed from the system, their open (non-terminal) documents across all modules need to be reassigned to another user.

**Scope:** Every document with a `created_by` FK — Quotations, Sales Orders, Delivery Notes, Customer Invoices, Credit Notes, Debit Notes, Sales Returns, Advance Payments, Purchase Orders, GRNs, Supplier Invoices, Credit Notes, Purchase Returns.

**Rule:** Only reassign documents in non-terminal statuses. Terminal documents (Confirmed invoices, Received GRNs, Cancelled orders, etc.) keep their original `created_by` forever — this is the audit trail, legally required in EU jurisdictions.

**Implementation:**
- Trigger point: User deactivation action in Settings → Users (or Landlord panel)
- Confirmation modal shows count of open documents per type
- "Reassign to" user selector — target user may be different from the person doing the deactivation
- Single transaction — all document types updated atomically
- Activity log entry on each reassigned document: "Reassigned from [old user] to [new user] by [admin]"

**Prerequisite:** `created_by` filter on document list views (needed for daily management — "show me what Sales Rep X has open") — add this filter to all Sales and Purchases list views.

---

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
