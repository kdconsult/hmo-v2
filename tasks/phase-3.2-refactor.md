# Phase 3.2 Refactor — Review Findings

Issues found during structured review of the Phase 3.2 Sales pipeline.
Review order: Quotations → Sales Orders → Delivery Notes → Customer Invoices →
Customer Credit Notes → Customer Debit Notes → Sales Returns → Advance Payments.

Each item captures: **What** (the problem), **Why** (the reasoning), **How** (the fix).
Tiers define implementation order. Tier 0 runs first (schema), Tier 5 last (view overhaul).

**Excluded (tracked in backlog, not here):**
- `VAT-DETERMINATION-1` / `CI-C1` — is_reverse_charge logic, APP-BREAKING, requires separate design
- `SALES-5` — Express delivery setting, out of scope for this refactor

---

## Implementation Status

### Tier 0 — Migration

| Item | Description | Status |
|------|-------------|--------|
| SR-CCN-1 | Add `sales_return_id` FK to `customer_credit_notes` | [x] |

### Tier 1 — Cross-Cutting (all/most resources)

| Item | Description | Status |
|------|-------------|--------|
| INFRA-L1 | Partner filter + date range filter on all 8 list pages | [x] |
| INFRA-L2 | Fix NULL-first sort on issued_at across all 8 tables | [x] |
| INFRA-L3 | Add `items_count` column to all 8 tables | [x] |
| EDIT-GUARD | isEditable() mount guard on 5 missing Edit pages | [x] |
| TABLE-EDIT | Guard edit table action with isEditable() on all 8 tables | [x] |
| TABLE-BULK | Guard bulk delete to Draft-only on all 8 tables | [x] |
| ENUM-FIX | Replace raw string status filters with enum values in 6 forms | [x] |
| MONEY-FIX | Replace hardcoded `->money('EUR')` with dynamic currency in 6 tables | [x] |

### Tier 2 — Service Layer Hardening

| Item | Description | Status |
|------|-------------|--------|
| SO-B1 | Auto-set issued_at on Draft→Confirmed in SalesOrderService | [ ] |
| CI-2 | Over-invoice guard in CustomerInvoiceService::confirm() | [ ] |
| CI-V2 | Extract inline cancel into CustomerInvoiceService::cancel() | [ ] |
| CCN-V2 | Add confirm() + cancel() to CustomerCreditNoteService | [ ] |
| CDN-V2 | Add confirm() + cancel() to CustomerDebitNoteService | [ ] |
| AP-V1 | Gate AdvancePaymentService::refund() — check no confirmed advance invoice | [ ] |
| AP-V2 | Add AdvancePaymentService::reverseApplication() | [ ] |

### Tier 3 — Form-Level Fixes

| Item | Description | Status |
|------|-------------|--------|
| QUO-C2 | Filter inactive partners in Quotation form | [ ] |
| QUO-C3 | Add minDate(now()) to valid_until in Quotation form | [ ] |
| QUO-C5 | Lock pricing_mode when items exist | [ ] |
| SO-C2 | Remove dead mount() quotation pre-fill from CreateSalesOrder | [ ] |
| SO-C3 | Lock partner_id when quotation_id is set | [ ] |
| DN-C1 | Lock warehouse_id when sales_order_id is set | [ ] |
| DN-C2 | Change delivered_at default from now() to null | [ ] |
| CI-C2 | Lock invoice_type when sales_order_id is set; include in fill() | [ ] |
| CCN-F2 | Add required reason textarea to CustomerCreditNote form | [ ] |
| CCN-F3 | Auto-fill items from parent invoice on CCN create | [ ] |
| CCN-F4 | Validate credit qty ≤ invoiced qty | [ ] |
| CDN-F2 | Add required reason textarea to CustomerDebitNote form | [ ] |
| CDN-F3 | Auto-fill items from parent invoice on CDN create | [ ] |
| CDN-F4 | Validate debit qty ≤ invoiced qty | [ ] |
| SR-F1 | Auto-fill items from parent delivery note on SalesReturn create | [ ] |
| SR-F2 | Validate return qty ≤ delivered qty | [ ] |
| AP-F1 | Make received_at required in AdvancePayment form | [ ] |
| AP-F2 | Make payment_method required (not nullable) | [ ] |
| AP-F3 | Add amount validation (> 0, ≤ SO remaining balance) | [ ] |

### Tier 4 — View Actions + Related Documents

| Item | Description | Status |
|------|-------------|--------|
| QUO-L5 | Add status badge column to Quotations table | [ ] |
| QUO-L6 | Add valid_until column with expired highlighting | [ ] |
| QUO-V1 | Guard "Convert to SO" — hide if SO already exists | [ ] |
| QUO-V2 | Show linked SO in Quotation related documents | [ ] |
| SO-L1 | Add fulfillment % column to SalesOrders table | [ ] |
| SO-V1 | Show linked Quotation in SalesOrder related documents | [ ] |
| DN-L1 | Add warehouse column to DeliveryNotes table | [ ] |
| DN-L2 | Add delivered_at column to DeliveryNotes table | [ ] |
| DN-V1 | Add "Create Invoice" button on confirmed DN view page | [ ] |
| CI-L1 | Add due_date column with overdue highlighting to CustomerInvoices table | [ ] |
| CI-1 | Show advance payment deductions in CI total display | [ ] |
| CI-V1 | Show applied advance payments in CI related documents | [ ] |
| SR-V1 | "Create CCN" action must pass sales_return_id (needs SR-CCN-1) | [ ] |
| SR-V2 | Show linked CCN in SalesReturn related documents | [ ] |
| SR-CCN-2 | Pre-fill sales_return_id in CreateCustomerCreditNote mount() | [ ] |
| AP-T2 | Add status badge column to AdvancePayments table | [ ] |
| AP-T3 | Add remaining amount computed column to AdvancePayments table | [ ] |

### Tier 5 — Infolist Views (INFRA-V1)

