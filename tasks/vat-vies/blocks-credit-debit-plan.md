# Plan: Non-VAT-Registered Tenant Blocks — Credit & Debit Notes

> **Task:** `tasks/vat-vies/blocks-credit-debit.md`
> **Review:** `review.md` (F-004, F-021)
> **Status:** Ready to implement after `blocks.md` + `invoice-credit-debit.md`

---

## Prerequisites

- [ ] `blocks.md` merged — `TenantVatStatus` helper available; invoice-side blocks working
- [ ] `invoice-credit-debit.md` merged — credit / debit note services have `confirmWithScenario()`
- [ ] `legal-references.md` merged — `VatLegalReference` resolves `exempt/default`

---

## Step 1 — Add `applyExemptScenario()` helper to both services

**File:** `app/Services/CustomerCreditNoteService.php` (add near `applyZeroRateToItems`)

```php
/**
 * Force Exempt treatment on a standalone note (no parent) when tenant is non-registered.
 * NOT called on parent-attached notes — those inherit (see F-021).
 */
protected function applyExemptScenario(\App\Models\CustomerCreditNote $note): void
{
    $this->applyZeroRateToItems($note, \App\Support\TenantVatStatus::country());

    $note->fill([
        'vat_scenario' => \App\Enums\VatScenario::Exempt,
        'vat_scenario_sub_code' => 'default',
        'is_reverse_charge' => false,
        'status' => \App\Enums\DocumentStatus::Confirmed,
    ]);
    $note->save();
}
```

**File:** `app/Services/CustomerDebitNoteService.php` — same helper, typed for `CustomerDebitNote`. **Copy, don't share** (project convention — see `invoice-credit-debit-plan.md` Step 4 note).

---

## Step 2 — Debit note service: standalone + non-registered → Exempt

**File:** `app/Services/CustomerDebitNoteService.php` — inside `confirmWithScenario()`

Add the standalone non-registered guard **before** fresh determination:

```php
public function confirmWithScenario(
    CustomerDebitNote $note,
    ?string $subCode = null,
): void {
    DB::transaction(function () use ($note, $subCode) {
        $parent = $note->customerInvoice;

        if ($parent) {
            // Inheritance path — blocks do NOT override (F-021)
            // ... existing logic from invoice-credit-debit-plan.md Step 5
            return;
        }

        // Standalone — check blocks
        if (!\App\Support\TenantVatStatus::isRegistered()) {
            $this->applyExemptScenario($note);
            return;
        }

        // Otherwise: fresh determine()
        // ... existing standalone logic
    });
}
```

**Credit note service: NO blocks override.** Credit notes always have a parent; inheritance wins.

Add a comment at the top of `CustomerCreditNoteService::confirmWithScenario()`:
```
// NOTE: No tenant-non-registered short-circuit here (F-021).
// A credit note always inherits the parent invoice's VAT treatment,
// even if the tenant has since deregistered.
```

---

## Step 3 — Form + items RM blocks (mirror invoice blocks)

**File:** `app/Filament/Resources/CustomerCreditNotes/Schemas/CustomerCreditNoteForm.php`

```php
use App\Support\TenantVatStatus;

Select::make('pricing_mode')
    ->options([...])
    ->visible(fn () => TenantVatStatus::isRegistered()),
```

Same for `CustomerDebitNoteForm.php`.

**Items RM** — copy the `vat_rate_id` restriction from `blocks-plan.md` Step 4 into:
- `CustomerCreditNoteItemsRelationManager.php`
- `CustomerDebitNoteItemsRelationManager.php`

Logic: when `!TenantVatStatus::isRegistered()` → options = `[$zero->id => $zero->name]`.

---

## Step 4 — Import from invoice action

**File:** wherever the "Import from invoice" action is defined on credit / debit note pages.

Verify the current behavior. If the action currently overrides items' `vat_rate_id` in any way when tenant is non-registered — **remove that override**. Items should be copied from parent as-is. Inheritance semantics drive correctness (F-021).

Only if the **parent is Exempt** does the copied rate happen to be 0% — that's data, not logic. No override code needed.

---

## Step 5 — PDF rendering (verify; no code change)

PDF rendering is handled in `invoice-credit-debit-plan.md` Step 6 via `VatLegalReference::resolve()`. Verify after that task lands:

1. Credit note against **Domestic parent**, tenant non-registered → `$note->vat_scenario = Domestic` → `requiresVatRateChange()` false → PDF shows standard VAT breakdown at the parent's rate. No legal-basis block rendered (Domestic has no lookup row). ✅ correct.
2. Credit note against **Exempt parent** → `$note->vat_scenario = Exempt, sub_code = 'default'` → PDF resolves `чл. 113, ал. 9 ЗДДС`. ✅
3. Credit note against **EuB2bReverseCharge parent** → resolved by parent's sub_code (goods/services). ✅
4. Standalone debit note, tenant non-registered → `$note->vat_scenario = Exempt` → PDF resolves `чл. 113, ал. 9 ЗДДС`. ✅

---

## Tests

**File:** `tests/Feature/CreditNoteBlocksInheritanceTest.php`

