# Plan: Credit & Debit Note VAT Determination

> **Task:** `tasks/vat-vies/invoice-credit-debit.md`
> **Review:** `review.md` (F-010, F-011, F-021, F-024)
> **Status:** Ready to implement after `pdf-rewrite.md`, `domestic-exempt.md`, `blocks.md`

---

## Prerequisites

- [ ] `pdf-rewrite.md` merged (shared Blade partials in `resources/views/pdf/partials/`)
- [ ] `domestic-exempt.md` merged (`vat_scenario_sub_code` exists on invoices)
- [ ] `blocks.md` merged (`TenantVatStatus` helper available)
- [ ] `hotfix.md` merged (immutability pattern for reference)

---

## Step 1 — Migrations for note VAT columns

**Files:**
- `database/migrations/tenant/{timestamp}_add_vat_columns_to_customer_credit_notes.php`
- `database/migrations/tenant/{timestamp}_add_vat_columns_to_customer_debit_notes.php`

Template:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_credit_notes', function (Blueprint $table) {
            $table->string('vat_scenario')->nullable()->index()->after('status');
            $table->string('vat_scenario_sub_code')->nullable()->after('vat_scenario');
            $table->boolean('is_reverse_charge')->default(false)->after('vat_scenario_sub_code');
            $table->date('triggering_event_date')->nullable()->after('issued_at');
        });
    }

    public function down(): void
    {
        Schema::table('customer_credit_notes', function (Blueprint $table) {
            $table->dropColumn(['vat_scenario', 'vat_scenario_sub_code', 'is_reverse_charge', 'triggering_event_date']);
        });
    }
};
```

Mirror for `customer_debit_notes`.

Add casts on both models:
```php
protected $casts = [
    'vat_scenario' => VatScenario::class,
    'is_reverse_charge' => 'boolean',
    'triggering_event_date' => 'date',
    'issued_at' => 'date',
    'supplied_at' => 'date',  // if applicable
];
```

---

## Step 2 — Immutability guards on both note models

**Files:** `app/Models/CustomerCreditNote.php`, `app/Models/CustomerDebitNote.php`

Mirror `hotfix-plan.md` Step 7:

```php
protected static function booted(): void
{
    static::updating(function (self $note): void {
        if ($note->getOriginal('status') === DocumentStatus::Confirmed->value) {
            throw new \RuntimeException(
                "Confirmed {$note::documentType()} are immutable. Document #{$note->document_number}."
            );
        }
    });

    static::deleting(function (self $note): void {
        if ($note->status === DocumentStatus::Confirmed) {
            throw new \RuntimeException(
                "Confirmed {$note::documentType()} cannot be deleted."
            );
        }
    });
}

public static function documentType(): string
{
    return 'credit notes';  // or 'debit notes' in the respective model
}
```

Same for their item models.

---

## Step 3 — `EuOssService::adjust()`

**File:** `app/Services/EuOssService.php`

Add:

```php
/**
 * Adjust the OSS accumulation for a parent invoice by the given delta.
 * Negative for credit notes, positive for debit notes.
 *
 * Uses the PARENT invoice's year for accumulation key (not now()->year).
 */
public function adjust(CustomerInvoice $parent, float $deltaEur): void
{
    if ($parent->vat_scenario !== VatScenario::EuB2cOverThreshold
        && $parent->vat_scenario !== VatScenario::EuB2cUnderThreshold) {
        return;  // no OSS tracking for this scenario
    }

    if (!$this->shouldApplyOss($parent)) {
        return;  // same eligibility as accumulate()
    }

    $year = (int) $parent->issued_at?->year;
    if (!$year) {
        throw new \DomainException('Cannot adjust OSS: parent invoice has no issued_at.');
    }

    EuOssAccumulation::query()
        ->where('year', $year)
        ->increment('amount_eur', $deltaEur);
        // If increment with negative delta isn't supported on your DB driver,
        // use a raw update: DB::update('UPDATE eu_oss_accumulations SET amount_eur = amount_eur + ? WHERE year = ?', [$deltaEur, $year]);
}
```

Reuse `shouldApplyOss()` if it's already extracted; if it's inlined in `accumulate()`, extract it as a protected method first (small refactor, documented in the refactor-findings of the invoice task).

---

## Step 4 — `CustomerCreditNoteService::confirmWithScenario()`

**File:** `app/Services/CustomerCreditNoteService.php`

```php
use App\Enums\DocumentStatus;
use App\Enums\VatScenario;
use App\Models\CustomerCreditNote;
use App\Services\EuOssService;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

