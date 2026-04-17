# Phase A — Legal References Foundation (VAT/VIES)

## Context

The existing VAT/VIES planning docs hardcoded Bulgarian legal references
("Art. 96 ЗДДС") directly into PDF templates and task notes. Two problems:

1. **Legally wrong.** "Art. 96 ЗДДС" is the mandatory-registration threshold
   rule — it is never printed on an invoice. The correct citation for a
   non-VAT-registered tenant's invoice is **"чл. 113, ал. 9 ЗДДС"**.
2. **Not EU-scalable.** The product targets the entire EU. Every country has
   its own legal basis for each VAT scenario. Hardcoding Bulgaria blocks
   onboarding any other jurisdiction.

Phase A builds the **data-driven foundation** that later phases will render
from: a tenant-scoped `vat_legal_references` table, a resolver model, and a
seeder with the correct Bulgarian references. Phase A touches **no** UI,
**no** service logic, **no** PDF templates, and does **not** change the
`VatScenario` enum. Its only job is to make the lookup table exist and
return correct data.

Phase B (invoice blocks + `DomesticExempt` scenario) and Phase C (credit/
debit notes) depend on this foundation.

## Scope

1. Tenant migration creating `vat_legal_references`.
2. `VatLegalReference` Eloquent model with translatable description and a
   strict `resolve()` contract.
3. `VatLegalReferencesSeeder` with 16 Bulgarian rows covering all current
   scenarios plus `domestic_exempt` (Art. 39–49) and a goods/services split
   for EU B2B reverse charge and non-EU export.
4. Wire the seeder into `TenantOnboardingService` **and**
   `TenantTemplateManager` (both `recreateTemplate()` and `currentHash()`).
5. Correct the three markdown files that cite "Art. 96 ЗДДС" to
   "чл. 113, ал. 9 ЗДДС".
6. Tests covering migration, seeder idempotency, resolver fallback,
   translations, and onboarding integration.

Out of scope for Phase A: enum changes, invoice/credit/debit note forms,
services, PDFs, UI blocks, `DomesticExempt` wiring — all deferred to B/C.

## Critical Files

### Create

- `database/migrations/tenant/2026_04_17_200025_create_vat_legal_references_table.php`
- `app/Models/VatLegalReference.php`
- `database/seeders/VatLegalReferencesSeeder.php`
- `tests/Feature/Tenant/VatLegalReferencesSeederTest.php`
- `tests/Unit/Models/VatLegalReferenceTest.php`

### Modify

- `app/Services/TenantOnboardingService.php` — register seeder after
  `EuCountryVatRatesSeeder` (non-testing branch only).
- `tests/Support/TenantTemplateManager.php` — run seeder in
  `recreateTemplate()` **and** add path to `currentHash()` seeder list.
- `tasks/vat-vies/spec.md` line 278 — replace "Art. 96 ЗДДС" with
  "чл. 113, ал. 9 ЗДДС".
- `tasks/vat-vies/blocks.md` line 20 — same correction + update
  "Art. 96" mention in the open-question text.
- `tasks/vat-vies/blocks-credit-debit.md` lines 59 and 110 — same.

### Patterns to Reuse

- `app/Models/VatRate.php` — existing country-scoped tenant model with
  `scopeForCountry()` / `scopeOfType()`. Mirror its scope style.
- `app/Support/EuCountries.php` — use `EuCountries::codes()` for future
  multi-country seeds; BG is in that list. (Phase A seeds only BG.)
- `spatie/laravel-translatable` via `HasTranslations` trait — already used
  elsewhere in the app; use the same pattern (`$translatable` array, `json`
  column, **no** `cast` to array).
- `database/seeders/VatRateSeeder.php` — idempotent `updateOrCreate` pattern
  keyed on the unique tuple. Follow this exactly.

## Migration

```php
Schema::create('vat_legal_references', function (Blueprint $table) {
    $table->id();
    $table->char('country_code', 2)->index();
    $table->string('scenario');                // VatScenario value
    $table->string('sub_code')->default('default'); // never NULL
    $table->string('legal_reference');         // e.g. "чл. 39 ЗДДС"
    $table->json('description');               // translatable {en, bg, ...}
    $table->integer('display_order')->default(0);
    $table->boolean('is_default')->default(false);
    $table->timestamps();

    $table->unique(
        ['country_code', 'scenario', 'sub_code'],
        'vat_legal_refs_country_scenario_sub_unique'
    );
});
```

Rationale:
- `sub_code` uses sentinel `'default'` (never NULL) because PostgreSQL
  treats NULLs as distinct in unique indexes — NULL would let duplicates
  through.
- `description` is `json`, **not** `text` with an `array` cast, because
  `HasTranslations` handles the JSON encoding. Double-encoding would break
  translation lookups.
- `is_default` marks the row the UI should preselect when the scenario has
  multiple variants (e.g. `domestic_exempt` → Art. 39 preselected).

## Model

