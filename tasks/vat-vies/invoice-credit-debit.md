# Task: Credit & Debit Note VAT Determination

> **Spec:** `tasks/vat-vies/spec.md`
> **Plan:** `tasks/vat-vies/invoice-credit-debit-plan.md`
> **Review:** `review.md` (F-010, F-011, F-021, F-024)
> **Status:** 📋 PLANNED
> **Depends on:** `pdf-rewrite.md`, `domestic-exempt.md`, `blocks.md` all landed
> **Unblocks:** `blocks-credit-debit.md`, `pre-launch.md`

---

## Why this task exists

BG ЗДДС **чл. 115** governs credit / debit notices (известие за кредит / дебит): corrections to an already-confirmed invoice. EU law equivalent is **Art. 219 Directive 2006/112/EC** — documents that amend and specifically refer to the initial invoice are treated as invoices for VAT purposes and carry the same requirements.

Critical property: the amending document **inherits the parent invoice's VAT treatment**. A credit note for a `EuB2bReverseCharge` parent is itself reverse-charge (not a fresh scenario determination at credit-note time). This is the principle of Art. 90 — the taxable basis adjustment mirrors the original.

Currently neither credit notes nor debit notes have VAT scenario logic. This task wires it in.

Review findings this task resolves:
- **F-010** — 5-day issuance rule (чл. 115 ЗДДС) not enforced; add `triggering_event_date` input and soft warning.
- **F-011** — credit / debit note PDF must reference original invoice **number AND date** (Art. 219 / чл. 115 ЗДДС).
- **F-021** — inheritance vs tenant-non-registered blocks: a credit note for a parent issued while tenant was VAT-registered must carry the parent's treatment, even if the tenant later deregistered. Blocks override only applies when parent is also Exempt or note is standalone.
- **F-024** — partner mutation during VIES re-check must be inside the invoice-confirmation transaction; apply same rule to any credit-note VIES interaction (though credit notes don't typically re-run VIES — they inherit).

---

## Scope

### Credit notes
- `vat_scenario` + `vat_scenario_sub_code` + `is_reverse_charge` columns on `customer_credit_notes`
- **Inherit** all three from parent `customer_invoices` at confirmation
- No VIES re-check — parent carries the audit trail
- PDF template based on `pdf-rewrite.md` partials; reference parent invoice number AND date (Art. 219)
- 5-day warning if `issued_at - triggering_event_date > 5 days`
- Items force to the parent's VAT rate (if parent is zero-rated, items zero)
- OSS adjustment (negative delta) via `EuOssService::adjust()` when parent's scenario accumulated OSS

### Debit notes
- Same columns on `customer_debit_notes`
- If parent exists: inherit (same logic as credit notes, but delta is additive / positive)
- If standalone (no parent): run `VatScenario::determine()` fresh — treated as a new invoice for VAT purposes
- Standalone + mixed items + zero-rate-eligible scenario → user must explicitly pick goods/services sub-code in the confirmation modal

### Model immutability
- Credit & debit notes — same `RuntimeException` guard as CustomerInvoice once confirmed (mirrors hotfix Step 7)

---

## Non-scope

- Cancellation documents (different legal construct; not a credit note)
- Partial credit notes with rate re-allocation — scope: credit notes mirror parent line-for-line; partial = user adjusts line quantities/prices but rate stays fixed
- Non-registered tenant blocks on credit / debit — handled in `blocks-credit-debit.md`
- Fresh OSS accumulation for standalone debit notes (deferred — flagged as follow-up)
- Foreign currency / FX nuances on cross-year credits — `pre-launch.md`

---

## Known Changes

### Data model — `customer_credit_notes` and `customer_debit_notes`

Add (both tables):
- `vat_scenario` — enum nullable until confirmed
- `vat_scenario_sub_code` — nullable string
- `is_reverse_charge` — boolean default false
- `triggering_event_date` — date nullable; the event (return, price correction, cancellation) that prompts the note
- VIES audit columns? **No** — inherited from parent. Credit/debit notes do not re-run VIES. If historical audit is needed, reference parent's columns.

### Model immutability

Add booted guards on:
- `app/Models/CustomerCreditNote.php`
- `app/Models/CustomerDebitNote.php`
- Related item models

Same pattern as `hotfix.md` Step 7.

### Service layer — `CustomerCreditNoteService`

Add `confirmWithScenario()`:
- Parent must exist (schema already enforces `customer_invoice_id` NOT NULL for credit notes — verify)
- Parent must be Confirmed — throw if parent is Draft
- Inherit `vat_scenario`, `vat_scenario_sub_code`, `is_reverse_charge` from parent
- If parent scenario requires zero rate → apply 0% to note's items
- Emit 5-day warning if applicable
- OSS adjust (negative delta) if parent's scenario is `EuB2cOverThreshold`
- Status → Confirmed

### Service layer — `CustomerDebitNoteService`

Add `confirmWithScenario()`:
- If parent exists: same as credit-note path
- If standalone (no parent):
  - Run `VatScenario::determine()` fresh with current partner / tenant state
  - If zero-rate scenario AND mixed goods/services → user must pick sub-code in confirmation form
  - NO VIES re-check for the debit-note path in Phase C (deferred); use partner's current `vat_status`
  - **Defer OSS accumulation** for standalone debit notes to a future phase (documented)

### `EuOssService::adjust()`

New method — negative / positive delta accumulation:
```php
public function adjust(CustomerInvoice $parent, float $deltaEur): void
```
- Uses `$parent->issued_at->year` (NOT `now()->year`) per `[review.md#f-006]` + hotfix parent-year lock
- Applies same eligibility checks as `accumulate()` but with a partial amount
- Credit note: negative delta (reduce accumulation)
- Debit note against confirmed parent: positive delta

### PDF templates

Two new templates:
- `resources/views/pdf/customer-credit-note.blade.php`
- `resources/views/pdf/customer-debit-note.blade.php`

Both reuse partials from `pdf-rewrite.md` Step 4 (`_header.blade.php`, `_parties.blade.php`, `_vat-treatment.blade.php`, `_totals-by-rate.blade.php`, `_footer.blade.php`).

**Art. 219 / чл. 115 requirement** — render `Referring to invoice <number>, issued <date>` (and `date of supply` if parent `supplied_at` is distinct).

### Form changes

Credit / debit note forms:
- `triggering_event_date` DatePicker (nullable; defaults to today)
- Visible banner: "This note inherits the parent invoice's VAT treatment (‹scenario›). Current partner / tenant VAT status does not affect this note."
- VAT scenario fields (scenario + sub_code + is_reverse_charge) are read-only / display-only on credit-note form; editable on standalone debit-note form

---

## Tests Required

### Credit notes
- [ ] Feature: confirm credit note — inherits parent's `vat_scenario`, `sub_code`, `is_reverse_charge`
- [ ] Feature: confirm credit note on Draft parent throws
- [ ] Feature: credit note with zero-rate parent → items forced to 0%
- [ ] Feature: credit note against `EuB2cOverThreshold` parent → OSS adjust called with NEGATIVE delta
- [ ] Feature: OSS adjust uses parent's `issued_at->year` not current year (cross-year regression)
- [ ] Feature: credit note PDF renders "Referring to invoice <number>, issued <date>"
- [ ] Feature: credit note PDF inherits "Reverse Charge" wording when parent was reverse-charge
- [ ] Feature: credit note confirmed → immutable (throws on update/delete)
- [ ] Feature: 5-day warning surfaced when `issued_at - triggering_event_date > 5 days`
- [ ] Feature: inherited scenario overrides blocks — tenant now non-registered, parent was Domestic → credit note stays Domestic (per F-021)

### Debit notes
- [ ] Feature: debit note with parent → inherits like credit note
- [ ] Feature: standalone debit note → runs fresh scenario determination
- [ ] Feature: standalone debit note zero-rate + mixed goods/services → sub-code required in confirmation
- [ ] Feature: debit note against `EuB2cOverThreshold` parent → OSS adjust with POSITIVE delta
- [ ] Feature: standalone debit note does NOT trigger fresh OSS accumulation (deferred)
- [ ] Feature: debit note confirmed → immutable

### General
- [ ] Feature: credit / debit note Confirmed rows cannot be edited or deleted
- [ ] Feature: blocks interaction — tenant non-registered + parent Domestic → credit note inherits Domestic, NOT forced to Exempt (F-021)

---

## Refactor Findings

> Filled during / after implementation.

---

## Checklist

- [ ] Investigation complete (current credit/debit note models, services, forms)
- [ ] Plan written (`invoice-credit-debit-plan.md`)
- [ ] Migrations for note columns
- [ ] Immutability guards on both note models
- [ ] Credit note service `confirmWithScenario()`
- [ ] Debit note service `confirmWithScenario()` (both paths)
- [ ] `EuOssService::adjust()` method
- [ ] PDF templates (credit + debit) using shared partials
- [ ] Form changes (triggering_event_date, inheritance banner)
- [ ] Automated tests pass
- [ ] Browser-tested: create credit note against reverse-charge invoice → correct PDF
- [ ] Browser-tested: standalone debit note for a non-EU services → correct sub-code
- [ ] Pint clean
- [ ] Final test run green
