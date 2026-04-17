# Task: Non-VAT-Registered Tenant Blocks — Credit & Debit Notes

> **Spec:** `tasks/vat-vies/spec.md`
> **Plan:** `tasks/vat-vies/blocks-credit-debit-plan.md`
> **Review:** `review.md` (F-004, F-021)
> **Status:** 📋 PLANNED
> **Depends on:** `blocks.md` landed (shared `TenantVatStatus` helper), `invoice-credit-debit.md` landed (credit/debit scenario logic)

---

## Why this task exists

`blocks.md` restricts outgoing invoices for a non-VAT-registered tenant. The same restrictions must apply to **credit** and **debit** notes — but with a **critical exception** that the previous plan got wrong:

**A credit note for a parent issued while the tenant WAS VAT-registered must still carry the parent's VAT treatment** (Art. 219 Directive / чл. 115 ЗДДС — the amending document mirrors the original). If the tenant has since deregistered, the credit note cannot retroactively become Exempt — that would falsify the correction.

The old plan forced `Exempt` unconditionally for any non-registered tenant. That is **wrong**. The correct rule:

- **Parent-attached credit note** → inherit from parent, always. Current tenant status is irrelevant.
- **Parent-attached debit note** → inherit from parent, always.
- **Standalone debit note + tenant non-registered** → Exempt (same as a fresh non-registered invoice).
- **Standalone debit note + tenant VAT-registered** → normal fresh determination.

This task implements that rule, plus the UI surface and PDF rendering.

Review findings resolved:
- **F-004** — no stale "Art. 96 ЗДДС" citation; legal basis comes from `VatLegalReference::resolve(tenantCountry, 'exempt', 'default')`
- **F-021** — inheritance wins over blocks-override for parent-attached notes

---

## Scope

### UI blocks (mirror invoice blocks)

For non-VAT-registered tenant:
- Credit / debit note forms: hide pricing-mode selector
- Items RM: restrict `vat_rate_id` options to 0% exempt
- "Import from invoice" action: when parent is Exempt, copied item rates are already 0% (no override needed); when parent is non-Exempt but tenant deregistered → items keep parent's rates (inheritance), NOT overridden to 0%

### Service layer

Credit / debit note services' `confirmWithScenario()` — ordered logic:

```
1. Parent exists?
   → YES: inherit scenario + sub_code + RC from parent (ALWAYS). Stop blocks logic.
   → NO (standalone debit): continue
2. Tenant non-registered?
   → YES: force Exempt (scenario='exempt', sub_code='default', rc=false, skip VIES+OSS)
   → NO: fresh determine()
```

### PDF rendering

Parent-attached Exempt note → shows parent's legal basis (`чл. 113, ал. 9 ЗДДС` if parent was non-registered, or whatever parent cited).

Standalone debit + non-registered tenant → render `чл. 113, ал. 9 ЗДДС`.

### `applyExemptScenario()` helper (previously undefined — defined in plan)

Shared between credit & debit services:

```php
protected function applyExemptScenario(Model $note): void
{
    $this->applyZeroRateToItems($note, \App\Support\TenantVatStatus::country());
    $note->fill([
        'vat_scenario' => VatScenario::Exempt,
        'vat_scenario_sub_code' => 'default',
        'is_reverse_charge' => false,
    ]);
    $note->save();
}
```

Do NOT call this for parent-attached notes — that's the F-021 bug.

---

## Non-scope

- Fresh OSS accumulation for standalone debit notes (deferred from `invoice-credit-debit.md`)
- Per-MS localization of the "не е регистриран по ЗДДС" notice (DE / FR variants — future)
- Reopening a non-registered tenant's historical credit notes for re-classification on re-registration (backlog)

---

## Known Changes

### Forms

- `app/Filament/Resources/CustomerCreditNotes/Schemas/CustomerCreditNoteForm.php` — pricing-mode hidden when tenant non-registered
- `app/Filament/Resources/CustomerDebitNotes/Schemas/CustomerDebitNoteForm.php` — same

### Items RM

- `app/Filament/Resources/CustomerCreditNotes/RelationManagers/CustomerCreditNoteItemsRelationManager.php` — vat_rate_id options gated via TenantVatStatus (mirror `blocks-plan.md` Step 4)
- Same for debit note items RM

### Services

- `CustomerCreditNoteService::confirmWithScenario()` — ALREADY inherits (from `invoice-credit-debit.md`). Explicit comment: "No blocks override here — inheritance wins per F-021."
- `CustomerDebitNoteService::confirmWithScenario()` — add standalone-path guard:
  ```php
  if (!$parent && !TenantVatStatus::isRegistered()) {
      $this->applyExemptScenario($note);
      return;
  }
  ```

### PDF

- Credit note PDF template renders legal reference from note's stored `vat_scenario_sub_code` → `VatLegalReference::resolve()`. No special-casing — the inherited scenario drives rendering.
- Standalone debit note in Exempt scenario renders the `чл. 113, ал. 9 ЗДДС` notice via same path.

### Import action (copy from invoice)

When credit / debit note is created from an existing invoice (action: "Import from invoice"):
- Items are copied with their original `vat_rate_id`
- **If tenant is now non-registered AND the parent is the Exempt invoice itself** → items already at 0% (no rewrite)
- **If parent is a normal-VAT invoice** → items keep their original rates (inheritance — F-021)

No "Import from invoice" rate override needed. The old plan's override requirement was based on the faulty "force Exempt unconditionally" logic.

---

## Tests Required

- [ ] Feature: parent-attached credit note against Domestic parent — tenant non-registered at note time — note remains Domestic (inherits)
- [ ] Feature: parent-attached credit note against Exempt parent — note is Exempt (inherits)
- [ ] Feature: parent-attached debit note — same inheritance rules
- [ ] Feature: standalone debit note + tenant non-registered → Exempt
- [ ] Feature: standalone debit note + tenant VAT-registered → fresh determination works normally
- [ ] Feature: credit / debit note form hides pricing-mode for non-registered tenant
- [ ] Feature: credit / debit note items RM restricts VAT rate when tenant non-registered
- [ ] Feature: Import-from-invoice copies rates as-is (no Exempt override)
- [ ] Feature: credit note PDF against Domestic parent (non-registered tenant) → renders normal Domestic treatment, NOT exempt notice
- [ ] Feature: standalone debit note PDF (non-registered tenant) → renders `чл. 113, ал. 9 ЗДДС`

---

## Refactor Findings

> Filled during / after implementation.

---

## Checklist

- [ ] Investigation complete
- [ ] Plan written (`blocks-credit-debit-plan.md`)
- [ ] Form blocks landed for both note types
- [ ] Items RM restrictions landed
- [ ] `applyExemptScenario()` helper defined + used only for standalone non-registered path
- [ ] Inheritance test passes (F-021)
- [ ] PDF renders correctly in all four cases (parent-attached × Exempt/Non-Exempt, standalone × non-registered/registered)
- [ ] Browser-tested end-to-end
- [ ] Pint clean
- [ ] Final test run green