public function confirmWithScenario(CustomerCreditNote $note): void
{
    DB::transaction(function () use ($note) {
        $parent = $note->customerInvoice;

        if (!$parent) {
            throw new \DomainException('Credit note has no parent invoice.');
        }

        if ($parent->status !== DocumentStatus::Confirmed) {
            throw new \DomainException(
                'Cannot confirm a credit note against an unconfirmed parent invoice. ' .
                "Parent #{$parent->invoice_number} is {$parent->status->value}."
            );
        }

        // Inheritance — parent wins, NOT current tenant state.
        $scenario = $parent->vat_scenario;
        $subCode = $parent->vat_scenario_sub_code;
        $isRc = $parent->is_reverse_charge;

        // Apply zero rate to items if scenario requires
        if ($scenario->requiresVatRateChange()) {
            $this->applyZeroRateToItems($note, $parent->tenant_country ?? tenancy()->tenant->country_code);
        }

        // 5-day warning
        $this->warnOnLateIssuance($note);

        $note->update([
            'vat_scenario' => $scenario,
            'vat_scenario_sub_code' => $subCode,
            'is_reverse_charge' => $isRc,
            'status' => DocumentStatus::Confirmed,
        ]);

        // OSS adjustment (negative delta)
        if (in_array($scenario, [VatScenario::EuB2cOverThreshold, VatScenario::EuB2cUnderThreshold], true)) {
            $deltaEur = -1.0 * $this->convertTotalToEur($note);
            app(EuOssService::class)->adjust($parent, $deltaEur);
        }
    });
}

protected function warnOnLateIssuance(CustomerCreditNote $note): void
{
    $trigger = $note->triggering_event_date ?? $note->issued_at;
    if ($trigger && $note->issued_at->diffInDays($trigger) > 5) {
        Notification::make()
            ->title('Late credit note')
            ->body('Credit notes must be issued within 5 days of the triggering event (чл. 115 ЗДДС).')
            ->warning()
            ->send();
    }
}

protected function applyZeroRateToItems(CustomerCreditNote $note, string $tenantCountry): void
{
    $zero = \App\Support\TenantVatStatus::zeroExemptRate();
    foreach ($note->items as $item) {
        $item->update(['vat_rate_id' => $zero->id]);
    }
    // Recalculate totals...
}

protected function convertTotalToEur(CustomerCreditNote $note): float
{
    // Same logic as CustomerInvoiceService's equivalent — reuse if accessible.
    return (float) $note->total / ($note->exchange_rate ?: 1);
}
```

**Note on copy-not-share:** Phase C original design explicitly kept these helpers **copied** (not shared) between CreditNoteService and DebitNoteService to avoid coupling. Follow that pattern.

---

## Step 5 — `CustomerDebitNoteService::confirmWithScenario()`

**File:** `app/Services/CustomerDebitNoteService.php`

Dual-path logic:

```php
public function confirmWithScenario(
    CustomerDebitNote $note,
    ?string $subCode = null,  // optional user-picked sub-code for standalone mixed-items case
): void {
    DB::transaction(function () use ($note, $subCode) {
        $parent = $note->customerInvoice;

        if ($parent) {
            if ($parent->status !== DocumentStatus::Confirmed) {
                throw new \DomainException(
                    "Cannot confirm debit note against an unconfirmed parent invoice."
                );
            }

            $scenario = $parent->vat_scenario;
            $finalSubCode = $parent->vat_scenario_sub_code;
            $isRc = $parent->is_reverse_charge;
        } else {
            // Standalone — fresh determination
            $scenario = VatScenario::determine(
                $note->partner,
                tenancy()->tenant->country_code,
                tenantIsVatRegistered: \App\Support\TenantVatStatus::isRegistered(),
            );

            if ($scenario === VatScenario::EuB2bReverseCharge || $scenario === VatScenario::NonEuExport) {
                // Mixed-items — require user pick
                $itemKind = $this->classifyItems($note);
                if ($itemKind === 'mixed' && empty($subCode)) {
                    throw new \DomainException(
                        'Standalone debit note with mixed goods/services requires explicit sub_code.'
                    );
                }
                $finalSubCode = $subCode ?? ($itemKind === 'services' ? 'services' : 'goods');
            } else {
                $finalSubCode = 'default';
            }

            $isRc = $scenario === VatScenario::EuB2bReverseCharge;
        }

        if ($scenario->requiresVatRateChange()) {
            $this->applyZeroRateToItems($note, tenancy()->tenant->country_code);
        }

        $this->warnOnLateIssuance($note);

        $note->update([
            'vat_scenario' => $scenario,
            'vat_scenario_sub_code' => $finalSubCode,
            'is_reverse_charge' => $isRc,
            'status' => DocumentStatus::Confirmed,
        ]);

        // OSS adjustment (positive delta) — only for parent-attached debit notes
        if ($parent && in_array($scenario, [VatScenario::EuB2cOverThreshold, VatScenario::EuB2cUnderThreshold], true)) {
            $deltaEur = $this->convertTotalToEur($note);
            app(EuOssService::class)->adjust($parent, $deltaEur);
        }
        // Standalone debit notes do NOT accumulate fresh OSS — deferred.
    });
}

