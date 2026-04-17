# Plan: DomesticExempt Scenario

> **Task:** `tasks/vat-vies/domestic-exempt.md`
> **Review:** `review.md` (drawn from retired phase-b-plan.md; no direct finding)
> **Status:** Ready to implement after `legal-references.md`

---

## Prerequisites

- [ ] `legal-references.md` merged — `VatLegalReference` model, 16 BG rows, `listForScenario()` + `resolve()` working
- [ ] `hotfix.md` merged — country_code not null
- [ ] `pdf-rewrite.md` optionally in flight; final PDF rendering lives there but this task's service + form can proceed independently

---

## Step 1 — Migration: `vat_scenario_sub_code` + backfill

**File:** `database/migrations/tenant/{timestamp}_add_vat_scenario_sub_code_to_customer_invoices.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->string('vat_scenario_sub_code')->nullable()->after('vat_scenario');
        });

        // Backfill per scenario rules.
        // Exempt invoices → 'default' (matches vat_legal_references seed).
        DB::table('customer_invoices')
            ->where('vat_scenario', 'exempt')
            ->whereNull('vat_scenario_sub_code')
            ->update(['vat_scenario_sub_code' => 'default']);

        // EU B2B reverse charge → assume goods (documented safer default for BG SMEs).
        DB::table('customer_invoices')
            ->where('vat_scenario', 'eu_b2b_reverse_charge')
            ->whereNull('vat_scenario_sub_code')
            ->update(['vat_scenario_sub_code' => 'goods']);

        // Non-EU export → assume goods.
        DB::table('customer_invoices')
            ->where('vat_scenario', 'non_eu_export')
            ->whereNull('vat_scenario_sub_code')
            ->update(['vat_scenario_sub_code' => 'goods']);

        // Domestic + EuB2c* → leave null (sub_code not applicable).
    }

    public function down(): void
    {
        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->dropColumn('vat_scenario_sub_code');
        });
    }
};
```

Add to `CustomerInvoice` `$fillable` + `$casts` (no cast needed, string is fine).

---

## Step 2 — VatScenario enum — add `DomesticExempt`

**File:** `app/Enums/VatScenario.php`

Add case (preserve alphabetical/priority order — insert between `Domestic` and `EuB2bReverseCharge`):

```php
case DomesticExempt = 'domestic_exempt';
```

Update `description()`:
```php
self::DomesticExempt => 'Domestic exemption — zero-rated under a specific ЗДДС article (39–49).',
```

Update `requiresVatRateChange()`:
```php
return match ($this) {
    self::Domestic, self::EuB2cUnderThreshold => false,
    self::Exempt, self::DomesticExempt, self::EuB2bReverseCharge, self::EuB2cOverThreshold, self::NonEuExport => true,
};
```

**`determine()` is NOT touched.** DomesticExempt is user-selected, never auto-determined. Add a comment above `determine()`:

```php
/**
 * NOTE: DomesticExempt is NOT returned by this method. It is a user-toggled
 * scenario on the invoice form. See CustomerInvoiceService::determineVatType().
 */
```

---

## Step 3 — Form: toggle + sub-code Select

**File:** `app/Filament/Resources/CustomerInvoices/Schemas/CustomerInvoiceForm.php`

Add two new fields in the VAT section (after `is_reverse_charge` toggle, before `pricing_mode`):

```php
use App\Enums\VatScenario;
use App\Models\CompanySettings;
use App\Models\Partner;
use App\Models\VatLegalReference;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;

Toggle::make('is_domestic_exempt')
    ->label('Domestic VAT exemption')
    ->helperText('Mark this invoice as exempt under a specific ЗДДС article (39–49).')
    ->live()
    ->dehydrated(false)  // not stored on its own column — drives sub-code
    ->visible(function (Get $get) {
        $partner = Partner::find($get('partner_id'));
        $tenantCountry = CompanySettings::get('company', 'country_code');

        return $partner
            && $partner->country_code === $tenantCountry;
    })
    ->afterStateUpdated(function ($state, callable $set) {
        if (!$state) {
            $set('vat_scenario_sub_code', null);
        } else {
            // Default to is_default row.
            $tenantCountry = CompanySettings::get('company', 'country_code');
            $defaultRow = VatLegalReference::forCountry($tenantCountry)
                ->ofScenario('domestic_exempt')
                ->default()
                ->first();
            if ($defaultRow) {
                $set('vat_scenario_sub_code', $defaultRow->sub_code);
            }
        }
    }),

Select::make('vat_scenario_sub_code')
    ->label('Exemption article')
    ->helperText('The ЗДДС article justifying the exemption.')
    ->options(function () {
        $tenantCountry = CompanySettings::get('company', 'country_code');
        return VatLegalReference::listForScenario($tenantCountry, 'domestic_exempt')
            ->mapWithKeys(fn ($ref) => [
                $ref->sub_code => "{$ref->legal_reference} — {$ref->getTranslation('description', app()->getLocale(), false)}",
            ]);
    })
    ->visible(fn (Get $get) => (bool) $get('is_domestic_exempt'))
    ->required(fn (Get $get) => (bool) $get('is_domestic_exempt')),
```