```php
namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class VatLegalReference extends Model
{
    use HasFactory, HasTranslations;

    public array $translatable = ['description'];

    protected $fillable = [
        'country_code',
        'scenario',
        'sub_code',
        'legal_reference',
        'description',
        'display_order',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
            'is_default' => 'boolean',
            // NOTE: no 'description' cast — HasTranslations owns it.
        ];
    }

    public function scopeForCountry($q, string $countryCode)
    {
        return $q->where('country_code', strtoupper($countryCode));
    }

    public function scopeOfScenario($q, string $scenario)
    {
        return $q->where('scenario', $scenario);
    }

    /**
     * Resolve a single legal reference row.
     *
     * Lookup order:
     *   1. exact (country, scenario, sub_code)
     *   2. fallback (country, scenario, 'default')
     *   3. throw DomainException — never silent-null.
     *
     * Scenarios WITHOUT a 'default' row (eu_b2b_reverse_charge, non_eu_export)
     * REQUIRE callers to pass an explicit sub_code; otherwise step 2 also
     * misses and the call throws. This is intentional — the caller must
     * decide goods vs. services.
     */
    public static function resolve(
        string $countryCode,
        string $scenario,
        string $subCode = 'default',
    ): self {
        $country = strtoupper($countryCode);

        $row = static::query()
            ->forCountry($country)
            ->ofScenario($scenario)
            ->where('sub_code', $subCode)
            ->first();

        if ($row !== null) {
            return $row;
        }

        if ($subCode !== 'default') {
            $row = static::query()
                ->forCountry($country)
                ->ofScenario($scenario)
                ->where('sub_code', 'default')
                ->first();

            if ($row !== null) {
                return $row;
            }
        }

        throw new DomainException(
            "No VAT legal reference for country={$country} scenario={$scenario} sub_code={$subCode}"
        );
    }

    /**
     * List all references for a (country, scenario) pair, ordered for UI
     * display (e.g. populating the Art. 39–49 dropdown).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, self>
     */
    public static function listForScenario(
        string $countryCode,
        string $scenario,
    ) {
        return static::query()
            ->forCountry($countryCode)
            ->ofScenario($scenario)
            ->orderBy('display_order')
            ->orderBy('sub_code')
            ->get();
    }
}
```

## Seeder — 16 Bulgarian Rows

Idempotent: `updateOrCreate` keyed on
`['country_code', 'scenario', 'sub_code']`.

| # | scenario              | sub_code  | legal_reference                                          | is_default | display_order |
|---|-----------------------|-----------|----------------------------------------------------------|------------|---------------|
| 1 | `exempt`              | `default` | чл. 113, ал. 9 ЗДДС                                      | true       | 0             |
| 2 | `domestic_exempt`     | `art_39`  | чл. 39 ЗДДС                                              | true       | 10            |
| 3 | `domestic_exempt`     | `art_40`  | чл. 40 ЗДДС                                              | false      | 20            |
| 4 | `domestic_exempt`     | `art_41`  | чл. 41 ЗДДС                                              | false      | 30            |
| 5 | `domestic_exempt`     | `art_42`  | чл. 42 ЗДДС                                              | false      | 40            |
| 6 | `domestic_exempt`     | `art_43`  | чл. 43 ЗДДС                                              | false      | 50            |
| 7 | `domestic_exempt`     | `art_44`  | чл. 44 ЗДДС                                              | false      | 60            |
| 8 | `domestic_exempt`     | `art_45`  | чл. 45 ЗДДС                                              | false      | 70            |
| 9 | `domestic_exempt`     | `art_46`  | чл. 46 ЗДДС                                              | false      | 80            |
| 10| `domestic_exempt`     | `art_47`  | чл. 47 ЗДДС                                              | false      | 90            |
| 11| `domestic_exempt`     | `art_48`  | чл. 48 ЗДДС                                              | false      | 100           |
| 12| `domestic_exempt`     | `art_49`  | чл. 49 ЗДДС                                              | false      | 110           |
| 13| `eu_b2b_reverse_charge` | `goods`   | Art. 138 Directive 2006/112/EC                         | true       | 10            |
| 14| `eu_b2b_reverse_charge` | `services`| Art. 44 + 196 Directive 2006/112/EC                    | false      | 20            |
| 15| `non_eu_export`       | `goods`   | Art. 146 Directive 2006/112/EC                           | true       | 10            |
| 16| `non_eu_export`       | `services`| Art. 44 Directive 2006/112/EC (outside scope of EU VAT) | false      | 20            |

Notes:
- Row 1 uses **"чл. 113, ал. 9 ЗДДС"** — the correct legal basis for
  non-VAT-registered issuers. NOT Art. 96.