protected function classifyItems(CustomerDebitNote $note): string
{
    $kinds = $note->items->map(fn ($i) => $i->productVariant?->product?->type)->unique()->filter();
    if ($kinds->count() === 1) {
        return match ($kinds->first()) {
            'service' => 'services',
            default => 'goods',
        };
    }
    return 'mixed';
}
```

---

## Step 6 — PDF templates

**Files:**
- `resources/views/pdf/customer-credit-note.blade.php`
- `resources/views/pdf/customer-debit-note.blade.php`

Structure (credit note example):

```blade
@php
    use App\Models\VatLegalReference;

    $locale = $note->renderLocale();
    app()->setLocale($locale);

    $tenant = tenancy()->tenant;
    $parent = $note->customerInvoice;

    // Legal reference
    $legalRef = null;
    if ($note->vat_scenario !== \App\Enums\VatScenario::Domestic) {
        try {
            $legalRef = VatLegalReference::resolve(
                $tenant->country_code,
                $note->vat_scenario->value,
                $note->vat_scenario_sub_code ?? 'default'
            );
        } catch (\DomainException) {}
    }
@endphp

<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="UTF-8">
    <style>{{-- reuse invoice styles --}}</style>
</head>
<body>
<div class="page">

    @include('pdf.partials._header', [
        'document_title' => __('invoice-pdf.credit_note_heading'),
        'document_number' => $note->document_number,
        'issued_at' => $note->issued_at,
        'supplied_at' => $note->supplied_at ?? null,
    ])

    @include('pdf.partials._parties', ['supplier' => $tenant, 'customer' => $note->partner])

    {{-- Parent reference (Art. 219 / чл. 115) --}}
    <div class="meta-box">
        <table class="meta">
            <tr>
                <td class="meta-label">{{ __('invoice-pdf.referring_to_invoice') }}:</td>
                <td class="meta-value" colspan="3">
                    #{{ $parent->invoice_number }},
                    {{ __('invoice-pdf.issued') }} {{ $parent->issued_at?->format('d.m.Y') }}
                    @if($parent->supplied_at && !$parent->supplied_at->equalTo($parent->issued_at))
                        ({{ __('invoice-pdf.date_of_supply') }} {{ $parent->supplied_at->format('d.m.Y') }})
                    @endif
                </td>
            </tr>
        </table>
    </div>

    @if($note->is_reverse_charge || $legalRef)
        @include('pdf.partials._vat-treatment', [
            'is_reverse_charge' => $note->is_reverse_charge,
            'vies_request_id' => $parent->vies_request_id,  // inherited from parent for audit
            'vies_checked_at' => $parent->vies_checked_at,
            'legal_ref' => $legalRef,
        ])
    @endif

    {{-- Items, totals — same partials as invoice --}}
    @include('pdf.partials._items', ['items' => $note->items])
    @include('pdf.partials._totals-by-rate', ['document' => $note])

    @include('pdf.partials._footer')

