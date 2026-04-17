# Task: Non-VAT-Registered Tenant Blocks — Credit & Debit Notes (Area 4.2)

> **Spec:** `tasks/vat-vies/spec.md` — Area 4 (credit/debit note extension)
> **Parent task:** `tasks/vat-vies/blocks.md` — Area 4 (customer invoice) — not yet implemented
> **Sibling task:** `tasks/vat-vies/invoice-credit-debit.md` — Area 3.2 (must be done first — PDF templates needed here)
> **Status:** Not started — blocked by Area 3.2 for PDF work; form/service work can proceed independently

---

## Dependency Note

The PDF changes in this task require the templates created in Area 3.2 (`invoice-credit-debit.md`). The form and service changes are independent and can be done in parallel or before 3.2.

---

## Context

When `is_vat_registered = false`, the same master-switch behaviour defined for customer invoices (Area 4 / `blocks.md`) must apply to credit notes and debit notes. Currently neither document type checks `is_vat_registered` anywhere — in forms, item relation managers, or service confirmation.

---

## Gap Analysis

### `CustomerCreditNoteForm` — `pricing_mode` not hidden

`pricing_mode` is a visible, editable `Select` in `CustomerCreditNoteForm`. It is populated from the parent invoice on selection, but it remains visible even when the tenant is not VAT registered. For non-registered tenants the field is irrelevant (all items are 0%) and should be hidden.

### `CustomerDebitNoteForm` — same

Identical gap in `CustomerDebitNoteForm`.

### `CustomerCreditNoteItemsRelationManager` — VAT rate not restricted

The `vat_rate_id` Select in the credit note items form uses `VatRate::active()->orderBy('rate')->pluck('name', 'id')` — all active rates. When tenant is non-registered, it must be restricted to only the 0% exempt rate.

The "Import from Invoice" action copies `vat_rate_id` directly from the parent invoice item. If the parent invoice was confirmed under a non-exempt scenario but the tenant has since deregistered, the imported rate would be wrong. The import action must override to the exempt rate when `is_vat_registered = false`.

### `CustomerDebitNoteItemsRelationManager` — same

Identical gap. The same override requirement applies to the "Import from Invoice" action.

### `CustomerCreditNoteService::confirmWithScenario()` (to be added in 3.2)

When `confirmWithScenario()` is written in Area 3.2, it must check `is_vat_registered` before inheriting from the parent invoice. If the tenant is non-registered:
- Force `vat_scenario = VatScenario::Exempt`
- Force `is_reverse_charge = false`
- Apply the 0% exempt rate to all items regardless of what the parent invoice had

This ensures a non-registered tenant cannot accidentally issue a credit note carrying the parent invoice's reverse-charge or OSS scenario.

### `CustomerDebitNoteService::confirmWithScenario()` — same

Identical requirement.

### PDF templates (dependent on Area 3.2)

Once `pdf/customer-credit-note.blade.php` and `pdf/customer-debit-note.blade.php` exist, they need the same exempt-case handling as the customer invoice PDF:
- VAT breakdown section absent when `vat_scenario === VatScenario::Exempt`
- Legal notice rendered: *"Not subject to VAT — Art. 96 ЗДДС"* (exact article to be confirmed at implementation — see `blocks.md` open question)

---

## Known Changes

### `CustomerCreditNoteForm`

- `pricing_mode` Select: add `->hidden(fn (): bool => ! (bool) tenancy()->tenant?->is_vat_registered)` (or `->visible()` inverse)
- Mirror the same approach already used in `CustomerInvoiceForm`

### `CustomerDebitNoteForm`

- Same change to `pricing_mode`

### `CustomerCreditNoteItemsRelationManager`

- `vat_rate_id` Select options: when non-registered, restrict to the single 0% exempt rate using `VatRate::where('type', 'zero')->where('country_code', $tenantCountry)->first()` (or `firstOrCreate` pattern from `CustomerInvoiceService::resolveZeroVatRate()`)
- "Import from Invoice" action: override `vat_rate_id` to the exempt rate when non-registered

### `CustomerDebitNoteItemsRelationManager`

- Same two changes

### `CustomerCreditNoteService::confirmWithScenario()`

At the top, before parent-invoice inheritance:
```
$tenantIsVatRegistered = (bool) tenancy()->tenant?->is_vat_registered;
if (! $tenantIsVatRegistered) {
    $this->applyExemptScenario($ccn);  // sets scenario, rate, recalculates
    $ccn->status = DocumentStatus::Confirmed;
    $ccn->save();
    return;
}
// ... normal inheritance path
```

### `CustomerDebitNoteService::confirmWithScenario()`

Same pattern.

### PDF templates (Area 3.2 dependency)

In both new PDF blade templates:
```blade
@unless ($document->vat_scenario?->value === 'exempt')
    {{-- VAT breakdown rows --}}
@else
    <tr>
        <td colspan="2" class="legal-notice">
            Not subject to VAT — Art. 96 ЗДДС
        </td>
    </tr>
@endunless
```

---

## Tests Required

- [ ] Feature: Credit note form — `pricing_mode` hidden when tenant non-registered
- [ ] Feature: Debit note form — `pricing_mode` hidden when tenant non-registered
- [ ] Feature: Credit note item form — only 0% rate selectable when non-registered
- [ ] Feature: Debit note item form — only 0% rate selectable when non-registered
- [ ] Feature: Credit note "Import from Invoice" — overrides to 0% rate when non-registered
- [ ] Feature: Debit note "Import from Invoice" — overrides to 0% rate when non-registered
- [ ] Feature: Credit note confirmation — `vat_scenario = exempt`, `is_reverse_charge = false` stored when non-registered (regardless of parent invoice scenario)
- [ ] Feature: Debit note confirmation — same
- [ ] Feature: Credit note PDF — no VAT breakdown, legal notice present when exempt
- [ ] Feature: Debit note PDF — same

---

## Checklist

- [ ] `CustomerCreditNoteForm` — `pricing_mode` hidden when non-registered
- [ ] `CustomerDebitNoteForm` — same
- [ ] `CustomerCreditNoteItemsRelationManager` — VAT rate restricted
- [ ] `CustomerCreditNoteItemsRelationManager` — import action overrides rate
- [ ] `CustomerDebitNoteItemsRelationManager` — VAT rate restricted
- [ ] `CustomerDebitNoteItemsRelationManager` — import action overrides rate
- [ ] `CustomerCreditNoteService::confirmWithScenario()` — exempt short-circuit
- [ ] `CustomerDebitNoteService::confirmWithScenario()` — exempt short-circuit
- [ ] PDF templates updated (requires Area 3.2)
- [ ] Automated tests pass
- [ ] Pint clean
- [ ] Final test run