| Item | Description | Status |
|------|-------------|--------|
| INFRA-V1-QUO | Replace Blade view with infolist in ViewQuotation | [ ] |
| INFRA-V1-SO | Replace Blade view with infolist in ViewSalesOrder | [ ] |
| INFRA-V1-DN | Replace Blade view with infolist in ViewDeliveryNote | [ ] |
| INFRA-V1-CI | Replace Blade view with infolist in ViewCustomerInvoice | [ ] |
| INFRA-V1-CCN | Replace Blade view with infolist in ViewCustomerCreditNote | [ ] |
| INFRA-V1-CDN | Replace Blade view with infolist in ViewCustomerDebitNote | [ ] |
| INFRA-V1-SR | Replace Blade view with infolist in ViewSalesReturn | [ ] |
| INFRA-V1-AP | Replace Blade view with infolist in ViewAdvancePayment | [ ] |
| CI-V3 | Tax breakdown section in CI view infolist | [ ] |
| CI-V4 | Payment status timeline in CI view infolist | [ ] |
| CCN-V1 | Proper infolist sections for CCN view | [ ] |
| CDN-V1 | Proper infolist sections for CDN view | [ ] |
| DELETE-BLADE | Delete view-document-with-items.blade.php + view-document.blade.php | [ ] |

---

## Tier 0 — Migration

### SR-CCN-1: Add `sales_return_id` FK to `customer_credit_notes`

**What:** The `customer_credit_notes` table has no `sales_return_id` column. A CCN raised
from a Sales Return cannot record which return originated it.

**Why:** When a customer returns goods (Sales Return), the resulting credit note must link
back to that return for traceability and audit. Without the FK the "Create CCN from SR"
workflow (SR-V1, SR-CCN-2) cannot be implemented.

**How:**
```bash
php artisan make:migration add_sales_return_id_to_customer_credit_notes --no-interaction
```
Migration body:
```php
$table->foreignId('sales_return_id')->nullable()->constrained('sales_returns')->nullOnDelete();
```
Add the inverse relationship on `SalesReturn` model (`hasManyThrough` or `hasMany` via `CustomerCreditNote`).

**Files:**
- New migration file
- `app/Models/SalesReturn.php` — add `customerCreditNotes()` hasMany
- `app/Models/CustomerCreditNote.php` — add `salesReturn()` belongsTo

**Test:** Assert `customer_credit_notes` has column `sales_return_id` after migration.

---

## Tier 1 — Cross-Cutting Fixes

### INFRA-L1: Partner Filter + Date Range Filter on All List Pages

**What:** None of the 8 Sales list pages have a partner filter or a date range filter.
Users cannot find "all invoices for Customer X" or "all quotes this month" without
manually scanning.

**Why:** The purchases side (Tier 1 of Phase 3.1) established this as standard. Sales
pages must match.

**How:**
Add to every `*Table.php` file's `filters()` method:
```php
SelectFilter::make('partner_id')
    ->label('Partner')
    ->relationship('partner', 'name')
    ->searchable()
    ->preload(),

Filter::make('issued_date')
    ->form([
        DatePicker::make('from'),
        DatePicker::make('until'),
    ])
    ->query(function (Builder $query, array $data): Builder {
        return $query
            ->when($data['from'], fn ($q, $date) => $q->whereDate('issued_at', '>=', $date))
            ->when($data['until'], fn ($q, $date) => $q->whereDate('issued_at', '<=', $date));
    }),
```
Use `created_at` when `issued_at` does not exist on the model (AdvancePayments use `received_at`).

**Files:** All 8 `*Table.php` files.

---

### INFRA-L2: Fix NULL-First Sort on `issued_at`

**What:** All 8 tables use `->defaultSort('issued_at', 'desc')`. Draft records have
`issued_at = NULL`. In PostgreSQL, NULLs sort last in ASC but **first in DESC** —
so Draft documents always float to the top and hide confirmed records.

**Why:** The typical user view is "show me my most recent confirmed documents." Drafts
should appear prominently but not bury confirmed records.

**How:**
Change the default sort on each table to use `created_at` (always populated) as the
primary sort column. This ensures consistent ordering regardless of status:
```php
->defaultSort('created_at', 'desc')
```
For tables where showing the most recently *issued* documents is important (CI, CCN, CDN),
add a NULLS LAST sort using a raw expression:
```php
->defaultSort(fn ($query) => $query->orderByRaw('issued_at DESC NULLS LAST'))
```

**Files:** All 8 `*Table.php` files.

---

### INFRA-L3: Add `items_count` Column to All Tables

**What:** No Sales list page shows how many line items a document contains. Users cannot
quickly distinguish a single-line order from a 50-line order.

**Why:** Standard ERP practice. Useful for triage (large orders need more attention) and
for confirming the record was fully entered.

**How:**
```php
TextColumn::make('items_count')
    ->counts('items')
    ->label('Lines')
    ->sortable(),
```
Ensure each model has an `items()` relationship defined (all 8 do — via `*Item` morphs or
dedicated relationship methods).

**Files:** All 8 `*Table.php` files. Verify `items()` relationship exists on each model.

---

### EDIT-GUARD: Add `isEditable()` Guard to 5 Missing Edit Pages

**What:** Three Edit pages already guard against editing confirmed/cancelled records
(`EditDeliveryNote`, `EditSalesReturn`, `EditAdvancePayment`). Five do not:
`EditQuotation`, `EditSalesOrder`, `EditCustomerInvoice`, `EditCustomerCreditNote`,
`EditCustomerDebitNote`.

**Why:** A confirmed invoice is legally binding — no fields may be changed. Without the
guard, a user can navigate directly to `/edit` and modify a confirmed document.

**How:**
Copy the guard pattern from `EditDeliveryNote.php`:
```php
public function mount(int|string $record): void
{
    parent::mount($record);
    if (! $this->record->isEditable()) {
        $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record]));
    }
}
```
Apply to: `EditQuotation`, `EditSalesOrder`, `EditCustomerInvoice`,
`EditCustomerCreditNote`, `EditCustomerDebitNote`.

**Files:**
- `app/Filament/Resources/Quotations/Pages/EditQuotation.php`
- `app/Filament/Resources/SalesOrders/Pages/EditSalesOrder.php`
- `app/Filament/Resources/CustomerInvoices/Pages/EditCustomerInvoice.php`
- `app/Filament/Resources/CustomerCreditNotes/Pages/EditCustomerCreditNote.php`
- `app/Filament/Resources/CustomerDebitNotes/Pages/EditCustomerDebitNote.php`

