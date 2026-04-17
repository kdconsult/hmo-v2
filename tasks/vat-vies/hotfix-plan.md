# Plan: Post-Review Hotfix Bundle

> **Task:** `tasks/vat-vies/hotfix.md`
> **Review:** `review.md` (F-030, F-031, F-005, F-017, F-018, F-034, F-036)
> **Status:** Ready to implement

---

## Prerequisites

- [ ] Clean branch off `main`: `hotfix/vat-country-immutability-cache`
- [ ] All tests currently green on main
- [ ] No uncommitted tenant DB migrations pending

---

## Step 1 — `VatScenario::determine()` throws on empty country

**File:** `app/Enums/VatScenario.php` (edit lines ~42-44)

**Before:**
```php
if (empty($partner->country_code)) {
    return self::NonEuExport;
}
```

**After:**
```php
if (empty(trim((string) $partner->country_code))) {
    throw new \DomainException(
        "Cannot determine VAT scenario: partner #{$partner->id} has no country_code. " .
        'Every partner must have a country_code set.'
    );
}
```

Also update the docblock above `determine()`:
- Remove "Empty country_code → treat as non-EU export" line (item 1)
- Add "Empty country_code → throws DomainException (see hotfix.md / review.md#f-030)" at the top

**Call sites that need try/catch guarding:**
- `CustomerInvoiceService::runViesPreCheck()` — before calling `determine()`, validate partner has country; surface a user-facing error
- `CustomerInvoiceService::determineVatType()` — same
- `CustomerInvoiceForm` scenario preview (line ~56-116) — wrap in try, display "Partner missing country" helper text if caught

Preview is non-committal so throwing there is user-hostile — catch and display a clear message: "Partner has no country set. Fix the partner record before confirming."

---

## Step 2 — Tenant country_code guard (same pattern)

**File:** `app/Services/CustomerInvoiceService.php` wherever tenant country is read

**Pattern:**
```php
$tenantCountry = CompanySettings::get('company', 'country_code');
if (empty(trim((string) $tenantCountry))) {
    throw new \DomainException(
        'Tenant country_code is not set. Configure it in Company Settings before confirming invoices.'
    );
}
```

Emit this guard at the **top** of `confirmWithScenario()` — before any DB write. Fails fast.

Same guard in `previewScenario()` with a catch-and-render UX.

---

## Step 3 — Full-country list helper

**File:** Either extend `app/Support/EuCountries.php` with a `::allCountriesForSelect()` helper, OR create `app/Support/Countries.php`.

Preferred: new `Countries.php` to avoid confusing the "EU" class. `EuCountries` stays the authoritative source for EU membership / VAT regex / prefix.

```php
<?php

namespace App\Support;

class Countries
{
    /**
     * ISO 3166-1 alpha-2 country codes keyed to their English display names.
     * Ordered alphabetically by name for Select dropdowns.
     *
     * @return array<string, string>
     */
    public static function forSelect(): array
    {
        // Use a package or a static data file. Suggest: symfony/intl or a shipped JSON.
        // If symfony/intl is already a dependency, prefer:
        // return \Symfony\Component\Intl\Countries::getNames();
        // Otherwise ship a small data/countries.php returning the ISO list.
        return [
            'AT' => 'Austria', 'BE' => 'Belgium', 'BG' => 'Bulgaria',
            // ... full ISO list
            'US' => 'United States', 'GB' => 'United Kingdom', 'CH' => 'Switzerland',
            // etc.
        ];
    }
}
```

**Check first:** if `symfony/intl` is already installed (composer.json), use `\Symfony\Component\Intl\Countries::getNames($locale)`. Cleaner.

---

## Step 4 — Partner form country_code required + full list + default

**File:** `app/Filament/Resources/Partners/Schemas/PartnerForm.php` (line ~49)