**Gotcha:** `is_domestic_exempt` is an ephemeral form input (`dehydrated(false)`), not a persisted column. The service reads it from `$data` on create / update and routes accordingly. On edit, seed the toggle from the persisted `vat_scenario === DomesticExempt`:

```php
->afterStateHydrated(function ($set, ?Model $record) {
    if ($record?->vat_scenario === VatScenario::DomesticExempt) {
        $set('is_domestic_exempt', true);
    }
})
```

---

## Step 4 — Items Relation Manager — restrict 0%

**File:** `app/Filament/Resources/CustomerInvoices/RelationManagers/CustomerInvoiceItemsRelationManager.php`

Where the `vat_rate_id` Select is defined, add a conditional:

```php
Select::make('vat_rate_id')
    ->label('VAT Rate')
    ->options(function () {
        $parent = $this->getOwnerRecord();  // CustomerInvoice

        if ($parent->vat_scenario === VatScenario::DomesticExempt
            || $parent->vat_scenario === VatScenario::Exempt
            || $parent->is_reverse_charge) {
            $zero = VatRate::forCountry($parent->tenant_country ?? CompanySettings::get('company', 'country_code'))
                ->where('rate', 0)
                ->first();
            return $zero ? [$zero->id => '0% — Exempt'] : [];
        }

        return VatRate::active()
            ->forCountry(...)
            ->pluck('name', 'id');
    })
    ->required(),
```

**Caveat:** the RM reads the parent's stored state. For a brand-new draft invoice the `is_domestic_exempt` flag hasn't been saved yet → items RM still shows full rate list. This is acceptable — the service forces 0% at confirmation regardless. Soft UX regression; document.

---

## Step 5 — Service: route DomesticExempt at confirmation

**File:** `app/Services/CustomerInvoiceService.php`

Modify `determineVatType()` (existing method) and `confirmWithScenario()`:

```php
public function confirmWithScenario(
    CustomerInvoice $invoice,
    ?array $viesData = null,
    bool $treatAsB2c = false,
    ?ManualOverrideData $override = null,
    bool $isDomesticExempt = false,
    ?string $subCode = null,  // from form
): void {
    DB::transaction(function () use ($invoice, $viesData, $treatAsB2c, $override, $isDomesticExempt, $subCode) {
        $scenario = $isDomesticExempt
            ? VatScenario::DomesticExempt
            : $this->determineVatType($invoice, $treatAsB2c);

        // Tenant country guard (from hotfix)
        $tenantCountry = CompanySettings::get('company', 'country_code');
        if (empty($tenantCountry)) {
            throw new \DomainException('Tenant country_code is not set.');
        }

        // Apply rate changes if scenario requires
        if ($scenario->requiresVatRateChange()) {
            $this->applyZeroRateToItems($invoice, $tenantCountry);
        }

        // Sub-code resolution
        $finalSubCode = match ($scenario) {
            VatScenario::DomesticExempt => $subCode ?? throw new \DomainException('DomesticExempt requires a sub_code'),
            VatScenario::Exempt => 'default',
            VatScenario::EuB2bReverseCharge, VatScenario::NonEuExport
                => $this->inferSubCodeFromItems($invoice) ?? 'goods',
            default => null,
        };

        // Skip VIES + OSS for DomesticExempt, Exempt
        $shouldRunVies = !in_array($scenario, [VatScenario::DomesticExempt, VatScenario::Exempt], true);
        $shouldAccumulateOss = $scenario === VatScenario::EuB2cOverThreshold;  // as before

        // ... rest of existing logic, adapted
        $invoice->update([
            'vat_scenario' => $scenario,
            'vat_scenario_sub_code' => $finalSubCode,
            'is_reverse_charge' => $scenario === VatScenario::EuB2bReverseCharge,
            // ... viesData fields, override fields, status → Confirmed
        ]);

        // Conditional post-confirm
        if ($shouldAccumulateOss) {
            app(EuOssService::class)->accumulate($invoice);
        }
    });
}

/**
 * Peek at the invoice items to guess if it's mostly goods or services.
 * Not perfect — user can override via form in Phase C for standalone debit notes.
 */
protected function inferSubCodeFromItems(CustomerInvoice $invoice): ?string
{
    $kinds = $invoice->items
        ->map(fn ($item) => $item->productVariant?->product?->type)
        ->unique()
        ->filter();

    if ($kinds->count() === 1) {
        return match ($kinds->first()) {
            'service' => 'services',
            'bundle', 'goods', 'product' => 'goods',
            default => 'goods',
        };
    }

    // Mixed or unknown — default to 'goods' (BG SME majority assumption).
    return 'goods';
}
```

