# Phase 3.1 Refactor — Review Findings

Issues found during structured review of the Phase 3.1 Purchases pipeline.
Review order: Purchase Orders → Goods Receipts → Supplier Invoices → Supplier Credit Notes.

Each item captures: **What** (the problem), **Why** (the reasoning), **How** (the fix).
Infrastructure items are listed first — they unblock document-level fixes.

## Implementation Status

| Item | Status |
|------|--------|
| INFRA-1 | ⬜ |
| INFRA-2 | ⬜ |
| INFRA-3 | ⬜ |
| INFRA-4 | ✅ |
| INFRA-5 | ✅ |
| PO-1 | ✅ |
| PO-2 | ✅ |
| PO-3 | ⬜ |
| PO-4 | ✅ |
| PO-5 | ✅ |
| PO-6 | ⬜ |
| CATALOG-BUG-1 | ✅ |
| GRN-1 | ⬜ |
| SI-1 | ⬜ |
| SI-2 | ⬜ |
| SI-3 | ⬜ |
| PR-1 | ⬜ |

---

## Infrastructure (Cross-cutting)

Items that affect multiple documents and modules. Must be designed and built before
the document-level fixes that depend on them.

---

### INFRA-1: Currency Rate Manager

**What:**
There is no centralized exchange rate management. Every document (PO, Supplier Invoice,
Credit Note, and eventually Sales documents) has a dumb `exchange_rate` text field.
The user types a number. There is no lookup, no reuse, no validation.

**Why:**
Exchange rates must have a single source of truth per currency per day. If today's
EUR/USD rate is 1.082, it should be entered once and reused across all documents
created that day — not typed 40 times across 40 records.

At the same time, confirmed/posted documents must be immutable. A confirmed Supplier
Invoice has a legal EUR value that cannot change retroactively. If we only stored
`currency_id` and always looked up the current rate, updating the rate would silently
alter historical financials — which is illegal under EU accounting law.

Per-document override must also remain possible: a specific transaction may have a
different agreed rate (bank spread, forward contract, etc.).

**How:**
- New `currency_rates` table: `(currency_code, date, rate)` — unique per `(currency_code, date)`
- `CurrencyRateService`: resolves rate for `(currency, date)`. If no rate exists for today
  → surface a prompt/warning directing the user to enter it. Once entered, it is saved
  to `currency_rates` and reused for all documents that day.
- On document **creation** (Draft): `exchange_rate` is auto-filled from `currency_rates`
  for today. No manual typing required.
- On document **confirmation/posting**: the current rate is snapshotted into the
  document's own `exchange_rate` field and frozen. The document now owns its rate.
- Documents in **Draft**: rate is sourced dynamically from `currency_rates` — fix the
  daily rate once and all drafts reflect it.
- Documents **confirmed**: rate is locked on the document. Immune to `currency_rates`
  updates. Can still be overridden manually on that specific document if needed.

**Scope:** Infrastructure — affects PO, GRN, Supplier Invoice, Supplier Credit Note,
and all future Sales documents.

---

### INFRA-2: Number Series Auto-Resolution

**What:**
All document create forms (PO, GRN, Supplier Invoice, Supplier Credit Note) include a
"Number Series" dropdown. If the user does not select a series, no document number is
generated. A document with no number is invalid.

**Why:**
Number series is a system configuration, not a per-document user decision. The business
owner configures one active series per document type in Settings (e.g. "PO-2026-XXXX"
for Purchase Orders) and it is used automatically from that point forward. Users should
never see or interact with series selection when creating a document.

Exposing this selector is confusing, error-prone (user forgets → no number), and
architecturally wrong — it leaks a configuration concern into an operational workflow.

**How:**
- Remove the `document_series_id` / Number Series selector from **all** document
  create **and edit** forms. The form schema is shared, so one change covers both.
  On Edit, the number is already generated — the series reference is stored on the
  record but has no business being visible or editable.
- `NumberSeries::getDefault(SeriesType $type)` already exists on the model — it finds
  the active series marked `is_default = true` for a given type. Call this automatically
  in `mutateFormDataBeforeCreate` on each document's Create page.
- If no default series exists → throw a `ValidationException` with a clear, actionable
  message: *"No active number series configured for Purchase Orders. Go to Settings →
  Number Series."* This prevents the 500 crash currently caused by a null `po_number`
  hitting the DB not-null constraint.