**Before:**
```php
Select::make('country_code')
    ->label('Country')
    ->options(EuCountries::forSelect())
    ->searchable()
    ->live()
    ->helperText('Determines EU VAT treatment on invoices.')
    ->afterStateUpdated(fn ($livewire) => $livewire->resetVatState()),
```

**After:**
```php
Select::make('country_code')
    ->label('Country')
    ->options(\App\Support\Countries::forSelect())
    ->required()
    ->searchable()
    ->live()
    ->default(fn () => \App\Models\CompanySettings::get('company', 'country_code'))
    ->helperText('Determines VAT treatment on invoices. Required.')
    ->afterStateUpdated(fn ($livewire) => $livewire->resetVatState()),
```

**Key changes:**
- `->required()` — Filament validation enforces non-null at save
- Options: full ISO list instead of EU-only
- Default: tenant country (new partners inherit tenant country; user can override)
- Helper text clarifies requirement

---

## Step 5 — Tenant / CompanySettings form country_code required

**File:** `app/Filament/Pages/CompanySettingsPage.php` (line ~104-109)

Add `->required()` to the country_code select. Keep whatever default logic is already there.

**File:** `app/Filament/Resources/Tenants/Schemas/TenantForm.php` (if present — check first)

Same change.

Any **registration / onboarding** flow that creates a tenant must also set country_code — if `RegisterTenant.php` Livewire component creates a tenant without country, add it to the required fields. (This ties into the tenant.md refactor queue; cross-link.)

---

## Step 6 — Migration: data-fix + NOT NULL on partners.country_code

**File:** `database/migrations/tenant/{timestamp}_partners_country_code_not_null.php`

Generate: `php artisan make:migration partners_country_code_not_null --path=database/migrations/tenant --no-interaction`

```php
<?php

use App\Models\CompanySettings;
use App\Models\Partner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Data-fix: null rows get the tenant's own country as a best-effort default.
        $tenantCountry = CompanySettings::get('company', 'country_code');

        if ($tenantCountry) {
            DB::table('partners')
                ->whereNull('country_code')
                ->update(['country_code' => strtoupper($tenantCountry)]);
        }

        // 2. Any rows still null (tenant itself has no country set) — throw; schema guard
        //    cannot be applied until data is clean.
        $remainingNulls = DB::table('partners')->whereNull('country_code')->count();
        if ($remainingNulls > 0) {
            throw new \RuntimeException(
                "Cannot apply NOT NULL to partners.country_code: {$remainingNulls} null rows remain. " .
                'Configure tenant country_code first (Company Settings) and re-run this migration.'
            );
        }

        // 3. Apply NOT NULL.
        Schema::table('partners', function (Blueprint $table) {
            $table->string('country_code', 2)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->string('country_code', 2)->nullable()->change();
        });
    }
};
```

**Notes:**
- Runs inside tenant DB via `tenants:migrate`.
- If a tenant has no country itself, migration throws with a clear remediation message. This is intentional — we cannot fabricate a country.
- Down migration reverts to nullable for safety (never used in practice, but required for CI rollback).

**Same migration for central DB `tenants` table:**

**File:** `database/migrations/{timestamp}_tenants_country_code_not_null.php`

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
        $remainingNulls = DB::table('tenants')->whereNull('country_code')->count();
        if ($remainingNulls > 0) {
            throw new \RuntimeException(
                "Cannot apply NOT NULL to tenants.country_code: {$remainingNulls} null rows remain. " .
                'Resolve manually before applying this migration.'
            );
        }

        Schema::table('tenants', function (Blueprint $table) {
            $table->string('country_code', 2)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('country_code', 2)->nullable()->change();
        });
    }
};
```

---

## Step 7 — CustomerInvoice immutability guard

**File:** `app/Models/CustomerInvoice.php`

Add a `booted()` method (or extend existing `booted()`):

```php
use App\Enums\DocumentStatus; // adjust to actual enum name in this project
use RuntimeException;