</div>
</body>
</html>
```

Add translation keys:
- `invoice-pdf.credit_note_heading` — BG: "КРЕДИТНО ИЗВЕСТИЕ"; EN: "CREDIT NOTE"
- `invoice-pdf.debit_note_heading` — BG: "ДЕБИТНО ИЗВЕСТИЕ"; EN: "DEBIT NOTE"
- `invoice-pdf.referring_to_invoice` — BG: "Към фактура"; EN: "Referring to invoice"
- `invoice-pdf.issued` — BG: "издадена"; EN: "issued"

Debit note: clone the credit-note Blade, change heading key to `debit_note_heading`.

---

## Step 7 — Forms

**Files:**
- `app/Filament/Resources/CustomerCreditNotes/Schemas/CustomerCreditNoteForm.php`
- `app/Filament/Resources/CustomerDebitNotes/Schemas/CustomerDebitNoteForm.php`

Add fields:

```php
DatePicker::make('triggering_event_date')
    ->label('Triggering event date')
    ->helperText('When the correction event occurred. Defaults to today if left blank.')
    ->nullable()
    ->default(now()),

// Display-only scenario for credit notes
Placeholder::make('vat_scenario_inherited')
    ->label('VAT treatment')
    ->content(fn (?Model $record) => $record?->customerInvoice
        ? "Inherits from parent invoice #{$record->customerInvoice->invoice_number}: "
          . $record->customerInvoice->vat_scenario?->description()
        : '—'),

// Banner
Fieldset::make('Inheritance notice')
    ->schema([
        Placeholder::make('banner')
            ->content('This note inherits the parent invoice\'s VAT treatment. '
                . 'Current partner / tenant VAT status does not affect this note.')
            ->columnSpanFull(),
    ])
    ->visible(fn (?Model $record) => $record?->customer_invoice_id !== null),
```

For **standalone debit notes** (no parent), surface the scenario preview AND the goods/services radio when applicable — similar to the invoice form's pattern.

---

## Tests

**File:** `tests/Feature/CreditNoteConfirmationTest.php`

```php
it('inherits scenario from parent invoice', function () {
    $parent = CustomerInvoice::factory()->confirmed()->euB2bReverseCharge()->create([
        'vat_scenario_sub_code' => 'goods',
    ]);
    $note = CustomerCreditNote::factory()->draft()->withParent($parent)->create();

    app(CustomerCreditNoteService::class)->confirmWithScenario($note);

    $note->refresh();
    expect($note->vat_scenario)->toBe(VatScenario::EuB2bReverseCharge)
        ->and($note->vat_scenario_sub_code)->toBe('goods')
        ->and($note->is_reverse_charge)->toBeTrue();
});

it('throws when parent is draft', function () {
    $parent = CustomerInvoice::factory()->draft()->create();
    $note = CustomerCreditNote::factory()->draft()->withParent($parent)->create();

    expect(fn () => app(CustomerCreditNoteService::class)->confirmWithScenario($note))
        ->toThrow(\DomainException::class, 'unconfirmed parent');
});

it('applies negative OSS delta for EuB2cOverThreshold parent', function () {
    $parent = CustomerInvoice::factory()->confirmed()->euB2cOverThreshold()->create([
        'issued_at' => '2025-06-15',
        'total' => 500,
    ]);
    $note = CustomerCreditNote::factory()->draft()->withParent($parent)->create([
        'total' => 100,
    ]);

    $oss = EuOssAccumulation::forYear(2025)->first();
    $before = (float) $oss->amount_eur;

    app(CustomerCreditNoteService::class)->confirmWithScenario($note);

    $oss->refresh();
    expect((float) $oss->amount_eur)->toBe($before - $note->total);
});

it('inherits Domestic scenario even when tenant later deregistered (F-021)', function () {
    $parent = CustomerInvoice::factory()->confirmed()->domestic()->create();
    tenancy()->tenant->update(['is_vat_registered' => false, 'vat_number' => null]);

    $note = CustomerCreditNote::factory()->draft()->withParent($parent)->create();
    app(CustomerCreditNoteService::class)->confirmWithScenario($note);

    $note->refresh();
    expect($note->vat_scenario)->toBe(VatScenario::Domestic)  // NOT Exempt
        ->and($note->is_reverse_charge)->toBeFalse();
});

