# Plan: Invoice VAT Determination — Refactor Queue

> **Task:** `tasks/vat-vies/invoice.md`
> **Review:** `tasks/vat-vies/review.md` (F-006, F-007, F-009, F-024, F-036)
> **Status:** Refactor-only plan (Area 3 implementation is already shipped). Covers the four review findings above; F-003 deferred to backlog.

---

## Prerequisites

- [ ] `hotfix.md` merged (country_code not null; immutability guard; `$ignorePartnerVat` doc clarification)
- [ ] `domestic-exempt.md` merged (adds `vat_scenario_sub_code` — needed for F-007 sub-code split)
- [ ] `pdf-rewrite.md` optional — Step 1's OSS year change works independently; downstream PDF consumption is separately tested there

---

## Step 1 — Fix OSS year to use invoice's chargeable-event year (F-006)

**File:** `app/Enums/VatScenario.php`

Currently (line 58):

```php
if (EuOssAccumulation::isThresholdExceeded((int) now()->year)) {
    return self::EuB2cOverThreshold;
}
```

**Change signature to accept year:**

```php
public static function determine(
    Partner $partner,
    string $tenantCountryCode,
    bool $ignorePartnerVat = false,
    bool $tenantIsVatRegistered = true,
    ?int $year = null,
): self {
    // ... existing tenant + country checks

    if (EuOssAccumulation::isThresholdExceeded($year ?? (int) now()->year)) {
        return self::EuB2cOverThreshold;
    }

    return self::EuB2cUnderThreshold;
}
```

**Callers:**

- `CustomerInvoiceForm` scenario preview → pass `now()->year` (form-time preview, current year is correct for UX)
- `CustomerInvoiceService::previewScenario()` → pass `$invoice->supplied_at?->year ?? $invoice->issued_at?->year ?? now()->year`
- `CustomerInvoiceService::confirmWithScenario()` → SAME. MUST pass the invoice's year, not `now()->year`.
- `CustomerInvoiceService::determineVatType()` → SAME
- `CustomerDebitNoteService::confirmWithScenario()` standalone path → pass `$note->issued_at->year`

**File:** `app/Services/EuOssService.php`

`accumulate()` — already fixed per `pdf-rewrite.md` / Phase B plan to use `$invoice->issued_at->year`. Verify.
`adjust()` — added in `invoice-credit-debit.md`; uses `$parent->issued_at->year`. Verify.
`shouldApplyOss()` — keeps `now()->year` because it's used at form-preview time (current-year check is correct).

**Regression test** (`tests/Feature/VatScenarioOssCrossYearTest.php`):

```php
it('uses invoice issued_at year, not wall-clock year', function () {
    // Accumulate 2025 past threshold
    EuOssAccumulation::updateOrCreate(['year' => 2025], ['amount_eur' => 15_000]);
    EuOssAccumulation::updateOrCreate(['year' => 2026], ['amount_eur' => 0]);

    $partner = Partner::factory()->create(['country_code' => 'DE', 'vat_status' => VatStatus::NotRegistered]);

    // Invoice dated Dec 2025, confirmed in Jan 2026
    $scenario = VatScenario::determine(
        partner: $partner,
        tenantCountryCode: 'BG',
        tenantIsVatRegistered: true,
        year: 2025,  // explicit
    );

    expect($scenario)->toBe(VatScenario::EuB2cOverThreshold);
});

it('passes current year when no year argument given (backward compat)', function () {
    // Confirms that default behavior matches pre-refactor
    EuOssAccumulation::updateOrCreate(['year' => now()->year], ['amount_eur' => 0]);
    $partner = Partner::factory()->create(['country_code' => 'DE', 'vat_status' => VatStatus::NotRegistered]);

    $scenario = VatScenario::determine($partner, 'BG', tenantIsVatRegistered: true);
    expect($scenario)->toBe(VatScenario::EuB2cUnderThreshold);
});
```

---

## Step 2 — Update `NonEuExport` description (F-007)

**File:** `app/Enums/VatScenario.php`

**Before:**
```php
self::NonEuExport => 'Non-EU export — zero-rated (0% VAT).',
```

**After:**
```php
self::NonEuExport => 'Non-EU supply — zero-rated (goods, Art. 146) or outside scope of EU VAT (services, Art. 44).',
```