**ViewCustomerInvoice page** — pass `isDomesticExempt` + `subCode` from form state to `confirmWithScenario()`:

```php
// In the confirm action handler:
app(CustomerInvoiceService::class)->confirmWithScenario(
    $this->record,
    viesData: $viesData,
    isDomesticExempt: $data['is_domestic_exempt'] ?? false,
    subCode: $data['vat_scenario_sub_code'] ?? null,
);
```

---

## Step 6 — PDF rendering (cross-reference)

PDF legal-reference line is handled in `pdf-rewrite-plan.md` Step 4 (the meta box block):

```blade
$legalRef = VatLegalReference::resolve(
    $tenant->country_code,
    $invoice->vat_scenario->value,
    $invoice->vat_scenario_sub_code ?? 'default'
);
```

For DomesticExempt: `resolve('BG', 'domestic_exempt', 'art_45')` returns the `чл. 45 ЗДДС` row. PDF renders: `чл. 45 ЗДДС — Доставка, свързана със земя и сгради`.

No changes here — handled once `pdf-rewrite.md` is live.

---

## Tests

**File:** `tests/Unit/VatScenarioDomesticExemptTest.php`

```php
use App\Enums\VatScenario;
use App\Models\Partner;

it('DomesticExempt case exists on the enum', function () {
    expect(VatScenario::tryFrom('domestic_exempt'))->toBe(VatScenario::DomesticExempt);
});

it('requires rate change', function () {
    expect(VatScenario::DomesticExempt->requiresVatRateChange())->toBeTrue();
});

it('determine() never returns DomesticExempt for a domestic partner', function () {
    $partner = Partner::factory()->create(['country_code' => 'BG']);
    $result = VatScenario::determine($partner, 'BG', tenantIsVatRegistered: true);
    expect($result)->toBe(VatScenario::Domestic)
        ->and($result)->not->toBe(VatScenario::DomesticExempt);
});
```

**File:** `tests/Feature/CustomerInvoiceDomesticExemptConfirmationTest.php`

```php
it('confirms a domestic invoice as DomesticExempt when toggle is set', function () {
    $invoice = CustomerInvoice::factory()->draft()->domestic()->create();

    app(CustomerInvoiceService::class)->confirmWithScenario(
        $invoice,
        isDomesticExempt: true,
        subCode: 'art_45',
    );

    $invoice->refresh();
    expect($invoice->vat_scenario)->toBe(VatScenario::DomesticExempt)
        ->and($invoice->vat_scenario_sub_code)->toBe('art_45')
        ->and($invoice->is_reverse_charge)->toBeFalse();
});

it('applies 0% rate to items when DomesticExempt', function () {
    $invoice = CustomerInvoice::factory()->draft()->domestic()->withItems(3)->create();

    app(CustomerInvoiceService::class)->confirmWithScenario(
        $invoice,
        isDomesticExempt: true,
        subCode: 'art_39',
    );

    $invoice->refresh()->items->each(fn ($item) => expect($item->vatRate->rate)->toBe(0.0));
});

it('skips OSS accumulation for DomesticExempt', function () {
    $before = EuOssAccumulation::count();

    $invoice = CustomerInvoice::factory()->draft()->domestic()->create();
    app(CustomerInvoiceService::class)->confirmWithScenario(
        $invoice,
        isDomesticExempt: true,
        subCode: 'art_39',
    );

    expect(EuOssAccumulation::count())->toBe($before);
});

it('throws when DomesticExempt is set without a sub_code', function () {
    $invoice = CustomerInvoice::factory()->draft()->domestic()->create();

    expect(fn () => app(CustomerInvoiceService::class)->confirmWithScenario(
        $invoice,
        isDomesticExempt: true,
        subCode: null,
    ))->toThrow(\DomainException::class);
});
```