**Test:** Assert that navigating to the edit URL for a confirmed record redirects to view.

---

### TABLE-EDIT: Guard Edit Table Action with `isEditable()`

**What:** All 8 tables show an "Edit" row action unconditionally. Clicking it on a
confirmed record navigates to the edit page (which now redirects back, but the button
should not appear at all).

**Why:** Showing an action that immediately redirects away is confusing UX. The action
should only be visible when editing is actually allowed.

**How:**
```php
EditAction::make()
    ->visible(fn ($record) => $record->isEditable()),
```

**Files:** All 8 `*Table.php` files.

---

### TABLE-BULK: Guard Bulk Delete to Draft Status Only

**What:** Bulk delete is available on all records regardless of status. Deleting a
confirmed invoice or sales order could corrupt financial data.

**Why:** Confirmed documents have downstream effects (qty_invoiced, OSS accumulations,
stock reservations). They must be cancelled through proper service flows, not deleted.

**How:**
```php
DeleteBulkAction::make()
    ->requiresConfirmation()
    ->deselectRecordsAfterCompletion()
    ->action(function (Collection $records): void {
        $records->each(function ($record): void {
            if ($record->status === \App\Enums\DocumentStatus::Draft ||
                $record->status === \App\Enums\QuotationStatus::Draft) {
                $record->delete();
            }
        });
    }),
```
Use the correct status enum per document type. Only Draft records are deleted; others
are silently skipped (or surface a notification listing skipped records).

**Files:** All 8 `*Table.php` files.

---

### ENUM-FIX: Replace Raw String Status Filters with Enum Values

**What:** Six forms use raw string literals in `whereIn`/`where` status filters instead
of enum `->value` or enum instances:

| Form | Line | Current | Fix |
|------|------|---------|-----|
| `SalesOrderForm` | ~49 | `whereIn('status', ['accepted', 'sent'])` | `QuotationStatus::Accepted`, `::Sent` |
| `CustomerInvoiceForm` | ~52 | `whereIn('status', ['confirmed', 'partially_delivered', 'delivered'])` | `SalesOrderStatus` enum values |
| `CustomerCreditNoteForm` | ~38 | `whereIn('status', ['confirmed', 'paid'])` | `DocumentStatus` enum values |
| `CustomerDebitNoteForm` | ~38 | `whereIn('status', ['confirmed', 'paid'])` | `DocumentStatus` enum values |
| `SalesReturnForm` | ~36 | `where('status', 'confirmed')` | `DeliveryNoteStatus::Confirmed` |
| `AdvancePaymentForm` | ~38 | `whereNotIn('status', ['cancelled', 'invoiced'])` | `SalesOrderStatus` enum values |

`DeliveryNoteForm` is already correct and should be used as reference.

**Why:** If an enum value is renamed, raw strings silently stop matching. Enum references
get a compile-time error.

**How:**
```php
// Before
->options(fn () => SalesOrder::whereIn('status', ['confirmed', 'partially_delivered', 'delivered'])->...)
// After
->options(fn () => SalesOrder::whereIn('status', [
    SalesOrderStatus::Confirmed->value,
    SalesOrderStatus::PartiallyDelivered->value,
    SalesOrderStatus::Delivered->value,
])->...)
```

**Files:**
- `app/Filament/Resources/SalesOrders/SalesOrderForm.php`
- `app/Filament/Resources/CustomerInvoices/CustomerInvoiceForm.php`
- `app/Filament/Resources/CustomerCreditNotes/CustomerCreditNoteForm.php`
- `app/Filament/Resources/CustomerDebitNotes/CustomerDebitNoteForm.php`
- `app/Filament/Resources/SalesReturns/SalesReturnForm.php`
- `app/Filament/Resources/AdvancePayments/AdvancePaymentForm.php`

---

### MONEY-FIX: Replace Hardcoded `->money('EUR')` with Dynamic Currency

**What:** Six tables hardcode `->money('EUR')` on monetary columns:
`QuotationsTable`, `SalesOrdersTable`, `CustomerInvoicesTable`,
`CustomerCreditNotesTable`, `CustomerDebitNotesTable`, `AdvancePaymentsTable`.

**Why:** HMO supports multi-currency documents. A USD-denominated quotation displaying
"€1,000" is factually wrong.

**How:**
```php
TextColumn::make('total_amount')
    ->money(fn ($record) => $record->currency_code ?? 'EUR'),
```
Check the model for the correct currency field name (`currency_code` or `currency`).

**Files:** 6 `*Table.php` files (all except `DeliveryNotesTable` and `SalesReturnsTable`
which have no monetary columns or are already correct).

---

## Tier 2 — Service Layer Hardening

### SO-B1: Auto-set `issued_at` on Draft→Confirmed Transition

**What:** `SalesOrderService::transitionStatus()` does not set `issued_at` when a Sales
Order moves from Draft to Confirmed. The field stays NULL.

**Why:** `issued_at` records the legal issue date. For Sales Orders the confirmation
moment is the issue moment. Leaving it NULL breaks the date range filter (INFRA-L1) and
any downstream date calculations.

**How:**
In `SalesOrderService::transitionStatus()`, when transitioning to `Confirmed`:
```php
if ($newStatus === SalesOrderStatus::Confirmed && $salesOrder->issued_at === null) {
    $salesOrder->issued_at = now();
}
```

**Files:** `app/Services/SalesOrderService.php`

**Test:** Assert `issued_at` is populated after confirming a Draft SO.

---

### CI-2: Over-Invoice Guard in `CustomerInvoiceService::confirm()`

**What:** Nothing prevents a user from creating multiple invoices that together invoice
more quantity than was ordered on the linked Sales Order.

**Why:** Over-invoicing is illegal. Each invoice line's `quantity` plus all previously
confirmed invoice lines for the same SO item must not exceed the SO item's `qty_ordered`.

