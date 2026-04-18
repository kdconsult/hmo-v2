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

## Corrected design (before any code)

**Why the original plan was wrong in Step 3:**
The original plan said "when `!TenantVatStatus::isRegistered()` → options = [0% only]" for **both** items RMs.
That is the old "force Exempt unconditionally" logic this task was written to undo.

**Legal basis:** Art. 90 Directive 2006/112/EC ("reduced accordingly"), Art. 219 ("treated as invoice"),
ЗДДС чл. 115 ал. 1+4 — corrective documents inherit the parent supply's VAT treatment. The tenant's
current registration status is irrelevant for past supplies.

**Corrected gate for `vat_rate_id` options:**

| Situation | Gate |
|-----------|------|
| Credit note (always has parent) with `requiresVatRateChange()` = true | Restrict to 0% |
| Credit note parent Domestic/B2C (requiresVatRateChange = false) | Full rate list |
| Debit note + parent + `requiresVatRateChange()` = true | Restrict to 0% |
| Debit note + parent + `requiresVatRateChange()` = false | Full rate list |
| Debit note + no parent (standalone) + non-registered tenant | Restrict to 0% |
| Debit note + no parent + registered tenant | Full rate list |
| Any note with null parent that hasn't been set yet | Full rate list (optimistic) |

`TenantVatStatus::isRegistered()` is **NOT** the gate for parent-attached notes.
`parent->vat_scenario->requiresVatRateChange()` mirrors the service predicate — no drift risk.

---

## Step 1 — Service layer: standalone debit + non-registered → Exempt

**No `applyExemptScenario()` helper.** The original plan's helper caused two problems:
1. Dead code on the credit-note service (no standalone path; schema enforces `customer_invoice_id NOT NULL`).
2. Early-return on the debit-note service, bypassing `warnOnLateIssuance()` in the shared tail.

Instead, inline the guard in the standalone branch, then fall through to the shared tail.

**File:** `app/Services/CustomerCreditNoteService.php`

Add a legal comment at the top of `confirmWithScenario()`:

```php
public function confirmWithScenario(CustomerCreditNote $note): void
{
    DB::transaction(function () use ($note): void {
        // Credit notes always have a parent (schema: customer_invoice_id NOT NULL).
        // Inheritance wins unconditionally — tenant's current VAT status does NOT override (F-021,
        // Art. 90 Directive 2006/112/EC: correction mirrors original supply's taxable basis).
        $parent = $note->customerInvoice()->with('partner')->first();
        // ... rest unchanged
    });
}
```

**File:** `app/Services/CustomerDebitNoteService.php`

Refactor `confirmWithScenario()` so the non-registered guard resolves variables, then falls through
to the shared tail (not an early return):

```php
public function confirmWithScenario(CustomerDebitNote $note, ?string $subCode = null): void
{
    DB::transaction(function () use ($note, $subCode): void {
        $parent = $note->customerInvoice()->with('partner')->first();

        if ($parent) {
            // Inheritance path — blocks do NOT override (F-021).
            if ($parent->status !== DocumentStatus::Confirmed) {
                throw new \DomainException(
                    "Cannot confirm debit note against an unconfirmed parent invoice (#{$parent->invoice_number}, status={$parent->status->value})."
                );
            }
            if ($note->currency_code !== $parent->currency_code) {
                throw new \DomainException(
                    "Debit note currency ({$note->currency_code}) must match parent invoice currency ({$parent->currency_code})."
                );
            }

            $scenario    = $parent->vat_scenario;
            $finalSubCode = $parent->vat_scenario_sub_code;
            $isRc        = $parent->is_reverse_charge;
        } else {
            // Standalone path.
            if (! TenantVatStatus::isRegistered()) {
                // Non-registered tenant → force Exempt (ЗДДС чл. 113, ал. 9).
                // Does NOT re-run VatScenario::determine() — tenant lacks VAT registration.
                $scenario    = VatScenario::Exempt;
                $finalSubCode = 'default';
                $isRc        = false;
            } else {
                // Registered tenant — fresh determination.
                $partner     = $note->partner;
                $tenantCountry = TenantVatStatus::country() ?? 'BG';

                $scenario = VatScenario::determine(
                    $partner,
                    $tenantCountry,
                    tenantIsVatRegistered: true,
                );

                if (in_array($scenario, [VatScenario::EuB2bReverseCharge, VatScenario::NonEuExport], true)) {
                    $itemKind = $this->classifyItems($note);
                    if ($itemKind === 'mixed' && $subCode === null) {
                        throw new \DomainException(
                            'Standalone debit note with mixed goods and services requires an explicit sub_code (goods or services).'
                        );
                    }
                    $finalSubCode = $subCode ?? ($itemKind === 'services' ? 'services' : 'goods');
                } else {
                    $finalSubCode = $subCode ?? 'default';
                }

                $isRc = $scenario === VatScenario::EuB2bReverseCharge;
            }
        }

        // Shared tail — runs for ALL paths.
        if ($scenario?->requiresVatRateChange()) {
            $this->applyZeroRateToItems($note, TenantVatStatus::country() ?? 'BG');
        }

        $this->warnOnLateIssuance($note);

        $note->update([
            'vat_scenario'          => $scenario,
            'vat_scenario_sub_code' => $finalSubCode,
            'is_reverse_charge'     => $isRc,
            'status'                => DocumentStatus::Confirmed,
        ]);

        // OSS positive delta for parent-attached debit notes only; standalone deferred.
        if ($parent) {
            $deltaEur = $this->noteToParentEur($note, $parent);
            app(EuOssService::class)->adjust($parent, $deltaEur);
        }
    });
}
```