- The `is_default` checkbox on the NumberSeries form is the correct configuration
  mechanism. It stays as-is.
- The Number Series settings page remains unchanged — that is where configuration lives.

**Current failure mode (confirmed via manual test):**
No series selected → `po_number` is `null` → DB not-null constraint violation →
unhandled 500 `QueryException`. No user-friendly error shown.

**Scope:** Infrastructure — affects PO, GRN, Supplier Invoice, Supplier Credit Note.

---

### INFRA-3: Related Documents navigation panel on all view pages

**What:**
There is no way to navigate between related documents in the pipeline. A GRN shows a
PO number as text — it is not clickable. A PO has no indication of how many GRNs or
Supplier Invoices have been created against it. The user must return to the list and
search manually to find related documents.

**Why:**
Navigating the document chain (PO → GRN → SI → SCN) is a routine daily task for
purchasing staff. Without direct links, users lose context and waste time. An ERP
without document cross-navigation is not usable in production.

**How:**
Add a "Related Documents" infolist section to each view page. Compact, read-only,
always visible. Links open the related document's view page directly.

Relationships per document:

| View page | Upward link (one parent) | Downward links (many children) |
|-----------|--------------------------|-------------------------------|
| PO | — | Goods Receipts, Supplier Invoices |
| GRN | Purchase Order | — |
| SI | Purchase Order | Supplier Credit Notes |
| SCN | Supplier Invoice | — |

Implementation:
- Use a Filament `Section` with `TextEntry` components for parent links and a compact
  list (or `RepeatableEntry`) for child links.
- Each link renders as a clickable URL via `->url(fn() => Resource::getUrl('view', $id))`.
- Show child counts inline: *"Goods Receipts (2)"*, *"Supplier Invoices (1)"*.
- If no related documents exist for a direction, omit that sub-section (don't show
  empty panels).

**Scope:** Infrastructure — affects `ViewPurchaseOrder`, `ViewGoodsReceivedNote`,
`ViewSupplierInvoice`, `ViewSupplierCreditNote`.

---

### INFRA-4: Empty draft warning banner on all document view pages

**What:**
A document (PO, GRN, SI, SCN) can be saved in Draft with no line items. No visual
indicator warns the user that the document is incomplete and cannot be confirmed.
The problem only surfaces when the user tries to confirm — at which point the service
throws an error notification. There is no proactive signal.

**Why:**
Blocking save on an empty draft is wrong — the save-then-fill workflow is valid
(e.g. a warehouse manager creates the GRN header, then staff fill in quantities).
The correct place to enforce completeness is at status transition, not at save time.

However, the UX gap between "saved empty" and "discovered problem at confirmation" is
real. A user can create an empty draft, leave it in the list, come back days later, and
only then discover it's stuck.

**How:**
On each document view page, when the record is in `Draft` status and has no items,
show an inline warning banner above the items relation manager:
*"No items added — this document cannot be confirmed until at least one item is added."*

- Use Filament's `InfolistEntry` or a `Placeholder` component rendered conditionally.
- Banner is only visible in `Draft` state with zero items. Disappears once items exist
  or once the document is no longer Draft.
- No blocking, no modal — purely informational.

**Scope:** Infrastructure — affects `ViewPurchaseOrder`, `ViewGoodsReceivedNote`,
`ViewSupplierInvoice`, `ViewSupplierCreditNote`.

---

### INFRA-5: Status-change actions must redirect after completing (all four view pages)

**What:**
Every status-change action across all four document view pages is missing a redirect
after it completes. After the action fires, the parent view page and the items relation
manager (a separate embedded Livewire component) re-render at slightly different times.
This creates a brief window where the relation manager still holds stale state —
Create/Edit/Delete buttons remain briefly visible on an immutable document.

Confirmed affected actions:

| Page | Actions |
|------|---------|
| `ViewPurchaseOrder` | send, confirm, cancel |
| `ViewGoodsReceivedNote` | confirm_receipt, cancel |
| `ViewSupplierInvoice` | confirm, cancel |
| `ViewSupplierCreditNote` | confirm, cancel |