**How:**
Before confirming, loop through CI lines:
```php
foreach ($invoice->items as $item) {
    $alreadyInvoiced = CustomerInvoiceItem::whereHas('invoice', fn ($q) =>
        $q->where('sales_order_id', $invoice->sales_order_id)
          ->where('status', DocumentStatus::Confirmed)
          ->where('id', '!=', $invoice->id)
    )->where('product_variant_id', $item->product_variant_id)
     ->sum('quantity');

    if ($alreadyInvoiced + $item->quantity > $item->salesOrderItem->qty_ordered) {
        throw new \DomainException("Over-invoice: {$item->productVariant->sku}");
    }
}
```
Surface the exception as a Filament notification (catch in the View action, not as an
unhandled exception).

**Files:** `app/Services/CustomerInvoiceService.php`

**Test:** Assert confirmation fails when invoice qty + existing confirmed qty > ordered qty.

---

### CI-V2: Extract Inline Cancel into `CustomerInvoiceService::cancel()`

**What:** `ViewCustomerInvoice.php` cancels an invoice inline:
```php
$record->status = DocumentStatus::Cancelled;
$record->save();
```
This does NOT reverse `qty_invoiced` on linked Sales Order items or reverse OSS
accumulation.

**Why:** Cancelling a confirmed invoice must undo all its side effects: the SO's
`qty_invoiced` was incremented on confirmation; it must be decremented on cancellation.
If the invoice triggered EU OSS accumulation, that must be reversed too.

**How:**
Add `CustomerInvoiceService::cancel(CustomerInvoice $invoice): void`:
```php
DB::transaction(function () use ($invoice): void {
    // 1. Reverse qty_invoiced on linked SO items
    foreach ($invoice->items as $item) {
        if ($item->salesOrderItem) {
            $item->salesOrderItem->decrement('qty_invoiced', $item->quantity);
        }
    }
    // 2. Reverse OSS accumulation if applicable
    if ($invoice->oss_applicable) {
        $this->euOssService->reverseAccumulation($invoice);
    }
    // 3. Cancel the invoice
    $invoice->update(['status' => DocumentStatus::Cancelled, 'cancelled_at' => now()]);
});
```
Update `ViewCustomerInvoice.php` cancel action to call `$this->service->cancel($record)`.

**Files:**
- `app/Services/CustomerInvoiceService.php`
- `app/Filament/Resources/CustomerInvoices/Pages/ViewCustomerInvoice.php`

**Test:** Assert cancel decrements qty_invoiced on SO items and sets status=Cancelled.

---

### CCN-V2: Add `confirm()` + `cancel()` to `CustomerCreditNoteService`

**What:** `CustomerCreditNoteService` has only `recalculateItemTotals()` and
`recalculateDocumentTotals()`. Both the confirm and cancel view actions are INLINE code
in `ViewCustomerCreditNote.php`.

**Why:** Confirms and cancels of credit notes have side effects (update parent invoice
status, reverse stock if applicable). They belong in the service, not in view page code.

**How:**
Add `confirm(CustomerCreditNote $ccn): void` and `cancel(CustomerCreditNote $ccn): void`
following the pattern established in `CustomerInvoiceService::confirm()`.
- `confirm()`: set status=Confirmed, confirmed_at=now(), update parent invoice's
  remaining balance if applicable, wrap in DB::transaction.
- `cancel()`: set status=Cancelled, cancelled_at=now(), reverse any balance changes.

Update `ViewCustomerCreditNote.php` to call service methods.

**Files:**
- `app/Services/CustomerCreditNoteService.php`
- `app/Filament/Resources/CustomerCreditNotes/Pages/ViewCustomerCreditNote.php`

**Test:** Confirm sets status=Confirmed; cancel sets status=Cancelled.

---

### CDN-V2: Add `confirm()` + `cancel()` to `CustomerDebitNoteService`

**What/Why/How:** Exact mirror of CCN-V2 for debit notes.

**Files:**
- `app/Services/CustomerDebitNoteService.php`
- `app/Filament/Resources/CustomerDebitNotes/Pages/ViewCustomerDebitNote.php`

**Test:** Same as CCN-V2.

---

### AP-V1: Gate `AdvancePaymentService::refund()` Against Confirmed Advance Invoice

**What:** `AdvancePaymentService::refund()` only flips the status to Refunded. It does
not check whether a confirmed Advance Invoice has already been issued for this payment.

**Why:** If an advance invoice was issued and handed to the customer, refunding the
advance without cancelling/crediting the invoice leaves the books in an inconsistent
state (the customer has an invoice for money that was returned).

**How:**
At the start of `refund()`:
```php
if ($advancePayment->advanceInvoice?->status === DocumentStatus::Confirmed) {
    throw new \DomainException(
        'Cannot refund: a confirmed advance invoice exists. Cancel or credit the invoice first.'
    );
}
```
Surface as Filament notification.

**Files:** `app/Services/AdvancePaymentService.php`

**Test:** Assert refund() throws when confirmed advance invoice exists.

---

### AP-V2: Add `AdvancePaymentService::reverseApplication()`

**What:** There is no method to unapply an advance payment from an invoice after it has
been applied. Once applied, `AdvancePaymentApplication` records are permanent.

**Why:** Mistakes happen. An advance may have been applied to the wrong invoice. The
reversal must restore the advance's `remaining_amount` and remove the application record,
within a transaction.

**How:**
```php
public function reverseApplication(AdvancePaymentApplication $application): void
{
    DB::transaction(function () use ($application): void {
        $application->advancePayment->increment('amount_applied', -$application->amount);
        $application->delete();
    });
}
```
Only allow reversal when the linked invoice is still in Draft or Open status (not
Confirmed/Paid — at that point a CCN is required instead).

**Files:** `app/Services/AdvancePaymentService.php`

**Test:** Assert reversal restores remaining_amount and deletes application record.

---

## Tier 3 — Form-Level Fixes

### QUO-C2: Filter Inactive Partners in Quotation Form

**What:** The `partner_id` select in `QuotationForm` filters by `is_active = true` but
does not filter to only customers (`is_customer = true`). Suppliers can be selected.

**Why:** Quotations are issued to customers, not suppliers. Selecting a supplier-only
partner creates an invalid document.