**File:** `tests/Feature/CustomerInvoiceFormDomesticExemptTest.php`

```php
use function Pest\Livewire\livewire;

it('shows the DomesticExempt toggle only for domestic partner', function () {
    $domesticPartner = Partner::factory()->create(['country_code' => 'BG']);
    $euPartner = Partner::factory()->create(['country_code' => 'DE']);

    livewire(CreateCustomerInvoice::class)
        ->fillForm(['partner_id' => $domesticPartner->id])
        ->assertFormFieldExists('is_domestic_exempt');

    livewire(CreateCustomerInvoice::class)
        ->fillForm(['partner_id' => $euPartner->id])
        ->assertFormFieldHidden('is_domestic_exempt');
});

it('defaults sub-code to art_39 when toggle is enabled', function () {
    $partner = Partner::factory()->create(['country_code' => 'BG']);

    livewire(CreateCustomerInvoice::class)
        ->fillForm([
            'partner_id' => $partner->id,
            'is_domestic_exempt' => true,
        ])
        ->assertFormSet(['vat_scenario_sub_code' => 'art_39']);
});
```

**File:** `tests/Feature/VatScenarioSubCodeBackfillTest.php` (regression for the migration)

```php
it('backfills eu_b2b_reverse_charge invoices to goods', function () {
    // Create invoice bypassing the backfill (raw insert) at old scenario state
    DB::table('customer_invoices')->insert([
        /* minimal fields */ 'vat_scenario' => 'eu_b2b_reverse_charge',
        'vat_scenario_sub_code' => null,
    ]);

    Artisan::call('migrate', ['--path' => 'database/migrations/tenant']);

    $row = DB::table('customer_invoices')->latest()->first();
    expect($row->vat_scenario_sub_code)->toBe('goods');
});
```

---

## Gotchas / load-bearing details

1. **`is_domestic_exempt` is ephemeral**, not persisted. Source-of-truth on a saved record is `vat_scenario === DomesticExempt`. Form must hydrate the toggle from the scenario on edit.
2. **Items RM reactivity** — RM reads the parent from DB at mount; for fresh drafts the scenario isn't stored yet. Acceptable — service forces 0% at confirmation. Document in the task file's "Known Gaps" comments if surfaces complaints.
3. **Sub-code default comes from `is_default`, not hard-coded `'art_39'`.** Preserves the pattern so that if someone re-seeds a different article as default, UI follows automatically.
4. **EU B2C Over Threshold is NOT affected** by this task. Its sub-code remains null (scenario doesn't need a legal reference; OSS destination-country VAT is charged).
5. **`inferSubCodeFromItems()`** assumes products have a `type` attribute (`goods` / `services` / `bundle` / `product`). If they don't, adjust or drop the heuristic — default to `'goods'`.
6. **Backfill is one-time, safe.** `whereNull(vat_scenario_sub_code)` guards against re-running. If a tenant needs to correct a historical invoice from `goods` to `services`, a one-off tenant-ops command is added in the future (not here).

---

## Exit Criteria

- [ ] All tests pass: `./vendor/bin/sail artisan test --parallel --compact --filter="DomesticExempt|VatScenarioSubCode"`
- [ ] Full suite green
- [ ] Manual: BG tenant creates a domestic invoice → toggle exemption → pick art_45 → confirm → PDF shows `чл. 45 ЗДДС`
- [ ] Pint clean
- [ ] Checklist in `domestic-exempt.md` ticked