**Why:**
`isReadOnly()` on each relation manager is correct — it checks `$record->isEditable()`.
The problem is purely timing: the relation manager is an independent Livewire component
and re-renders slightly after the parent page. On a slow connection this window is wide
enough for a user to click an action button on a confirmed/cancelled document — which
would attempt a mutation the service would reject, but the UX is broken either way.

A redirect is also better UX for irreversible state changes: a full page reload makes
the new status immediately unambiguous.

**How:**
In each view page, add a redirect to the same view page at the end of each affected action:
```php
$this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
```
Send the success notification before redirecting — Filament flashes it across the
redirect via the session.

**Scope:** `ViewPurchaseOrder`, `ViewGoodsReceivedNote`, `ViewSupplierInvoice`,
`ViewSupplierCreditNote` — all status-change actions listed above.

---

## Purchase Orders

---

### PO-1: "Send to Supplier" label is misleading

**What:**
The status transition button on the PO View page is labelled "Send to Supplier". It does
not send anything — no email, no PDF, no notification. It only changes the status from
`Draft` to `Sent`.

**Why:**
The label implies the system performs an action (dispatch). It doesn't. This creates
confusion about whether something has actually been communicated to the supplier.

**How:**
- Rename to "Mark as Sent" — honest about the current behaviour.
- Phase 3.2 includes document generation (Blade + DomPDF — see `docs/STATUS.md`).
  When that lands, this button gets split: "Send to Supplier" triggers PDF generation
  + email dispatch + status change. Until Phase 3.2 ships, the label must not
  promise something the system doesn't do.

---

### PO-3: Cancel does not cascade to child documents

**What:**
Cancelling a `Confirmed` PO that has draft GRNs or draft Supplier Invoices attached
does nothing to those child documents. They remain alive pointing to a cancelled PO.
A user could still attempt to confirm a GRN against a cancelled PO.

**Why:**
A cancelled PO is a dead document. Any child documents in `Draft` state have no
parent to fulfil — they must be cancelled too. Leaving them open is data corruption.

**How:**
- In the Cancel confirmation modal: query for related draft GRNs and draft Supplier
  Invoices. If any exist, list them explicitly in the modal body:
  *"This will also cancel: 2 draft Goods Receipts, 1 draft Supplier Invoice."*
- On confirm: cancel the PO and all listed child documents in a single DB transaction.
- Only `Draft` children are auto-cancelled. If a child is already `Confirmed`
  (e.g. a GRN that has been confirmed and moved stock), the PO cancellation should
  be blocked — see PO-4.
- **Rename the button.** "Cancel" reads as "dismiss this screen / abort this action"
  — standard UI convention for form cancel buttons. A user will hesitate or
  misinterpret it. Rename to **"Cancel Order"** — unambiguous, clearly targets the
  document not the interaction.

---

### PO-4: Cancel must be blocked (not just hidden) once stock has been received

**What:**
The Cancel button is correctly hidden for `PartiallyReceived` status in the UI.
However, `PurchaseOrderService::validTransitions` still lists
`PartiallyReceived → Cancelled` as a valid transition. The guard lives only in the
UI — not in the service layer.

**Why:**
Once any stock has physically entered the warehouse (at least one GRN is confirmed),
cancelling the PO is not a valid business operation. The physical reality cannot be
undone by changing a document status. The `PartiallyReceived` and `Received` states
represent real-world events — a PO in these states is closed, not cancellable.

**How:**
- Remove `PartiallyReceived → Cancelled` from `validTransitions` in
  `PurchaseOrderService`. The service is the authoritative guard — the UI should not
  be the only thing preventing this.
- Remove `Received → Cancelled` as well (it is not in `validTransitions` currently
  but worth making explicit).
- For `Confirmed` POs: if any linked GRN is already `Confirmed` (stock received),
  block the cancellation entirely with a clear error:
  *"Cannot cancel: goods have already been received against this order."*

---

### PO-2: Status transitions allowed on empty POs (no line items)

**What:**
A PO with zero line items can be transitioned to `Sent` (and likely `Confirmed`) via
the header action buttons. No validation prevents it.

**Why:**
A Purchase Order with no items is not a valid business document. You cannot order
nothing from a supplier. Transitioning an empty PO to `Sent` or `Confirmed` corrupts
the document pipeline — a GRN or Supplier Invoice could be created against a PO that
has no items to receive or invoice.