**How:**
```php
->relationship('partner', 'name', fn ($query) =>
    $query->where('is_active', true)->where('is_customer', true)
)
```
Apply the same fix to all 6 forms that have a visible `partner_id` select
(QUO, SO, DN, CI, CCN, CDN — SR and AP inherit partner from parent).

**Files:** All forms that expose a `partner_id` select (check each form file).

---

### QUO-C3: Add `minDate(now())` to `valid_until` Date Picker

**What:** The `valid_until` field in `QuotationForm` has no minimum date constraint.
Users can set a validity date in the past.

**Why:** A quotation valid until yesterday is immediately expired. The UI should prevent
this unless overriding stale quotes (which is a different workflow).

**How:**
```php
DatePicker::make('valid_until')
    ->minDate(now()),
```

**Files:** `app/Filament/Resources/Quotations/QuotationForm.php`

---

### QUO-C5: Lock `pricing_mode` When Items Exist

**What:** Users can change `pricing_mode` (VatExclusive ↔ VatInclusive) after line items
have been entered. This silently changes how all totals are computed without
recalculating.

**Why:** Changing pricing mode mid-entry produces wrong totals unless every line's price
is recalculated. Locking the field once items exist forces the user to clear items first.

**How:**
```php
Select::make('pricing_mode')
    ->disabled(fn (Get $get) => count($get('items') ?? []) > 0)
    ->dehydrated(),
```

**Files:** `app/Filament/Resources/Quotations/QuotationForm.php`

---

### SO-C2: Remove Dead `mount()` Pre-fill from `CreateSalesOrder`

**What:** `CreateSalesOrder::mount()` reads `?quotation_id=` from the URL and pre-fills
form fields. However, the "Convert to SO" action in `ViewQuotation` calls a service that
creates the SO directly — it never navigates to the Create page.

**Why:** Dead code adds maintenance overhead and reader confusion. If the workflow ever
changes, the dead mount() code is likely to be picked up incorrectly.

**How:**
Remove the `mount()` override from `CreateSalesOrder.php`. Verify no other code path
passes `?quotation_id=` to this page.

**Files:** `app/Filament/Resources/SalesOrders/Pages/CreateSalesOrder.php`

---

### SO-C3: Lock `partner_id` When `quotation_id` is Set

**What:** When a Sales Order is linked to a Quotation (`quotation_id` is set), the
`partner_id` field remains editable. The user could change the customer to someone
different from the quotation's customer.

**Why:** An SO must belong to the same customer as its source quotation. Allowing
partner changes breaks the audit trail.

**How:**
```php
Select::make('partner_id')
    ->disabled(fn (Get $get) => filled($get('quotation_id')))
    ->dehydrated(),
```

**Files:** `app/Filament/Resources/SalesOrders/SalesOrderForm.php`

---

### DN-C1: Lock `warehouse_id` When `sales_order_id` is Set

**What:** When a Delivery Note is linked to a Sales Order, the warehouse can be changed
to a different location than the SO's delivery warehouse.

**Why:** The DN must ship from the same warehouse as the SO allocation. Changing it would
issue stock from a different location than reserved.

**How:**
```php
Select::make('warehouse_id')
    ->disabled(fn (Get $get) => filled($get('sales_order_id')))
    ->dehydrated(),
```

**Files:** `app/Filament/Resources/DeliveryNotes/DeliveryNoteForm.php`

---

### DN-C2: Change `delivered_at` Default from `now()` to `null`

**What:** `DeliveryNoteForm` defaults `delivered_at` to `now()`. This pre-fills the
delivery date on creation, before the goods have actually been delivered.

**Why:** `delivered_at` records when goods physically left the warehouse. It should be
empty until delivery occurs, not defaulted to the creation time.

**How:**
Remove `->default(now())` from the `delivered_at` field. Make it nullable with a
placeholder.

**Files:** `app/Filament/Resources/DeliveryNotes/DeliveryNoteForm.php`

---

### CI-C2: Lock `invoice_type` When `sales_order_id` is Set + Include in `fill()`

**What:**
1. When a Customer Invoice is created from a Sales Order (via URL `?sales_order_id=`),
   the `invoice_type` field is not included in `mount()`'s `fill()` array — it stays
   at its default regardless of context.
2. Even if pre-filled, the type field remains editable so the user can change it.

**Why:** An invoice linked to a Sales Order should always be a "Sales Invoice" type. The
type is determined by the creation context, not by the user's choice mid-form.

**How:**
In `CreateCustomerInvoice::mount()`, include `invoice_type` in the fill:
```php
$this->fillForm([
    'sales_order_id' => $salesOrder->id,
    'partner_id' => $salesOrder->partner_id,
    'invoice_type' => InvoiceType::SalesInvoice,
]);
```
In `CustomerInvoiceForm`, disable the type field when `sales_order_id` is set:
```php
Select::make('invoice_type')
    ->disabled(fn (Get $get) => filled($get('sales_order_id')))
    ->dehydrated(),
```

**Files:**
- `app/Filament/Resources/CustomerInvoices/Pages/CreateCustomerInvoice.php`
- `app/Filament/Resources/CustomerInvoices/CustomerInvoiceForm.php`

---

### CCN-F2: Add Required `reason` Textarea to CustomerCreditNote Form

**What:** There is no `reason` field on the Customer Credit Note form. Users cannot
record why the credit is being issued.

**Why:** EU invoicing rules require a reference to the original invoice and the reason
for the credit. The reason field is also needed for audit.

**How:**
Add `reason` column to `customer_credit_notes` table (if not present — check schema)
and add to form:
```php
Textarea::make('reason')
    ->required()
    ->maxLength(500)
    ->columnSpanFull(),
```

**Files:**
- Migration (if column missing)
- `app/Filament/Resources/CustomerCreditNotes/CustomerCreditNoteForm.php`

---

### CCN-F3: Auto-fill Items from Parent Invoice on CCN Create

**What:** When a CCN is created from a Customer Invoice (via `?customer_invoice_id=`),
the items repeater is empty. The user must manually re-enter every line item.

**Why:** The most common CCN scenario is crediting the entire invoice or specific lines.
Pre-filling from the parent invoice eliminates data entry errors and saves time.