That's the only change in this file. Exact article citation for a given invoice comes from `VatLegalReference::resolve(country, 'non_eu_export', $subCode)` at PDF render time (handled in `pdf-rewrite.md`).

---

## Step 3 — Reverse-charge override recency + acknowledgement (F-009)

**File:** `app/Filament/Resources/CustomerInvoices/Pages/ViewCustomerInvoice.php`

The "Confirm with Reverse Charge" action during VIES unavailable state:

### Recency gate

Only show the button when `partner.vies_verified_at > now()->subDays(30)`:

```php
->visible(function (CustomerInvoice $record): bool {
    $partner = $record->partner;
    return $partner->vat_status === VatStatus::Confirmed
        && $partner->vies_verified_at
        && $partner->vies_verified_at->gt(now()->subDays(30));
})
```

Below 30 days: button hidden; user sees "VIES unavailable — retry or confirm with VAT" only. Above: button appears with the role gate + acknowledgement.

### Alt-proof acknowledgement

Require a checkbox in the override modal:

```php
->schema([
    Checkbox::make('alternative_proof_acknowledgement')
        ->label('I have obtained alternative proof of the customer\'s taxable status (e.g. VAT certificate) and will retain it for the statutory period (BG: 10 years).')
        ->required()
        ->validationMessages(['required' => 'You must acknowledge alternative-proof retention before proceeding.']),
])
->action(function (array $data, CustomerInvoice $record): void {
    // Store acknowledgement
    $record->reverse_charge_override_acknowledgement = true;
    // ... existing override logic
})
```

Add a column to `customer_invoices`:
```php
$table->boolean('reverse_charge_override_acknowledgement')->default(false)->after('reverse_charge_override_reason');
```

### Configurable recency window

Make the 30-day window configurable. Add to `config/vat-vies.php` (new file if not present):

```php
return [
    'reverse_charge_override_recency_days' => env('VAT_VIES_RC_OVERRIDE_RECENCY_DAYS', 30),
];
```

Read in the visibility check:
```php
->gt(now()->subDays(config('vat-vies.reverse_charge_override_recency_days')))
```

---

## Step 4 — Partner mutation inside confirmation transaction (F-024)

**File:** `app/Services/CustomerInvoiceService.php`

Currently `runViesPreCheck()` directly mutates the partner. Refactor to return an **intent**, applied in `confirmWithScenario()`'s transaction.

```php
class PartnerMutationIntent
{
    public function __construct(
        public readonly bool $downgradeToNotRegistered,
        public readonly ?string $reason = null,
    ) {}

    public static function none(): self
    {
        return new self(downgradeToNotRegistered: false);
    }

    public static function downgrade(string $reason): self
    {
        return new self(downgradeToNotRegistered: true, reason: $reason);
    }
}
```

### Refactored pre-check

```php
/**
 * Returns VIES result + a pending partner mutation intent (not applied yet).
 */
public function runViesPreCheck(CustomerInvoice $invoice): array
{
    // ... existing eligibility checks

    $result = $this->vies->validate($partner->country_code, $vatNumber, fresh: true);

    if (!$result['available']) {
        return [
            'needed' => true,
            'result' => ViesResult::Unavailable,
            'partner_mutation' => PartnerMutationIntent::none(),
            'request_id' => null,
            'checked_at' => null,
        ];
    }

    if ($result['valid']) {
        // Partner stays Confirmed (or upgrades from Pending to Confirmed — an intent, applied inside tx)
        return [
            'needed' => true,
            'result' => ViesResult::Valid,
            'partner_mutation' => PartnerMutationIntent::none(),  // or 'confirm' intent if pending
            'request_id' => $result['request_id'],
            'checked_at' => now(),
        ];
    }

    // VIES said invalid — stage downgrade but don't apply yet
    return [
        'needed' => true,
        'result' => ViesResult::Invalid,
        'partner_mutation' => PartnerMutationIntent::downgrade('vies_invalid_at_invoice_confirmation'),
        'request_id' => $result['request_id'],
        'checked_at' => now(),
    ];
}
```

### Apply inside confirmWithScenario