**How:**
- In `PurchaseOrderService::transitionStatus()`, before applying any transition out of
  `Draft` (i.e. to `Sent` or `Cancelled`), validate that `$po->items()->exists()`.
- If no items → throw a `ValidationException` / return a user-facing error:
  *"Cannot send a Purchase Order with no line items."*
- Same guard for the `Sent → Confirmed` transition.
- The `→ Cancelled` transition is exempt — cancelling an empty draft is valid.

---

---

### PO-5: PO line item form — ambiguous labels and missing VAT auto-fill

**What:**
The "Create Purchase Order Item" modal has three issues:
- "Unit Price" label is ambiguous — could mean sale price or purchase price.
- "Discount %" label doesn't clarify whose discount or in which direction.
- VAT Rate does not auto-fill from the product's configured VAT rate. The user must
  select it manually every time, even if the product always uses the same rate.

**Why:**
On a Purchase Order, every price is a cost/purchase price — never a sale price.
The labels must reflect that context. A user seeing "Unit Price" cannot immediately
tell whether this is what they pay or what they charge.

VAT rate lives on `Product` (`vat_rate_id`). When a variant is selected, the code
already loads the variant to fill `unit_price` and `description`. It stops one step
short of fetching `$variant->product->vat_rate_id`.

**How:**
- Rename "Unit Price" → **"Purchase Price"**
- Rename "Discount %" → **"Supplier Discount %"**
- In `afterStateUpdated` on the variant selector: after setting `unit_price`,
  also set `vat_rate_id` from `$variant->product->vat_rate_id` if it exists.
  If the product has no VAT rate set, leave the field empty — do not guess.

---

### PO-6: Product variant dropdown — visibility and label rules

**What:**
The PO line item variant selector queries all active variants with no filter on
`is_default`. Two problems:

1. For products WITH named variants (T-Shirt S/M/L), the dropdown shows the default
   variant alongside the named ones. The default variant has no business meaning here
   — you cannot order "a T-Shirt", only "T-Shirt Size S". It is a technical construct
   and must not appear in document forms when named variants exist.

2. The label format `"{SKU} — {product.name}"` is used for every variant. Named
   variants show the product name but not their own name — you cannot tell Size S
   from Size M by the label alone.

**Why:**
The default variant exists purely to satisfy the always-variant pattern (stock is
always tracked at variant level). For products WITHOUT named variants (Bolt M8,
Office Paper), the default variant IS the product and is the correct selectable item.
For products WITH named variants, the default variant is invisible infrastructure —
exposing it to users creates confusion and invalid document lines.

**How:**
- **Visibility rule**: show default variant only when the product has no named variants.
  `Product::hasVariants()` already exists for this check. In the dropdown query:
  load variants with their product, then filter in PHP — exclude any variant where
  `is_default = true` AND `$variant->product->hasVariants() = true`.
- **Label rules**:
  - Default variant (product has no named variants): `"{SKU} — {product.name}"`
  - Named variant: `"{SKU} — {product.name} / {variant.name (current locale)}"`
- This applies to ALL document form variant selectors — PO items, GRN items,
  SI items, SCN items, and all future Sales document line items.

---

### CATALOG-BUG-1: Default variant name stored as raw JSON string

**What:**
When a product is created, `Product::booted()` auto-creates the default variant and
passes the product's raw translatable name directly to the variant's `name` field:
```php
$name = $model->getRawOriginal('name'); // raw JSON: {"en":"...","bg":"..."}
$model->variants()->create(['name' => $name]);
```
`ProductVariant::name` is a translatable column (spatie/laravel-translatable). When
you pass a raw JSON string to a translatable attribute, spatie stores it as a plain
string for the current locale — not as a proper translation structure. The result:
`getTranslation('name', 'en')` returns the entire JSON blob instead of the English value.

**Why:**
`getRawOriginal('name')` returns the raw DB value — a JSON string. Spatie's
`setAttribute` for translatable fields expects either a plain string (sets for current
locale) or an associative array (sets all locales at once). A JSON string is treated
as a plain string, so the locale structure is lost.