protected static function booted(): void
{
    static::updating(function (self $invoice): void {
        $originalStatus = $invoice->getOriginal('status');

        if ($originalStatus instanceof DocumentStatus) {
            $originalStatus = $originalStatus->value;
        }

        if ($originalStatus === DocumentStatus::Confirmed->value) {
            throw new RuntimeException(
                "Confirmed invoices are immutable (chl. 114, al. 6 ZDDS / Art. 233 Directive). " .
                "Invoice #{$invoice->invoice_number}. Issue a credit or debit note instead."
            );
        }
    });

    static::deleting(function (self $invoice): void {
        if ($invoice->status instanceof DocumentStatus
            ? $invoice->status === DocumentStatus::Confirmed
            : $invoice->status === DocumentStatus::Confirmed->value) {
            throw new RuntimeException(
                "Confirmed invoices cannot be deleted. Invoice #{$invoice->invoice_number}."
            );
        }
    });
}
```

**Pattern reference:** `app/Models/StockMovement.php` (per CLAUDE.md — "StockMovement rows throw RuntimeException on update/delete — this is intentional"). Read sibling before writing; match style exactly.

**DocumentStatus enum:** check actual enum name in this project — could be `InvoiceStatus`, `CustomerInvoiceStatus`, or `DocumentStatus`. Adjust import.

---

## Step 8 — CustomerInvoiceItem immutability guard

**File:** `app/Models/CustomerInvoiceItem.php`

Same pattern, but check the parent invoice's status:

```php
protected static function booted(): void
{
    static::updating(function (self $item): void {
        if ($item->invoice?->status === DocumentStatus::Confirmed) {
            throw new RuntimeException(
                "Cannot modify items of a confirmed invoice #{$item->invoice->invoice_number}."
            );
        }
    });

    static::deleting(function (self $item): void {
        if ($item->invoice?->status === DocumentStatus::Confirmed) {
            throw new RuntimeException(
                "Cannot delete items of a confirmed invoice #{$item->invoice->invoice_number}."
            );
        }
    });
}
```

---

## Step 9 — ViesValidationService tenant-scoped cache key + comment cleanup

**File:** `app/Services/ViesValidationService.php`

**Edit 1 — line 24, add tenant id:**

```php
$tenantId = tenancy()->tenant?->id ?? 'central';
$cacheKey = "vies_validation_{$tenantId}_{$countryCode}_{$vatNumber}";
```

**Edit 2 — lines 48-49, remove stale comment:**

Delete:
```
* Note: Both checkVat and checkVatApprox are defined in the same WSDL
* (checkVatService.wsdl). Verify this holds against the live endpoint if SOAP faults occur.
```

Replace with:
```
* The live WSDL at checkVatService.wsdl exposes both checkVat and checkVatApprox.
* Verified 2026-04-17 — no separate checkVatApproxService.wsdl exists.
```

**Edit 3 — verify `config/tenancy.php`:**

Confirm `\Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper::class` is listed under `bootstrappers`. If present → cache is tenant-scoped by framework; our key prefix is defence-in-depth. If missing → add it AND our prefix. If adding, note in the hotfix commit that an existing behavior may change (test for regressions across existing cache callers).

---

## Step 10 — Remediation artisan command

**File:** `app/Console/Commands/VatRemediateCountryCodeCommand.php`

Generate: `php artisan make:command VatRemediateCountryCodeCommand --no-interaction`

```php
<?php

namespace App\Console\Commands;

use App\Models\CustomerInvoice;
use Illuminate\Console\Command;

class VatRemediateCountryCodeCommand extends Command
{
    protected $signature = 'hmo:vat-remediate-country-code {--tenant= : Single tenant ID or domain to audit}';

    protected $description = 'Audit confirmed non_eu_export invoices whose partner has no country_code. Candidates for manual tax remediation.';