```php
public function confirmWithScenario(
    CustomerInvoice $invoice,
    ?array $viesData = null,
    bool $treatAsB2c = false,
    ?ManualOverrideData $override = null,
    bool $isDomesticExempt = false,
    ?string $subCode = null,
): void {
    DB::transaction(function () use ($invoice, $viesData, ...) {
        // 1. Apply staged partner mutation FIRST (inside tx)
        if ($viesData && ($viesData['partner_mutation'] ?? null)?->downgradeToNotRegistered) {
            $this->applyPartnerDowngrade($invoice->partner, $viesData['partner_mutation']->reason, $invoice);
        }

        // 2. Now determine scenario with the updated partner state
        // ... existing logic (including F-006 year fix)

        // 3. Write invoice
        // ... existing logic

        // If tx rolls back, partner downgrade rolls back too.
    });
}

protected function applyPartnerDowngrade(Partner $partner, string $reason, CustomerInvoice $invoice): void
{
    $partner->update([
        'vat_status' => VatStatus::NotRegistered,
        'vat_number' => null,
        'vies_verified_at' => null,
    ]);

    activity()
        ->performedOn($partner)
        ->causedBy(auth()->user())
        ->withProperties([
            'reason' => $reason,
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'checked_at' => now()->toIso8601String(),
        ])
        ->log('Partner VAT downgraded to not_registered by VIES rejection');

    Notification::make()
        ->title('Partner VAT downgraded')
        ->body("Partner '{$partner->company_name}' was marked as not VAT-registered...")
        ->warning()
        ->persistent()
        ->send();
}
```

### Regression test

```php
it('does not mutate partner if confirmWithScenario fails after pre-check (F-024)', function () {
    $partner = Partner::factory()->confirmed()->create(['country_code' => 'DE']);
    $invoice = CustomerInvoice::factory()->draft()->forPartner($partner)->create();

    ViesValidationService::shouldReceive('validate')
        ->andReturn(['available' => true, 'valid' => false, ...]);

    // Force confirmWithScenario to throw mid-transaction
    $this->mock(SomeDownstreamService::class)->shouldReceive('foo')->andThrow(new \RuntimeException('boom'));

    $viesData = app(CustomerInvoiceService::class)->runViesPreCheck($invoice);
    expect(fn () => app(CustomerInvoiceService::class)->confirmWithScenario($invoice, viesData: $viesData))
        ->toThrow(\RuntimeException::class);

    // Partner was NOT downgraded — transaction rolled back
    expect($partner->fresh()->vat_status)->toBe(VatStatus::Confirmed);
});
```

---

## Tests

**File:** `tests/Feature/InvoiceRefactorTest.php`

Consolidates Step 1-4 tests:

```php
// Step 1 — OSS year
it('scenario determination uses invoice issued_at year', function () { ... });

// Step 3 — RC override recency
it('hides reverse-charge override when vies_verified_at is older than 30 days', function () { ... });
it('requires alternative-proof acknowledgement to use the override', function () { ... });

// Step 4 — Transactional boundary
it('does not mutate partner when confirmWithScenario rolls back', function () { ... });
```

---

## Gotchas / load-bearing details

1. **F-006 default value.** `?int $year = null` → caller opt-in. All invoice-flow callers MUST pass the invoice's year. The default-to-now exists only for the form preview case.
2. **F-003 ECSL / ВИЕС-декларация is intentionally NOT here.** It's reporting, not invoice-determination. Flagged in backlog. Do not bolt it in.
3. **F-009 recency window.** 30 days is a starting point. Revisit with the accountant before first real tenant. Make configurable so each tenant can tune.
4. **F-024 DTO pattern** — the `PartnerMutationIntent` object keeps the pre-check side-effect-free. Don't skip the refactor and "just use a flag" — an intent object leaves room for future mutations (e.g. pending→confirmed) without further changes.
5. **Transactional boundary for Pending→Confirmed upgrade** — when VIES returns valid for a Pending partner, the partner is effectively upgraded. Same principle: stage the intent, apply inside tx.

---

## Exit Criteria

- [ ] All refactor tests green
- [ ] Full suite green
- [ ] Manual: confirm a cross-year invoice (December 2025 invoice, confirmed January 2026) → OSS year is 2025
- [ ] Manual: reverse-charge override button hidden for a partner with `vies_verified_at = 2 months ago`
- [ ] Manual: override requires checkbox tick
- [ ] Manual: force confirmation failure → partner state reverts
- [ ] Pint clean
- [ ] `invoice.md` refactor checkbox ticked