- Rows 2–12 describe each Art. 39–49 case. Descriptions should be concise
  Bulgarian titles of each article (e.g. "Доставки, свързани със
  здравеопазване" for Art. 39). English descriptions translate the same.
- Rows 13 and 14 split EU B2B reverse charge by supply type. Row 14's
  description notes "customer accounts for VAT under reverse charge".
- Row 16 is the "outside scope" case: services to non-EU customers fall
  under the Art. 44 place-of-supply rule and are legally **outside** EU
  VAT scope, not "exempt". The description and legal_reference must convey
  that distinction; Phase B/C render it.
- Scenarios `domestic` and `eu_b2c_under_threshold` get no rows — they
  carry VAT at the rate already stored on the invoice and need no legal
  notice.
- `eu_b2c_over_threshold` is OSS-handled and also prints standard VAT; no
  legal notice row for Phase A. (Phase B revisits if needed.)

Seeder shape:

```php
class VatLegalReferencesSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'country_code' => 'BG',
                'scenario' => 'exempt',
                'sub_code' => 'default',
                'legal_reference' => 'чл. 113, ал. 9 ЗДДС',
                'description' => [
                    'en' => 'Issuer not registered for VAT',
                    'bg' => 'Лицето не е регистрирано по ЗДДС',
                ],
                'is_default' => true,
                'display_order' => 0,
            ],
            // ... 15 more rows
        ];

        foreach ($rows as $row) {
            VatLegalReference::updateOrCreate(
                [
                    'country_code' => $row['country_code'],
                    'scenario' => $row['scenario'],
                    'sub_code' => $row['sub_code'],
                ],
                $row,
            );
        }
    }
}
```

If mass-assignment of the translations array misbehaves under
`HasTranslations`, split into two calls: create/update scalar fields, then
`$model->setTranslations('description', $row['description'])->save()`.

## Onboarding + Template Integration

`app/Services/TenantOnboardingService.php` — add after
`EuCountryVatRatesSeeder::class`:

```php
$this->runSeeder(VatLegalReferencesSeeder::class);
```

Inside the `! app()->environment('testing')` branch so tests still rely on
the pre-seeded template DB.

`tests/Support/TenantTemplateManager.php` — **two** changes, both required:

1. In `recreateTemplate()` after `app(EuCountryVatRatesSeeder::class)->run();`:
   ```php
   app(VatLegalReferencesSeeder::class)->run();
   ```
2. In `currentHash()`, extend the `$seederFiles` list:
   ```php
   database_path('seeders/VatLegalReferencesSeeder.php'),
   ```

**Both** changes must land in the same commit. If only (1) lands, the
template won't rebuild when the seeder changes — stale data. If only (2)
lands, the rebuild runs but never seeds — empty table. Either failure mode
produces tests that pass falsely; the combination is load-bearing.

Remember to `use Database\Seeders\VatLegalReferencesSeeder;` at the top of
both files.

## Markdown Corrections

Three files cite "Art. 96 ЗДДС" and must be corrected to
"чл. 113, ал. 9 ЗДДС":

- `tasks/vat-vies/spec.md:278`
- `tasks/vat-vies/blocks.md:20` (plus the "open question" wording)
- `tasks/vat-vies/blocks-credit-debit.md:59`
- `tasks/vat-vies/blocks-credit-debit.md:110`

Also: in `blocks.md`'s "What Needs Investigation" bullet list, the item
about confirming Art. 96 should be removed entirely — Phase A settles it.

## Tests

`tests/Feature/Tenant/VatLegalReferencesSeederTest.php`:

- seeder creates exactly 16 rows for BG after fresh run.
- running the seeder twice still yields 16 rows (idempotent).
- `exempt` row: `legal_reference === 'чл. 113, ал. 9 ЗДДС'`.
- one `domestic_exempt` row per article 39–49 (11 rows total).
- exactly one `is_default = true` row per scenario that has variants
  (`domestic_exempt`, `eu_b2b_reverse_charge`, `non_eu_export`).
- Bulgarian description is present (`getTranslation('description', 'bg')`)
  on every row.

`tests/Unit/Models/VatLegalReferenceTest.php`:

- `resolve('BG', 'exempt')` returns a row with legal_reference
  `'чл. 113, ал. 9 ЗДДС'`.
- `resolve('BG', 'domestic_exempt', 'art_42')` returns the Art. 42 row.
- `resolve('BG', 'domestic_exempt', 'art_999')` throws `DomainException`
  (neither the exact `art_999` row nor a `default` fallback exists for
  this scenario — `domestic_exempt` has no `default` sub_code row).
- `resolve('BG', 'eu_b2b_reverse_charge')` **throws** `DomainException`
  (no `default` sub_code exists).
- `resolve('XX', 'exempt')` throws `DomainException` (unknown country).
- `listForScenario('BG', 'domestic_exempt')` returns 11 rows ordered by
  `display_order` with Art. 39 first.
- `description` translation returns English by default and Bulgarian when
  the app locale is `bg`.

Both test files must run inside tenant context — follow the
`AuthorizedAccessToVatRateTest` pattern (tenant onboarding + `$tenant->run`).

## Verification

1. `./vendor/bin/sail artisan test --parallel --compact --filter=VatLegalReference`
   — new tests green.
2. `./vendor/bin/sail artisan test --parallel --compact` — full suite still
   green, no regressions in existing 554 tests.
3. `./vendor/bin/sail artisan tinker --execute 'tenancy()->initialize(App\Models\Tenant::first()); dump(App\Models\VatLegalReference::count());'`
   — 16 on any tenant onboarded after this change.
4. `vendor/bin/pint --dirty --format agent` — clean.
5. Hash file at `storage/testing/tenant_template.hash` is regenerated
   after first test run post-merge (implicit — observed if the template
   rebuild logs appear).