it('warns on late issuance', function () {
    $parent = CustomerInvoice::factory()->confirmed()->create();
    $note = CustomerCreditNote::factory()->draft()->withParent($parent)->create([
        'triggering_event_date' => now()->subDays(10),
        'issued_at' => now(),
    ]);

    app(CustomerCreditNoteService::class)->confirmWithScenario($note);

    Notification::assertNotified(fn ($n) => str_contains($n->body, '5 days'));
});

it('is immutable after confirmation', function () {
    $note = CustomerCreditNote::factory()->confirmed()->create();

    expect(fn () => $note->update(['notes' => 'tampering']))
        ->toThrow(\RuntimeException::class);
});

it('PDF renders referring-to-invoice block', function () {
    $parent = CustomerInvoice::factory()->confirmed()->create(['invoice_number' => '0000000042', 'issued_at' => '2025-06-15']);
    $note = CustomerCreditNote::factory()->confirmed()->withParent($parent)->create();

    $html = view('pdf.customer-credit-note', ['note' => $note])->render();
    expect($html)->toContain('#0000000042')
        ->and($html)->toContain('15.06.2025');
});
```

**File:** `tests/Feature/DebitNoteConfirmationTest.php`

Mirror + add:
- Standalone debit note → fresh determination
- Standalone + mixed items + reverse-charge → requires sub_code
- Debit note against `EuB2cOverThreshold` parent → positive OSS delta
- Standalone debit note does NOT accumulate fresh OSS

**File:** `tests/Feature/OssAdjustCrossYearTest.php`

```php
it('adjust uses parent year even when confirmed in a different year', function () {
    $parent = CustomerInvoice::factory()->confirmed()->euB2cOverThreshold()->create([
        'issued_at' => '2025-12-20',
    ]);
    $note = CustomerCreditNote::factory()->draft()->withParent($parent)->create([
        'issued_at' => '2026-01-05',  // different year
        'total' => 50,
    ]);

    app(CustomerCreditNoteService::class)->confirmWithScenario($note);

    // 2025 accumulator decremented; 2026 untouched
    expect((float) EuOssAccumulation::forYear(2025)->first()?->amount_eur)->toBeLessThan(0)
        ->and(EuOssAccumulation::forYear(2026)->count())->toBe(0);
});
```

---

## Gotchas / load-bearing details

1. **Inheritance before blocks.** Credit note inherits parent's scenario FIRST. Only if parent itself is Exempt (or standalone debit with non-registered tenant) does the Exempt short-circuit engage. F-021 is the test for this.
2. **Standalone debit notes skip OSS accumulation.** Intentional deferral — documented in the task's non-scope. A tenant that issues a standalone debit note crossing the OSS threshold will see incorrect accumulation; backlog item.
3. **Draft parent invoices block confirmation.** Intentional — cannot mirror what isn't frozen. User must confirm the parent first.
4. **5-day rule** — BG-specific. Per-MS variance belongs in `pre-launch.md`'s FX/rounding/retention bundle.
5. **Debit note goods/services picker** only shown for standalone + zero-rate-eligible + mixed. Parent-attached debit notes inherit; no picker.
6. **Partial credit notes mirror parent line-for-line.** If a parent line has a 20% rate, the credit line has a 20% rate. If the parent is reverse-charge with 0% rate, the credit line is 0%. No line-level re-rating.
7. **`convertTotalToEur()` must use parent's FX rate**, not the credit-note's own. Same legal treatment. Verify this when implementing — may need to refactor to read parent's `exchange_rate`.

---

## Exit Criteria

- [ ] All new tests green
- [ ] Full suite green
- [ ] Manual: credit note against reverse-charge invoice → PDF shows correct parent ref + Art. 138 citation + Reverse Charge wording
- [ ] Manual: standalone debit note for a non-EU B2B customer → goods/services picker appears
- [ ] Manual: cross-year credit (parent 2025, note 2026) → 2025 OSS reduced
- [ ] Pint clean
- [ ] Checklist in `invoice-credit-debit.md` ticked