**How:**
In `CreateCustomerCreditNote::mount()`, after filling partner/invoice fields:
```php
$items = $invoice->items->map(fn ($item) => [
    'product_variant_id' => $item->product_variant_id,
    'description' => $item->description,
    'quantity' => $item->quantity,
    'unit_price' => $item->unit_price,
    'vat_rate_id' => $item->vat_rate_id,
])->toArray();

$this->fillForm([..., 'items' => $items]);
```

**Files:** `app/Filament/Resources/CustomerCreditNotes/Pages/CreateCustomerCreditNote.php`

---

### CCN-F4: Validate Credit Qty ≤ Invoiced Qty

**What:** No validation prevents entering a credit quantity greater than what was
originally invoiced.

**Why:** You cannot credit more than you invoiced. A credit for 10 units on a 5-unit
invoice is invalid.

**How:**
In `CustomerCreditNoteService` (or as a form validation rule), before saving:
```php
foreach ($ccn->items as $item) {
    $invoicedQty = $ccn->customerInvoice->items
        ->where('product_variant_id', $item->product_variant_id)
        ->sum('quantity');
    if ($item->quantity > $invoicedQty) {
        throw new ValidationException(...);
    }
}
```

**Files:** `app/Services/CustomerCreditNoteService.php`

---

### CDN-F2 / CDN-F3 / CDN-F4: Mirror CCN-F2/F3/F4 for Debit Notes

**What/Why/How:** Exact mirror of CCN-F2, CCN-F3, CCN-F4 for Customer Debit Notes.
Debit notes record additional charges against an invoice — reason is still required,
items should still pre-fill, qty should still be bounded.

**Files:**
- `app/Filament/Resources/CustomerDebitNotes/CustomerDebitNoteForm.php`
- `app/Filament/Resources/CustomerDebitNotes/Pages/CreateCustomerDebitNote.php`
- `app/Services/CustomerDebitNoteService.php`

---

### SR-F1: Auto-fill Items from Parent Delivery Note on SalesReturn Create

**What:** When a Sales Return is created from a Delivery Note (via `?delivery_note_id=`),
the items repeater is empty.

**Why:** Returns are almost always for items that were delivered. Pre-filling from the DN
prevents mistakes and saves entry time.

**How:** Mirror CCN-F3 pattern but source from `DeliveryNote::items`.

**Files:** `app/Filament/Resources/SalesReturns/Pages/CreateSalesReturn.php`

---

### SR-F2: Validate Return Qty ≤ Delivered Qty

**What:** No validation prevents entering a return quantity greater than the delivered
quantity on the linked Delivery Note.

**Why:** Cannot return more than was delivered.

**How:** Mirror CCN-F4 pattern, comparing against `DeliveryNoteItem::quantity`.

**Files:** `app/Services/SalesReturnService.php` (or form validation)

---

### AP-F1: Make `received_at` Required in AdvancePayment Form

**What:** `received_at` is nullable in `AdvancePaymentForm`. An advance payment with no
receipt date has no legal basis date.

**Why:** The receipt date is needed for VAT fiscal period assignment and for reconciliation.

**How:**
```php
DatePicker::make('received_at')
    ->required()
    ->default(now()),
```

**Files:** `app/Filament/Resources/AdvancePayments/AdvancePaymentForm.php`

---

### AP-F2: Make `payment_method` Required

**What:** `payment_method` is nullable. An advance payment with no recorded method has no
audit value.

**Why:** Required for reconciliation with bank statements and fiscal records.

**How:**
```php
Select::make('payment_method')
    ->required(),
```

**Files:** `app/Filament/Resources/AdvancePayments/AdvancePaymentForm.php`

---

### AP-F3: Add Amount Validation (> 0, ≤ SO Remaining Balance)

**What:** The `amount` field has no minimum or maximum constraint. Users can enter 0 or
an amount exceeding the Sales Order's remaining balance.

**Why:** A zero advance is meaningless. An advance exceeding the order total overpays
before delivery.

**How:**
```php
TextInput::make('amount')
    ->numeric()
    ->minValue(0.01)
    ->rules([
        fn (Get $get) => function ($attribute, $value, $fail) use ($get) {
            $soId = $get('sales_order_id');
            if (! $soId) { return; }
            $remaining = SalesOrder::find($soId)?->remainingBalance() ?? PHP_INT_MAX;
            if ($value > $remaining) {
                $fail("Amount cannot exceed the SO remaining balance of {$remaining}.");
            }
        },
    ]),
```
Add `remainingBalance()` method to `SalesOrder` model if not present.

**Files:**
- `app/Filament/Resources/AdvancePayments/AdvancePaymentForm.php`
- `app/Models/SalesOrder.php` (add `remainingBalance()` if missing)

---

## Tier 4 — View Actions + Related Documents

### QUO-L5: Status Badge Column on Quotations Table

**What:** The Quotations list has no status column. Users cannot see at a glance which
quotes are Draft, Sent, Accepted, or Declined.

**How:**
```php
TextColumn::make('status')
    ->badge()
    ->color(fn ($state) => $state->color()),
```

**Files:** `app/Filament/Resources/Quotations/QuotationsTable.php`

---

### QUO-L6: `valid_until` Column with Expired Highlighting

**What:** The Quotations table has no `valid_until` column. Expired quotes are not
visually distinguished.

**How:**
```php
TextColumn::make('valid_until')
    ->date()
    ->color(fn ($state) => $state && $state->isPast() ? 'danger' : null),
```

**Files:** `app/Filament/Resources/Quotations/QuotationsTable.php`

---

### QUO-V1: Guard "Convert to SO" — Hide if SO Already Exists

**What:** The "Convert to SO" action on `ViewQuotation` is visible for ALL Accepted
quotations, including those that already have a Sales Order.

**Why:** Converting twice creates a duplicate SO. The button should only appear when no
SO exists yet.

**How:**
```php
Action::make('convert_to_so')
    ->visible(fn ($record) =>
        $record->status === QuotationStatus::Accepted &&
        ! $record->salesOrders()->exists()
    ),
```

**Files:** `app/Filament/Resources/Quotations/Pages/ViewQuotation.php`

---

### QUO-V2: Show Linked SO in Quotation Related Documents