---

## Step 2 — Form blocks: `pricing_mode` visibility

**Files:**
- `app/Filament/Resources/CustomerCreditNotes/Schemas/CustomerCreditNoteForm.php`
- `app/Filament/Resources/CustomerDebitNotes/Schemas/CustomerDebitNoteForm.php`

```php
use App\Support\TenantVatStatus;

Select::make('pricing_mode')
    ->options([...])
    ->visible(fn (): bool => TenantVatStatus::isRegistered()),
```

This mirrors the invoice-side blocks (`blocks.md` Step 3). When tenant is non-registered, the
pricing mode field is hidden (only VAT-exclusive documents are relevant for Exempt supplies).

---

## Step 3 — Items RM: `vat_rate_id` options gate

**File:** `app/Filament/Resources/CustomerCreditNotes/RelationManagers/CustomerCreditNoteItemsRelationManager.php`

Replace the static `->options(VatRate::active()->...->pluck('name', 'id'))` on `vat_rate_id` Select:

```php
use App\Support\TenantVatStatus;
use App\Models\VatRate;

Select::make('vat_rate_id')
    ->label('VAT Rate')
    ->options(function (): array {
        /** @var \App\Models\CustomerCreditNote $note */
        $note   = $this->getOwnerRecord();
        $parent = $note->customerInvoice;

        if ($parent?->vat_scenario?->requiresVatRateChange()) {
            $country = TenantVatStatus::country() ?? 'BG';
            $zero = VatRate::where('country_code', $country)->where('rate', 0)->first()
                ?? TenantVatStatus::zeroExemptRate();

            return [$zero->id => $zero->name];
        }

        return VatRate::active()->orderBy('rate')->pluck('name', 'id')->toArray();
    })
    ->searchable()
    ->required(),
```

**File:** `app/Filament/Resources/CustomerDebitNotes/RelationManagers/CustomerDebitNoteItemsRelationManager.php`

Same change, but the gate has two conditions (parent-attached OR standalone non-registered):

```php
Select::make('vat_rate_id')
    ->label('VAT Rate')
    ->options(function (): array {
        /** @var \App\Models\CustomerDebitNote $note */
        $note   = $this->getOwnerRecord();
        $parent = $note->customerInvoice;

        $restrict = $parent
            ? (bool) $parent->vat_scenario?->requiresVatRateChange()
            : ! TenantVatStatus::isRegistered();

        if ($restrict) {
            $country = TenantVatStatus::country() ?? 'BG';
            $zero = VatRate::where('country_code', $country)->where('rate', 0)->first()
                ?? TenantVatStatus::zeroExemptRate();

            return [$zero->id => $zero->name];
        }

        return VatRate::active()->orderBy('rate')->pluck('name', 'id')->toArray();
    })
    ->searchable()
    ->required(),
```

**Note on `afterStateUpdated`:** When `customer_invoice_item_id` is selected, the existing
`$set('vat_rate_id', $invoiceItem->vat_rate_id)` copies the invoice item's original rate.
If the parent `requiresVatRateChange()`, the rate dropdown will only show 0% — Filament
validation will reject any other value. The service's `applyZeroRateToItems()` is the
confirmation-time safety net regardless.

---

## Step 4 — Import-from-invoice action (NO CODE CHANGE)

Both RMs were surveyed:
- `CustomerCreditNoteItemsRelationManager.php:195` — `'vat_rate_id' => $invoiceItem->vat_rate_id`
- `CustomerDebitNoteItemsRelationManager.php:206` — `'vat_rate_id' => $invoiceItem->vat_rate_id`

Both copy the rate from the parent invoice item as-is. This is correct: inheritance semantics
mean the parent's rate data flows through untouched. If the parent is Exempt, its items already
carry 0% — correct. If the parent is Domestic, items carry 20% — correct (inheritance).
No override code to remove; no override code to add.