**How:**
In `Product::booted()` created event, decode the raw name before passing it:
```php
$raw = $model->getRawOriginal('name') ?? $model->getAttributes()['name'];
$nameArray = is_string($raw) ? (json_decode($raw, true) ?? $raw) : $raw;
$model->variants()->create(['name' => $nameArray, ...]);
```
This passes the array `["en" => "...", "bg" => "..."]` to spatie, which stores it
correctly. All existing default variants with the broken name need a data migration.

**Scope:** Product model bug — affects all document forms that auto-fill description
from the default variant name (PO items, and all future Sales document line items).

---

## Goods Receipts

---

### GRN-1: Context-driven field visibility — header and items form

**What:**
The GRN form always shows the same fields regardless of whether a PO is linked. Two
specific problems:

1. The `partner_id` (supplier) field is visible even when the GRN is created from a PO
   and the supplier is auto-filled — it is redundant and read-only in that context.
2. The GRN items form always shows both a `PO Line Item` selector and a `Product`
   selector. When the GRN has no linked PO, the PO Line Item selector has nothing to
   offer. When a PO is linked, the Product field is auto-filled from the PO line and
   should not be independently editable.

**Why:**
A GRN can be created in two modes:
- **Linked to a PO** (standard flow): supplier and warehouse are inherited; items should
  be linked to PO lines to enable `qty_received` tracking.
- **Standalone** (no PO): goods arrive without a prior order — emergency purchase,
  unplanned delivery, opening stock. Supplier must be entered manually; there are no
  PO lines to link to.

Showing all fields in both modes creates noise and allows invalid states — e.g. a user
selecting a different product variant than what the PO line references.

**How:**
Two-mode form, driven by whether `purchase_order_id` is set:

*Header:*
- `partner_id` (supplier): **hide** when PO is linked (auto-filled, not user-editable);
  **show** when standalone (required, no PO to inherit from).

*Items form:*
- `PO Line Item` selector: **show** only when GRN has a linked PO. On selection,
  auto-fills variant, qty, unit cost, and sets `purchase_order_item_id`.
- `Product` selector: **show** when no PO linked (user picks variant manually).
  When PO is linked: hidden — variant is always sourced from the selected PO line.

*Service layer (`GoodsReceiptService::confirm()`):*
- Per-item: only call `updateReceivedQuantities()` when `purchase_order_item_id` is
  set. Items without a PO link still move stock via `StockService::receive()` — they
  just have no PO line to update.
- Document level: only update PO status (PartiallyReceived/Received) when a PO is
  linked to the GRN.

**Scope:** GRN Create/Edit form + `GoodsReceiptService`.

---

## Supplier Invoices

---

### SI-1: Context-driven field locking — currency, VAT mode, and exchange rate

**What:**
The SI form allows changing currency, pricing_mode (VAT inc/exc), and exchange_rate
regardless of whether a PO is linked. When a PO is linked, currency and pricing_mode
should be locked to the PO's values — they are inherited, not re-decided per invoice.

**Why:**
When an SI is created against a PO, the transaction currency and VAT treatment were
already decided at PO level. EU B2B purchasing is always VAT-exclusive; there is no
valid scenario where an SI linked to a PO would use a different currency or VAT mode.
Allowing these to be changed creates inconsistency between the PO commitment and the
invoice — a financial integrity problem.

Exchange rate is the one exception: it is always editable because the SI has its own
date, and the rate for that date may differ from the PO's rate. The PO rate is a
planning estimate; the SI rate is the legally binding AP entry. They serve different
purposes and can legitimately differ.

**How:**
Two modes driven by whether `purchase_order_id` is set:

*SI linked to PO:*
- `currency_code` → disabled, auto-filled from PO, not user-editable
- `pricing_mode` (VAT inc/exc) → disabled, auto-filled from PO, not user-editable
- `exchange_rate` → editable; auto-filled from currency manager for SI's own date
  (INFRA-1); can be manually overridden

*SI standalone (no PO):*
- `currency_code` → freely selectable
- `pricing_mode` → freely selectable; defaults to VAT-exclusive
- `exchange_rate` → auto-filled from currency manager for today; prompted if no
  rate exists for today (INFRA-1); can be manually overridden

**Scope:** SI Create/Edit form (`SupplierInvoiceForm`) + `mount()` on `CreateSupplierInvoice`.

---

### SI-2: Items form — context-driven variant filtering, import action, and quantity suggestions