**What:** `ViewQuotation` related documents panel does not include the Sales Orders
derived from this quotation.

**How:**
In `getRelatedDocuments()`, add:
```php
[
    'label' => 'Sales Orders',
    'records' => $this->record->salesOrders,
    'resource' => SalesOrderResource::class,
],
```

**Files:** `app/Filament/Resources/Quotations/Pages/ViewQuotation.php`

---

### SO-L1: Fulfillment % Column on Sales Orders Table

**What:** The Sales Orders list shows no indication of how much of the order has been
delivered or invoiced.

**Why:** Operations teams need to see at a glance which orders are partially or fully
fulfilled.

**How:**
Add a computed column:
```php
TextColumn::make('fulfillment_pct')
    ->label('Fulfillment')
    ->state(fn ($record) => $record->fulfillmentPercentage() . '%')
    ->sortable(false),
```
Add `fulfillmentPercentage(): int` on `SalesOrder` model (sum of delivered qty / ordered
qty, capped at 100).

**Files:**
- `app/Filament/Resources/SalesOrders/SalesOrdersTable.php`
- `app/Models/SalesOrder.php`

---

### SO-V1: Show Linked Quotation in SalesOrder Related Documents

**What:** `ViewSalesOrder` does not show the Quotation that originated this Sales Order.

**How:**
In `getRelatedDocuments()`, add:
```php
[
    'label' => 'Source Quotation',
    'records' => $this->record->quotation ? [$this->record->quotation] : [],
    'resource' => QuotationResource::class,
],
```

**Files:** `app/Filament/Resources/SalesOrders/Pages/ViewSalesOrder.php`

---

### DN-L1: Add Warehouse Column to DeliveryNotes Table

**What:** The DN list does not show which warehouse goods were shipped from.

**How:**
```php
TextColumn::make('warehouse.name')
    ->label('Warehouse')
    ->sortable(),
```

**Files:** `app/Filament/Resources/DeliveryNotes/DeliveryNotesTable.php`

---

### DN-L2: Add `delivered_at` Column to DeliveryNotes Table

**What:** The DN list does not show the actual delivery date.

**How:**
```php
TextColumn::make('delivered_at')
    ->date()
    ->sortable(),
```

**Files:** `app/Filament/Resources/DeliveryNotes/DeliveryNotesTable.php`

---

### DN-V1: Add "Create Invoice" Button on Confirmed DN View Page

**What:** `ViewDeliveryNote` has no action to create a Customer Invoice from the DN.
The user must navigate away to the CI create page manually.

**Why:** The DN→CI workflow is the most common path in B2B sales. A direct action reduces
friction.

**How:**
Add action to `ViewDeliveryNote.php`:
```php
Action::make('create_invoice')
    ->label('Create Invoice')
    ->icon(Heroicon::DocumentPlus)
    ->visible(fn ($record) => $record->status === DeliveryNoteStatus::Confirmed)
    ->url(fn ($record) => CustomerInvoiceResource::getUrl('create', [
        'sales_order_id' => $record->sales_order_id,
    ])),
```

**Files:** `app/Filament/Resources/DeliveryNotes/Pages/ViewDeliveryNote.php`

---

### CI-L1: Add `due_date` Column with Overdue Highlighting

**What:** The Customer Invoices table has no `due_date` column. Overdue invoices are
not visually flagged.

**How:**
```php
TextColumn::make('due_date')
    ->date()
    ->color(fn ($state, $record) =>
        $state && $state->isPast() && $record->status !== DocumentStatus::Paid
            ? 'danger' : null
    )
    ->sortable(),
```

**Files:** `app/Filament/Resources/CustomerInvoices/CustomerInvoicesTable.php`

---

### CI-1: Show Advance Payment Deductions in CI Total Display

**What:** The Customer Invoice view page does not display advance payments that were
applied against this invoice. The total shown appears to be the gross amount.

**Why:** The customer owes only the net amount after advance deductions. The view must
show: gross total, advance deductions, net payable.

**How:**
In the view page (or after INFRA-V1 in the infolist), add a totals section:
```
Subtotal: €X,XXX
Advance Applied: -€XXX
Net Payable: €X,XXX
```
Source from `AdvancePaymentApplication` records linked to this invoice.

**Files:** `app/Filament/Resources/CustomerInvoices/Pages/ViewCustomerInvoice.php`

---

### CI-V1: Show Applied Advance Payments in CI Related Documents

**What:** `ViewCustomerInvoice` related documents panel does not show the advance
payments that were applied to this invoice.

**How:**
In `getRelatedDocuments()`, add:
```php
[
    'label' => 'Applied Advances',
    'records' => $this->record->appliedAdvancePayments,
    'resource' => AdvancePaymentResource::class,
],
```

**Files:** `app/Filament/Resources/CustomerInvoices/Pages/ViewCustomerInvoice.php`

---

### SR-V1: "Create CCN" Action Must Pass `sales_return_id`

**What:** The "Create CCN" action in `ViewSalesReturn` redirects to CCN create page but
only passes `?customer_invoice_id=`. It does not pass `?sales_return_id=`.

**Why:** After SR-CCN-1 adds the `sales_return_id` column, the CCN must know which return
originated it. Without the parameter, the link is lost.

**Depends on:** SR-CCN-1 (Tier 0 migration).

**How:**
```php
->url(fn ($record) => CustomerCreditNoteResource::getUrl('create', [
    'customer_invoice_id' => $record->customer_invoice_id,
    'sales_return_id' => $record->id,
]))
```

**Files:** `app/Filament/Resources/SalesReturns/Pages/ViewSalesReturn.php`

---

### SR-V2: Show Linked CCN in SalesReturn Related Documents

**What:** `ViewSalesReturn` does not display the Credit Notes generated from this return.

**How:**
In `getRelatedDocuments()`, add:
```php
[
    'label' => 'Credit Notes',
    'records' => $this->record->customerCreditNotes,
    'resource' => CustomerCreditNoteResource::class,
],
```

**Files:** `app/Filament/Resources/SalesReturns/Pages/ViewSalesReturn.php`

---

### SR-CCN-2: Pre-fill `sales_return_id` in `CreateCustomerCreditNote::mount()`

