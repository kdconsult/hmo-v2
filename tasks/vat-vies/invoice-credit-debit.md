# Task: Credit & Debit Note VAT Determination (Area 3.2)

> **Spec:** `tasks/vat-vies/spec.md` — Area 3 (credit/debit note extension)
> **Parent task:** `tasks/vat-vies/invoice.md` — Area 3 (customer invoice) ✅ DONE
> **Status:** Not started

---

## Context

Credit notes and debit notes are outgoing documents that correct a previously confirmed customer invoice. They inherit their VAT treatment from the parent invoice — no independent VIES re-check is needed or appropriate. The VAT scenario is already a legal fact on the parent invoice; the correction document should mirror it.

Currently `CustomerCreditNoteService::confirm()` and `CustomerDebitNoteService::confirm()` simply flip the status to `Confirmed`. They store no VAT scenario, apply no VAT overrides to items, and show no confirmation preview to the user. Additionally, no PDF templates exist for either document type.

---

## Gap Analysis

### DB / Models — missing columns

Both `customer_credit_notes` and `customer_debit_notes` tables are missing:

| Column | Type | Notes |
|--------|------|-------|
| `vat_scenario` | nullable enum (`VatScenario`) | Copied from parent invoice at confirmation; null until confirmed |
| `is_reverse_charge` | boolean, default `false` | Copied from parent invoice |

Neither model has these in `$fillable` or `casts()`.

No VIES audit columns are needed — the parent invoice already carries the full VIES trail.

### Service layer — bare `confirm()` methods

`CustomerCreditNoteService::confirm()`:
- Does not load the parent `CustomerInvoice`
- Does not copy `vat_scenario` or `is_reverse_charge`
- Does not apply VAT rate overrides to items when the inherited scenario requires it (reverse charge / export / exempt)
- Does not skip OSS accumulation (which it should always do — credit notes reverse an existing invoice, OSS was already handled there)

`CustomerDebitNoteService::confirm()`:
- Same gaps

### Confirmation modal — bare `requiresConfirmation()`

`ViewCustomerCreditNote::getHeaderActions()`:
- Confirmation is a raw `->requiresConfirmation()` with no schema
- No scenario badge shown
- No financial preview (subtotal / VAT / total)
- No indication of inherited `vat_scenario` / `is_reverse_charge`

`ViewCustomerDebitNote::getHeaderActions()`:
- Identical gap

### PDF templates — do not exist

- `resources/views/pdf/customer-credit-note.blade.php` — missing entirely
- `resources/views/pdf/customer-debit-note.blade.php` — missing entirely
- No "Print" action on `ViewCustomerCreditNote` or `ViewCustomerDebitNote`
- VAT breakdown and reverse-charge notice need to be conditional (same logic as `customer-invoice.blade.php`)

---

## What Needs Building

### 1. Migrations

Two migrations (one per document type), each adding:
- `vat_scenario` nullable string (or enum)
- `is_reverse_charge` boolean default `false`

### 2. Model updates

`CustomerCreditNote` and `CustomerDebitNote`:
- Add columns to `$fillable`
- Add `vat_scenario => VatScenario::class` and `is_reverse_charge => 'boolean'` to `casts()`

### 3. Service — `confirmWithScenario()`

Add `confirmWithScenario()` to both services (keep existing `confirm()` as a thin wrapper for backward compatibility):

```
Load parent CustomerInvoice (if exists).
If parent exists:
    Copy vat_scenario and is_reverse_charge from parent.
    If parent vat_scenario requires VAT rate change:
        Resolve the correct zero-rate VatRate.
        Update all items to that rate.
        Recalculate item totals.
        Recalculate document totals.
Set status = Confirmed.
Save in transaction.
// Do NOT call EuOssService — OSS was already handled on the parent invoice.
```

When there is no parent invoice (standalone credit/debit notes are allowed by the schema — `customer_invoice_id` is nullable on debit notes):
- Fall back to `VatScenario::determine()` using the document's `partner` and the tenant VAT status, identical to the invoice path but without VIES re-check (treat partner VAT data as-is).

### 4. Confirmation modal

Replace bare `->requiresConfirmation()` in both view pages with a `->schema()` modal showing:
- Inherited scenario badge (or determined scenario for standalone)
- Financial preview: subtotal / VAT / total (with zero-VAT preview when scenario requires rate change)
- `is_reverse_charge` indicator when applicable

### 5. PDF templates

Create `resources/views/pdf/customer-credit-note.blade.php` and `resources/views/pdf/customer-debit-note.blade.php`:
- Based on `customer-invoice.blade.php` structure
- Document title "CREDIT NOTE" / "DEBIT NOTE"
- Reference the parent invoice number
- VAT breakdown section conditional: `@unless ($document->vat_scenario === VatScenario::Exempt || $document->is_reverse_charge)`
- When reverse charge: show "Reverse Charge — VAT accounted for by the recipient"
- When exempt: show legal notice (see Area 4.2)

Add "Print" action to `ViewCustomerCreditNote` and `ViewCustomerDebitNote`.

---

## Tests Required

- [ ] Unit: `confirmWithScenario()` copies `vat_scenario` and `is_reverse_charge` from parent invoice
- [ ] Unit: `confirmWithScenario()` applies zero-rate to items when inherited scenario requires it
- [ ] Unit: standalone debit note (no parent) falls back to `VatScenario::determine()`
- [ ] Feature: Credit note confirmation modal shows inherited scenario badge
- [ ] Feature: OSS accumulation NOT called on credit/debit note confirmation
- [ ] Feature: Print action produces PDF with correct document type label and parent invoice reference
- [ ] Feature: VAT breakdown absent when `vat_scenario = eu_b2b_reverse_charge`; reverse charge notice present

---

## Checklist

- [ ] Investigation complete
- [ ] Migrations written
- [ ] Models updated
- [ ] `confirmWithScenario()` implemented for credit note service
- [ ] `confirmWithScenario()` implemented for debit note service
- [ ] Confirmation modal updated (credit note view)
- [ ] Confirmation modal updated (debit note view)
- [ ] PDF template: credit note
- [ ] PDF template: debit note
- [ ] Print actions added to view pages
- [ ] Automated tests pass
- [ ] Pint clean
- [ ] Final test run