**What:**
The SI items form has three gaps when the SI is linked to a PO:

1. **No "Import from PO" action** — the GRN has bulk import; the SI does not. Users must
   add every line manually even when invoicing against a known PO.

2. **Variant dropdown shows all active variants** — when a PO is linked, the dropdown
   should only show variants that are on the PO. Showing the full catalog is noise and
   invites mistakes.

3. **Quantity suggestion ignores already-added rows** — when adding a second row for the
   same variant, the suggested quantity does not account for quantities already entered
   on this SI.

**Why:**
When an SI is linked to a PO, the scope of the invoice is defined by the PO. Showing
unrelated variants pollutes the form. Not accounting for already-added quantities leads
to accidental over-entry row by row.

Free-text lines (no variant, description only) remain valid even when a PO is linked —
suppliers add shipping costs, handling fees, bank charges, etc. that are not on the PO.
These lines set `product_variant_id = null` and are not affected by variant filtering.

**How:**

*Import from PO (header action):*
- Add "Import from PO" header action, visible only when SI has a linked PO.
- Imports full PO line quantities (not remaining receivable qty — unlike GRN import).
  The SI records what the supplier billed, not what was received. User adjusts after
  import if needed.
- Links `purchase_order_item_id` on each imported line for traceability.

*Variant dropdown (single-add form):*
- When SI linked to PO: filter dropdown to variants present on the PO only.
- Variant field can still be left empty (free-text line) — no change to that behaviour.
- When SI standalone: show all active variants (current behaviour).

*Quantity suggestion:*
- When a variant is selected and a PO is linked: suggested quantity =
  PO line quantity − sum of quantities already added on this SI for that variant.
- If the entered quantity exceeds the PO line quantity, show a **warning** (not a
  blocking error) — intentional over-invoicing is a valid business operation
  (negotiated additions, etc.).

**Scope:** `SupplierInvoiceItemsRelationManager`.

---

### SI-3: Express purchasing — "Confirm & Receive" fast-track (tenant setting)

**What:**
The full PO → GRN → SI pipeline is correct for businesses with separation of duties.
For micro-businesses (one person handles purchasing, receiving, and accounting), the
pipeline is overkill — receiving 5 toner cartridges from a known supplier requires
navigating multiple forms to log a single transaction.

A tenant-level setting enables a "Confirm & Receive" fast-track on the SI: one action
that confirms the invoice and receives the goods into stock simultaneously.

**Why:**
The shortcut must be opt-in and off by default. A larger company relies on the pipeline
for internal controls (purchasing manager approves, warehouse confirms receipt,
accountant books the invoice — three separate roles). Enabling this by default would
silently bypass those controls. It is a workflow preference, not a default behaviour.

The warehouse belongs on the GRN — not on the SI. Adding `warehouse_id` to
`supplier_invoices` would leak a warehouse concern into a financial document. The
action modal captures the warehouse for the GRN without polluting the SI data model.

**How:**

*Tenant setting:*
- New boolean setting in Settings panel: **"Express Purchasing"** (default: off).
- When off: SI confirm flow is unchanged. No extra button, no extra field.
- When on: "Confirm & Receive" button appears on `ViewSupplierInvoice` alongside
  the regular "Confirm" button.

*"Confirm & Receive" action:*
- Opens a confirmation modal with a single **warehouse selector** field.
- Pre-selects the default warehouse (`Warehouse::isDefault()`). User can change.
- On confirm, executes a single DB transaction:
  1. Confirm the SI (`SupplierInvoiceService::confirm()`)
  2. Create a GRN linked to the SI's `partner_id`, the selected warehouse, and today
     as `received_at`. If SI is linked to a PO, link the GRN to that PO as well.
  3. For each SI line item that has a `product_variant_id`: create a GRN item
     (variant, quantity, unit_cost from SI line).
  4. Confirm the GRN (`GoodsReceiptService::confirm()`) → stock moves in.
- The created GRN is visible from the SI view page via the Related Documents panel
  (INFRA-3). The user sees the audit trail without having navigated to it manually.
- Free-text SI lines (no variant) are skipped — no stock to receive.

*No schema changes to `supplier_invoices`* — warehouse lives on the GRN as it always has.