**What:** `CreateCustomerCreditNote::mount()` reads `?customer_invoice_id=` but not
`?sales_return_id=`. The new column (SR-CCN-1) never gets populated.

**Depends on:** SR-CCN-1.

**How:**
```php
if ($salesReturnId = request()->query('sales_return_id')) {
    $fill['sales_return_id'] = $salesReturnId;
}
```

**Files:** `app/Filament/Resources/CustomerCreditNotes/Pages/CreateCustomerCreditNote.php`

---

### AP-T2: Add Status Badge Column to AdvancePayments Table

**What:** The AdvancePayments list has no status column.

**How:**
```php
TextColumn::make('status')
    ->badge()
    ->color(fn ($state) => $state->color()),
```

**Files:** `app/Filament/Resources/AdvancePayments/AdvancePaymentsTable.php`

---

### AP-T3: Add `remaining` Computed Column to AdvancePayments Table

**What:** The AdvancePayments table shows `amount` and `amount_applied` separately but
not the remaining balance (`amount - amount_applied`).

**How:**
```php
TextColumn::make('remaining')
    ->label('Remaining')
    ->state(fn ($record) => $record->amount - $record->amount_applied)
    ->money(fn ($record) => $record->currency_code ?? 'EUR'),
```

**Files:** `app/Filament/Resources/AdvancePayments/AdvancePaymentsTable.php`

---

## Tier 5 — INFRA-V1: Replace Blade Views with Infolists

### Overview

All 8 View pages use a custom Blade template (`view-document-with-items.blade.php` or
`view-document.blade.php`) via `protected string $view = '...'`. This bypasses Filament's
infolist system entirely, making it impossible to use standard Filament components
(actions attached to fields, conditional visibility, etc.) and breaking future upgrade paths.

**Goal:** Remove the `$view` property override on all 8 View pages, implement
`content(Infolist $infolist)` with a proper infolist schema, then delete both Blade
template files.

**Pattern (use Quotation as reference — simplest document):**
```php
// Remove:
protected string $view = 'filament.pages.view-document-with-items';

// Add:
public function content(Infolist $infolist): Infolist
{
    return $infolist
        ->schema([
            Section::make('Document Details')
                ->schema([
                    TextEntry::make('number'),
                    TextEntry::make('partner.name')->label('Customer'),
                    TextEntry::make('issued_at')->date(),
                    TextEntry::make('status')->badge(),
                ]),
            Section::make('Line Items')
                ->schema([
                    RepeatableEntry::make('items')
                        ->schema([
                            TextEntry::make('productVariant.sku'),
                            TextEntry::make('description'),
                            TextEntry::make('quantity'),
                            TextEntry::make('unit_price')->money(...),
                            TextEntry::make('total')->money(...),
                        ]),
                ]),
            Section::make('Totals')
                ->schema([
                    TextEntry::make('subtotal')->money(...),
                    TextEntry::make('vat_amount')->money(...),
                    TextEntry::make('total_amount')->money(...),
                ]),
        ]);
}
```

---

### INFRA-V1-QUO: Replace Blade in ViewQuotation

**Files:** `app/Filament/Resources/Quotations/Pages/ViewQuotation.php`

---

### INFRA-V1-SO: Replace Blade in ViewSalesOrder

**Files:** `app/Filament/Resources/SalesOrders/Pages/ViewSalesOrder.php`

---

### INFRA-V1-DN: Replace Blade in ViewDeliveryNote

**Files:** `app/Filament/Resources/DeliveryNotes/Pages/ViewDeliveryNote.php`

---

### INFRA-V1-CI: Replace Blade in ViewCustomerInvoice

**Files:** `app/Filament/Resources/CustomerInvoices/Pages/ViewCustomerInvoice.php`

Add CI-V3 (tax breakdown) and CI-V4 (payment timeline) as extra sections here.

---

### INFRA-V1-CCN: Replace Blade in ViewCustomerCreditNote

**Files:** `app/Filament/Resources/CustomerCreditNotes/Pages/ViewCustomerCreditNote.php`

Add CCN-V1 proper infolist sections (original invoice reference, credit lines, totals).

---

### INFRA-V1-CDN: Replace Blade in ViewCustomerDebitNote

**Files:** `app/Filament/Resources/CustomerDebitNotes/Pages/ViewCustomerDebitNote.php`

Add CDN-V1 proper infolist sections.

---

### INFRA-V1-SR: Replace Blade in ViewSalesReturn

**Files:** `app/Filament/Resources/SalesReturns/Pages/ViewSalesReturn.php`

---

### INFRA-V1-AP: Replace Blade in ViewAdvancePayment

**Files:** `app/Filament/Resources/AdvancePayments/Pages/ViewAdvancePayment.php`

---

### DELETE-BLADE: Delete Blade Templates

After all 8 View pages have been converted:
- Delete `resources/views/filament/pages/view-document-with-items.blade.php`
- Delete `resources/views/filament/pages/view-document.blade.php`

Run the full test suite first to confirm no page still references these files.

---

## Test Requirements

Each tier requires tests before marking complete:

| Tier | Test Focus |
|------|-----------|
| Tier 0 | Migration: assert `sales_return_id` column exists on `customer_credit_notes` |
| Tier 1 | Cross-cutting: assert filters render, sort works, isEditable() redirects, edit action hidden for confirmed records |
| Tier 2 | Service: unit tests for cancel(), confirm(), refund gate, reverseApplication(), over-invoice guard, issued_at auto-set |
| Tier 3 | Form validation: required fields, disabled fields, qty bounds |
| Tier 4 | Page tests: action visibility (convert_to_so hidden when SO exists), related documents present |
| Tier 5 | View render: infolist renders without error, sections present, no Blade template referenced |

---

## Verification (End of Refactor)

1. `./vendor/bin/sail artisan test --parallel --compact` — all green
2. `vendor/bin/pint --dirty --format agent` — no violations
3. Manual browser: create + view one of each document type end-to-end
4. Check `resources/views/filament/pages/` — both Blade files deleted
5. Update `tasks/phase-3.2-plan.md` — check off `[ ] 3.2.12`
6. Update `docs/STATUS.md` — Phase 3.2.12 complete