```php
use App\Enums\VatScenario;
use App\Models\{CustomerInvoice, CustomerCreditNote};

it('F-021: credit note inherits Domestic even when tenant now non-registered', function () {
    // Parent issued when tenant was registered
    tenancy()->tenant->update(['is_vat_registered' => true, 'vat_number' => 'BG123456789']);
    $parent = CustomerInvoice::factory()->confirmed()->domestic()->create();

    // Tenant deregisters
    tenancy()->tenant->update(['is_vat_registered' => false, 'vat_number' => null]);

    $note = CustomerCreditNote::factory()->draft()->withParent($parent)->create();
    app(CustomerCreditNoteService::class)->confirmWithScenario($note);

    $note->refresh();
    expect($note->vat_scenario)->toBe(VatScenario::Domestic)   // INHERITS, not forced Exempt
        ->and($note->vat_scenario_sub_code)->toBeNull();
});

it('credit note inherits Exempt when parent is Exempt', function () {
    tenancy()->tenant->update(['is_vat_registered' => false, 'vat_number' => null]);
    $parent = CustomerInvoice::factory()->confirmed()->exempt()->create();

    $note = CustomerCreditNote::factory()->draft()->withParent($parent)->create();
    app(CustomerCreditNoteService::class)->confirmWithScenario($note);

    $note->refresh();
    expect($note->vat_scenario)->toBe(VatScenario::Exempt)
        ->and($note->vat_scenario_sub_code)->toBe('default');
});

it('credit note inherits reverse-charge even when tenant now non-registered', function () {
    tenancy()->tenant->update(['is_vat_registered' => true, 'vat_number' => 'BG123456789']);
    $parent = CustomerInvoice::factory()->confirmed()->euB2bReverseCharge()->create([
        'vat_scenario_sub_code' => 'goods',
    ]);

    tenancy()->tenant->update(['is_vat_registered' => false, 'vat_number' => null]);

    $note = CustomerCreditNote::factory()->draft()->withParent($parent)->create();
    app(CustomerCreditNoteService::class)->confirmWithScenario($note);

    $note->refresh();
    expect($note->is_reverse_charge)->toBeTrue()
        ->and($note->vat_scenario)->toBe(VatScenario::EuB2bReverseCharge);
});
```

**File:** `tests/Feature/DebitNoteBlocksStandaloneTest.php`

```php
it('standalone debit note + non-registered tenant → Exempt', function () {
    tenancy()->tenant->update(['is_vat_registered' => false, 'vat_number' => null]);

    $note = CustomerDebitNote::factory()->draft()->standalone()->create();
    app(CustomerDebitNoteService::class)->confirmWithScenario($note);

    $note->refresh();
    expect($note->vat_scenario)->toBe(VatScenario::Exempt)
        ->and($note->vat_scenario_sub_code)->toBe('default');
});

it('standalone debit note + registered tenant → fresh determine', function () {
    tenancy()->tenant->update(['is_vat_registered' => true, 'vat_number' => 'BG123456789', 'country_code' => 'BG']);

    $partner = \App\Models\Partner::factory()->create(['country_code' => 'BG']);
    $note = CustomerDebitNote::factory()->draft()->standalone()->create([
        'partner_id' => $partner->id,
    ]);

    app(CustomerDebitNoteService::class)->confirmWithScenario($note);

    $note->refresh();
    expect($note->vat_scenario)->toBe(VatScenario::Domestic);  // fresh determine
});

it('parent-attached debit note inherits, not blocks', function () {
    tenancy()->tenant->update(['is_vat_registered' => true, 'vat_number' => 'BG123456789']);
    $parent = CustomerInvoice::factory()->confirmed()->domestic()->create();

    tenancy()->tenant->update(['is_vat_registered' => false, 'vat_number' => null]);

    $note = CustomerDebitNote::factory()->draft()->withParent($parent)->create();
    app(CustomerDebitNoteService::class)->confirmWithScenario($note);

    $note->refresh();
    expect($note->vat_scenario)->toBe(VatScenario::Domestic);  // INHERITS
});
```

**File:** `tests/Feature/CreditDebitNoteFormBlocksTest.php`

```php
it('credit note form hides pricing-mode when tenant non-registered', function () {
    tenancy()->tenant->update(['is_vat_registered' => false]);

    livewire(CreateCustomerCreditNote::class)
        ->assertFormFieldHidden('pricing_mode');
});

it('debit note items RM restricts rate options when tenant non-registered', function () {
    tenancy()->tenant->update(['is_vat_registered' => false]);

    $note = CustomerDebitNote::factory()->draft()->create();

    // Inspect items RM — vat_rate_id options should be just the 0% exempt rate
    // (Livewire test helper for RM — see existing patterns in partner/invoice tests)
});
```

---

## Gotchas / load-bearing details

1. **Credit notes have NO standalone path.** Schema enforces `customer_invoice_id` NOT NULL. Always inherits. Blocks logic never applies to credit notes.
2. **Debit notes have BOTH paths.** Standalone = fresh determination (blocks apply). Parent-attached = inheritance (blocks don't apply).
3. **`applyExemptScenario()` is a **helper** for the standalone debit-note path only.** Do not call it from the credit-note service. Do not call it when a parent exists.
4. **`TenantVatStatus::isRegistered()`** is the single source of truth. No sprinkling `CompanySettings::get()` calls.
5. **Import-from-invoice rate copying.** Let the parent's rates flow through untouched. If the parent is itself Exempt, rates are 0% — correct. If the parent is Domestic and tenant has deregistered — rates stay at 20% — correct (inheritance). Do not override.
6. **Regression risk:** if someone later "helpfully" re-adds the blocks override to credit notes, F-021 test must catch it. Keep that test.

---

## Exit Criteria

- [ ] All inheritance tests green (F-021)
- [ ] All standalone-debit tests green
- [ ] All form-block tests green
- [ ] Full suite green
- [ ] Manual: tenant deregisters after issuing a Domestic invoice → create credit note → PDF shows standard 20% VAT (inherited)
- [ ] Manual: non-registered tenant creates standalone debit note → PDF shows `чл. 113, ал. 9 ЗДДС`
- [ ] Pint clean
- [ ] Checklist in `blocks-credit-debit.md` ticked