**Scope:** New tenant setting + `ViewSupplierInvoice` + new service method coordinating
`SupplierInvoiceService` and `GoodsReceiptService`.

---

## Purchase Returns

*New document type — not yet in the pipeline.*

---

### PR-1: Purchase Return document — physical return of goods to supplier

**What:**
There is no way to record a physical return of goods to a supplier. When defective
goods must be sent back, or an over-delivery needs to be returned, the only available
paths are: a Supplier Credit Note (financial only, no stock movement) or a manual stock
adjustment (opaque, no link to the original receipt). Neither models a purchase return
correctly.

**Why:**
A purchase return is a distinct business event: goods leave the warehouse and go back
to the supplier. It has two separate downstream effects:

1. **Physical** — stock decreases: `StockService::issue()` per returned line.
2. **Financial** — a credit becomes due from the supplier: a Supplier Credit Note.

These two effects are separate workflows and must not be forced into the same document.
A Supplier Credit Note is a financial document. It does not move stock. When stock
must also be returned, a Purchase Return is the correct trigger for the stock movement.
The financial credit (SCN) is initiated separately — either manually by the accountant
or (optionally) auto-prompted after the return is confirmed.

The distinction also matters for businesses that return goods without expecting a
credit (warranty replacement, exchange programs) — forcing an SCN on every return
would produce phantom financial entries.

**How:**

*New model stack (mirrors GRN + SCN):*
- `PurchaseReturn` model + migration + factory + soft deletes + `LogsActivity`
- `PurchaseReturnItem` model + migration + factory
- `PurchaseReturnStatus` enum: `Draft`, `Confirmed`, `Cancelled`
- New `SeriesType::PurchaseReturn` value — number series settings for return documents

*Primary link: GRN, not PO or SCN.*
A return reverses a receipt. The GRN is the document that recorded the goods arriving;
it has the line items, quantities, and `unit_cost` values needed to create return lines.
Linking to the GRN (not the PO) also handles standalone GRNs (receipts that had no PO).

*`remainingReturnableQuantity()` on `GoodsReceivedNoteItem`:*
Mirrors `remainingCreditableQuantity()` on `SupplierInvoiceItem`. Returns:
`qty_received − sum(return_items.quantity WHERE return.status != Cancelled)`.
Used to cap return quantities and prevent double-returning the same receipt line.
Must use `lockForUpdate()` inside a DB transaction when creating return items — same
race safety pattern as SCN.

*`PurchaseReturnService::confirm()`:*
1. Validates `Draft` state and that items exist.
2. For each item: `StockService::issue($variant, $qty, $warehouse, $reference)` where
   reference is the `PurchaseReturn` morph alias.
3. Movement type: `MovementType::PurchaseReturn` (new enum value).
4. Does NOT auto-create a Supplier Credit Note — physical and financial flows are
   separate. A future action ("Create Credit Note") can be added to `ViewPurchaseReturn`
   once SCN creation is covered by a proper service.

*Context-driven form:*
- Purchase Returns should always link to a GRN (returns need a receipt to reverse).
  Standalone returns (no GRN link) are technically possible but out of scope for now.
- When created from `ViewGoodsReceivedNote` via a "Create Return" action:
  - `goods_received_note_id`, `partner_id`, and `warehouse_id` are auto-filled from
    the GRN — not user-editable.
  - Items import pre-filled from GRN lines; user adjusts quantities as needed.
- `return_number` auto-generated from `SeriesType::PurchaseReturn` (INFRA-2 handles
  number series auto-resolution — same logic).
- `returned_at` date field (defaults to today).
- Optional `reason` text field.

*Morph map:* Register `purchase_return` in `AppServiceProvider`.
*RBAC:* Add `purchase_return` and `purchase_return_item` to the models array
(10 new permissions). `purchasing-manager` gets full CRUD; `warehouse-manager` gets
confirm + view.

*Tests required:*
- Confirm → stock decreases via `StockService::issue()`
- `remainingReturnableQuantity()` respects cancelled returns
- Cannot return more than received (single return + cumulative)
- Confirmed return is immutable
- Policy coverage for `purchasing-manager` and `warehouse-manager`

**Scope:** Full new document stack — model, migration, factory, service, Filament
resource with items RM, `ViewGoodsReceivedNote` action, morph map, RBAC, tests.