---

## Step 5 — PDF rendering (verify; no code change)

Scenarios to verify after implementation:

1. Credit note against **Domestic parent**, tenant now non-registered → `$note->vat_scenario = Domestic`
   → `requiresVatRateChange()` false → PDF shows standard VAT breakdown at parent's rate. ✅
2. Credit note against **Exempt parent** → `$note->vat_scenario = Exempt, sub_code = 'default'`
   → PDF resolves `чл. 113, ал. 9 ЗДДС`. ✅
3. Credit note against **EuB2bReverseCharge parent** → resolved by parent's sub_code (goods/services). ✅
4. Standalone debit note, non-registered tenant → `$note->vat_scenario = Exempt`
   → PDF resolves `чл. 113, ал. 9 ЗДДС`. ✅

---

## Tests

### Credit note inheritance (F-021)

**File:** `tests/Feature/Invoice/CreditNoteBlocksInheritanceTest.php`

```php
use App\Enums\VatScenario;
use App\Models\{CustomerInvoice, CustomerCreditNote};
use App\Services\CustomerCreditNoteService;

// F-021 inverse: parent Domestic, tenant deregisters → credit note inherits Domestic (NOT forced Exempt)
it('F-021: credit note inherits Domestic even when tenant now non-registered', function () {
    tenancy()->tenant->update(['is_vat_registered' => true, 'vat_number' => 'BG123456789']);
    $parent = CustomerInvoice::factory()->confirmed()->domestic()->create();

    tenancy()->tenant->update(['is_vat_registered' => false, 'vat_number' => null]);

    $note = CustomerCreditNote::factory()->draft()->withParent($parent)->create();
    app(CustomerCreditNoteService::class)->confirmWithScenario($note);

    expect($note->fresh()->vat_scenario)->toBe(VatScenario::Domestic)
        ->and($note->fresh()->vat_scenario_sub_code)->toBeNull();
});

it('credit note inherits Exempt when parent is Exempt', function () {
    tenancy()->tenant->update(['is_vat_registered' => false, 'vat_number' => null]);
    $parent = CustomerInvoice::factory()->confirmed()->exempt()->create();

    $note = CustomerCreditNote::factory()->draft()->withParent($parent)->create();
    app(CustomerCreditNoteService::class)->confirmWithScenario($note);

    expect($note->fresh()->vat_scenario)->toBe(VatScenario::Exempt)
        ->and($note->fresh()->vat_scenario_sub_code)->toBe('default');
});

it('credit note inherits reverse-charge even when tenant now non-registered', function () {
    tenancy()->tenant->update(['is_vat_registered' => true, 'vat_number' => 'BG123456789']);
    $parent = CustomerInvoice::factory()->confirmed()->euB2bReverseCharge()->create([
        'vat_scenario_sub_code' => 'goods',
    ]);

    tenancy()->tenant->update(['is_vat_registered' => false, 'vat_number' => null]);

    $note = CustomerCreditNote::factory()->draft()->withParent($parent)->create();
    app(CustomerCreditNoteService::class)->confirmWithScenario($note);

    expect($note->fresh()->is_reverse_charge)->toBeTrue()
        ->and($note->fresh()->vat_scenario)->toBe(VatScenario::EuB2bReverseCharge);
});

// Items RM positive case: parent requiresVatRateChange → items are forced to 0%
it('credit note items forced to 0% when parent scenario requiresVatRateChange', function () {
    tenancy()->tenant->update(['is_vat_registered' => true, 'vat_number' => 'BG123456789']);
    $parent = CustomerInvoice::factory()->confirmed()->euB2bReverseCharge()->create();

    $note = CustomerCreditNote::factory()->draft()->withParent($parent)->withItems()->create();
    app(CustomerCreditNoteService::class)->confirmWithScenario($note);

    $note->load('items.vatRate');
    foreach ($note->items as $item) {
        expect((float) $item->vatRate->rate)->toBe(0.0);
    }
});
```

### Standalone debit note blocks

**File:** `tests/Feature/Invoice/DebitNoteBlocksStandaloneTest.php`