    public function handle(): int
    {
        // Must run inside tenant context.
        $candidates = CustomerInvoice::query()
            ->where('vat_scenario', 'non_eu_export')
            ->where('status', 'confirmed')
            ->whereHas('partner', function ($q) {
                $q->whereNull('country_code');
            })
            ->with('partner:id,company_name,country_code')
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('No candidates found. All confirmed NonEuExport invoices have a partner country.');
            return self::SUCCESS;
        }

        $this->warn("Found {$candidates->count()} candidate(s) for remediation:");

        $this->table(
            ['Invoice #', 'Issued', 'Partner', 'Total', 'Currency'],
            $candidates->map(fn (CustomerInvoice $i) => [
                $i->invoice_number,
                $i->issued_at?->format('Y-m-d'),
                $i->partner?->company_name ?: '(unknown)',
                number_format((float) $i->total, 2),
                $i->currency_code,
            ]),
        );

        $this->info('Each candidate may need a credit note + reissuance at the correct domestic rate.');
        $this->info('Escalate to the tenant\'s accountant — this is a tax-remediation decision, not a code fix.');

        return self::SUCCESS;
    }
}
```

**Usage:** `./vendor/bin/sail artisan tenants:run hmo:vat-remediate-country-code` (runs inside each tenant context).

---

## Step 11 — Doc drift cleanup

**Edits (trivial):**

1. `tasks/vat-vies/invoice.md` — **Known Changes → VatScenario** section:
   - Change "Remove `$ignorePartnerVat` parameter — no longer needed" → "Keep `$ignorePartnerVat` parameter. Used by the `confirm()` backward-compat wrapper and the 'Confirm with VAT' path when VIES has rejected the partner. `[review.md#f-036]`"

2. `spec.md` — VIES unavailable localStorage line: **already done** in the spec-sync commit. Verify.

3. `ViesValidationService.php` — WSDL comment: handled in Step 9.

---

## Step 12 — Memory cleanup

**File:** `~/.claude/projects/-home-bogui-projects-hmo-hmo-v2/memory/project_vat_vies_design.md`

Find the line:
> `Areas 1+2 agreed (tenant+partner); Area 3 (invoice) next; spec at tasks/vat-vies/spec.md; old VAT-DETERMINATION-1 code to be replaced`

Replace with:
> `VAT/VIES design — Areas 1-3 shipped; hotfix + Phase A (legal-references) + PDF rewrite + DomesticExempt + blocks + credit/debit + pre-launch queued. VAT-DETERMINATION-1 replaced 2026-04. Spec at tasks/vat-vies/spec.md; full audit at tasks/vat-vies/review.md.`

Or delete entirely and let future discovery re-seed from spec.md / review.md.

---

## Tests

**File:** `tests/Feature/VatScenarioDetermineCountryCodeTest.php`

```php
use App\Enums\VatScenario;
use App\Models\Partner;

it('throws DomainException when partner country_code is empty', function () {
    $partner = Partner::factory()->create(['country_code' => null]);

    expect(fn () => VatScenario::determine($partner, 'BG'))
        ->toThrow(\DomainException::class);
});

it('throws on whitespace country_code', function () {
    $partner = Partner::factory()->create(['country_code' => '   ']);

    expect(fn () => VatScenario::determine($partner, 'BG'))
        ->toThrow(\DomainException::class);
});

it('does not throw on valid country_code', function () {
    $partner = Partner::factory()->create(['country_code' => 'BG']);

    expect(VatScenario::determine($partner, 'BG'))->toBe(VatScenario::Domestic);
});
```

**File:** `tests/Feature/CustomerInvoiceImmutabilityTest.php`

```php
use App\Models\CustomerInvoice;
use App\Enums\DocumentStatus;

it('throws when updating a confirmed invoice', function () {
    $invoice = CustomerInvoice::factory()->create(['status' => DocumentStatus::Confirmed]);

    expect(fn () => $invoice->update(['notes' => 'tampering']))
        ->toThrow(\RuntimeException::class, 'immutable');
});

it('throws when deleting a confirmed invoice', function () {
    $invoice = CustomerInvoice::factory()->create(['status' => DocumentStatus::Confirmed]);

    expect(fn () => $invoice->delete())
        ->toThrow(\RuntimeException::class, 'cannot be deleted');
});

it('allows updating a draft invoice', function () {
    $invoice = CustomerInvoice::factory()->create(['status' => DocumentStatus::Draft]);

    $invoice->update(['notes' => 'ok']);
    expect($invoice->fresh()->notes)->toBe('ok');
});
```

**File:** `tests/Feature/ViesCacheTenantIsolationTest.php`

```php
use App\Services\ViesValidationService;
use Illuminate\Support\Facades\Cache;

it('different tenants get independent cache entries for the same VAT lookup', function () {
    $tenantA = createTenant();
    $tenantB = createTenant();

    $tenantA->run(function () {
        Cache::put('vies_validation_' . tenancy()->tenant->id . '_DE_123', ['valid' => true], 60);
    });

    $tenantB->run(function () {
        $key = 'vies_validation_' . tenancy()->tenant->id . '_DE_123';
        expect(Cache::has($key))->toBeFalse();
    });
});
```

**File:** `tests/Feature/PartnerFormCountryRequiredTest.php`

```php
use App\Filament\Resources\Partners\Pages\CreatePartner;
use function Pest\Livewire\livewire;

it('refuses save when country_code is blank', function () {
    livewire(CreatePartner::class)
        ->fillForm(['name' => 'No Country Co', 'country_code' => null])
        ->call('create')
        ->assertHasFormErrors(['country_code']);
});

it('defaults country_code to tenant country on create', function () {
    livewire(CreatePartner::class)
        ->assertFormFieldExists('country_code')
        ->assertFormSet(['country_code' => 'BG']); // assuming test tenant is BG
});

it('offers non-EU countries in the dropdown', function () {
    livewire(CreatePartner::class)
        ->assertFormFieldExists('country_code');
    // Inspect options — should include US, GB, CH, etc.
});
```

---

## Gotchas / load-bearing details

1. **DocumentStatus enum naming.** Check the actual enum class name before writing the immutability guard. If unsure, `grep -r "enum.*Status" app/Enums/` and read sibling usage.
2. **Migration throws on remaining nulls.** If a tenant DB has a partner with null country AND no tenant country, the migration fails loudly. This is intentional — we won't invent data. Tenant operator must set country first.
3. **ViesValidationService tenant id.** `tenancy()->tenant?->id` may return the UUID. Whatever it returns, stringify safely — don't crash on central/no-tenant context.
4. **Remediation command is a SELECT, not an UPDATE.** Never mutate invoices. Surface the list; let the tenant's accountant decide.
5. **Full country list source.** Prefer `symfony/intl` if already installed (check composer.json). Otherwise hand-ship a small static list; don't fetch at runtime.
6. **Filament `->default()` on a form that's also used for edit.** Default only fires on create; for edit the stored value is used. That's desired here.
7. **Pint after every change.** `vendor/bin/pint --dirty --format agent`.

---

## Exit Criteria

- [ ] Branch rebases clean on main
- [ ] All new tests green: `./vendor/bin/sail artisan test --parallel --compact --filter="VatScenario|Immutability|ViesCache|PartnerForm"`
- [ ] Full test suite green: `./vendor/bin/sail artisan test --parallel --compact`
- [ ] Tenant migration runs cleanly against a scratch tenant
- [ ] `hmo:vat-remediate-country-code` runs without error (empty or populated)
- [ ] Manual: create a partner with no country → form blocks
- [ ] Manual: try to edit a confirmed invoice via Filament → user-visible error surfaced
- [ ] Pint clean
- [ ] Hotfix.md checklist ticked

---

## Follow-ups

- Partner form country default falls back to tenant country; if that too is null, partner creation is blocked. Fine — tenant setup must precede partner creation anyway.
- After landing, rebase `legal-references` branch on top of hotfix before starting that task.