```php
use App\Enums\VatScenario;
use App\Models\{CustomerInvoice, CustomerDebitNote};
use App\Services\CustomerDebitNoteService;

it('standalone debit note + non-registered tenant → Exempt', function () {
    tenancy()->tenant->update(['is_vat_registered' => false, 'vat_number' => null]);

    $note = CustomerDebitNote::factory()->draft()->standalone()->create();
    app(CustomerDebitNoteService::class)->confirmWithScenario($note);

    expect($note->fresh()->vat_scenario)->toBe(VatScenario::Exempt)
        ->and($note->fresh()->vat_scenario_sub_code)->toBe('default')
        ->and($note->fresh()->is_reverse_charge)->toBeFalse();
});

it('standalone debit note + registered tenant → fresh determine (Domestic)', function () {
    tenancy()->tenant->update(['is_vat_registered' => true, 'vat_number' => 'BG123456789', 'country_code' => 'BG']);

    $partner = \App\Models\Partner::factory()->create(['country_code' => 'BG']);
    $note    = CustomerDebitNote::factory()->draft()->standalone()->create([
        'partner_id' => $partner->id,
    ]);

    app(CustomerDebitNoteService::class)->confirmWithScenario($note);

    expect($note->fresh()->vat_scenario)->toBe(VatScenario::Domestic);
});

it('parent-attached debit note inherits Domestic even when tenant deregistered (F-021)', function () {
    tenancy()->tenant->update(['is_vat_registered' => true, 'vat_number' => 'BG123456789']);
    $parent = CustomerInvoice::factory()->confirmed()->domestic()->create();

    tenancy()->tenant->update(['is_vat_registered' => false, 'vat_number' => null]);

    $note = CustomerDebitNote::factory()->draft()->withParent($parent)->create();
    app(CustomerDebitNoteService::class)->confirmWithScenario($note);

    expect($note->fresh()->vat_scenario)->toBe(VatScenario::Domestic);
});

// Items RM positive case: standalone non-registered → items forced to 0%
it('standalone debit note items forced to 0% when tenant non-registered', function () {
    tenancy()->tenant->update(['is_vat_registered' => false, 'vat_number' => null]);

    $note = CustomerDebitNote::factory()->draft()->standalone()->withItems()->create();
    app(CustomerDebitNoteService::class)->confirmWithScenario($note);

    $note->load('items.vatRate');
    foreach ($note->items as $item) {
        expect((float) $item->vatRate->rate)->toBe(0.0);
    }
});
```

### Factory states to verify before implementation

Grep these before writing tests; add missing states if any are absent:
- `CustomerCreditNote::factory()->draft()->withParent($parent)` — confirmed in `invoice-credit-debit.md`
- `CustomerDebitNote::factory()->draft()->standalone()` — confirm this state exists
- `CustomerDebitNote::factory()->draft()->withParent($parent)` — confirm this state exists
- `CustomerInvoice::factory()->confirmed()->domestic()` — confirm
- `CustomerInvoice::factory()->confirmed()->euB2bReverseCharge()` — confirm
- `CustomerInvoice::factory()->confirmed()->exempt()` — confirm
- `->withItems()` on both note factories — may need to add this state

---

## Gotchas / load-bearing details

1. **Credit notes have NO standalone path.** Schema enforces `customer_invoice_id` NOT NULL. Always
   inherits. Blocks logic never applies to credit notes via the service. The items RM gate is driven
   by the parent's scenario, not tenant registration status.

2. **Debit notes have BOTH paths.** Standalone = non-registered guard first, then fresh determine.
   Parent-attached = inheritance (blocks don't apply).

3. **No `applyExemptScenario()` helper.** The non-registered guard is inlined in the standalone
   branch, then falls through to the shared tail. This ensures `warnOnLateIssuance()` always runs
   (correct: even an Exempt standalone debit note can be issued late).

4. **`TenantVatStatus::isRegistered()`** is the gate for standalone debit notes only.
   `requiresVatRateChange()` is the gate for parent-attached notes (both kinds). Never mix them up.

5. **Import-from-invoice rate copying is correct as-is.** Parent rates flow through untouched.
   Confirmation-time `applyZeroRateToItems()` is the safety net for any residual mismatches.

6. **Regression risk:** F-021 test (`credit note inherits Domestic even when tenant non-registered`)
   must stay in the suite permanently. If someone re-adds a blocks override to credit notes,
   that test will catch it.

---

## Exit Criteria

- [ ] `confirmWithScenario()` on debit note service has non-registered guard inline (no early return)
- [ ] Legal comment added to credit note service's `confirmWithScenario()`
- [ ] `pricing_mode` hidden when non-registered on both forms
- [ ] Items RM `vat_rate_id` options use corrected gate (requiresVatRateChange / standalone guard)
- [ ] Import-from-invoice confirmed as no-op (surveyed; no override code present)
- [ ] All inheritance tests green (F-021)
- [ ] All standalone-debit tests green
- [ ] Full suite green
- [ ] Pint clean
- [ ] Manual: tenant deregisters after issuing Domestic invoice → create credit note → PDF shows 20% VAT
- [ ] Manual: non-registered tenant creates standalone debit note → PDF shows `чл. 113, ал. 9 ЗДДС`
- [ ] Checklist in `blocks-credit-debit.md` ticked
