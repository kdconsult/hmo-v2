# Plan: Legal References Foundation

> **Task:** `tasks/vat-vies/legal-references.md`
> **Spec:** `tasks/vat-vies/spec.md`
> **Review:** `tasks/vat-vies/review.md` (F-001, F-004)
> **Status:** Ready to implement

---

## Prerequisites

- [ ] `hotfix.md` landed (NOT NULL `country_code`, immutability guard). Not strictly required to build the table, but avoids concurrent migrations.
- [ ] Tenant DB is running (PostgreSQL, `hmo-postgres` host per CLAUDE.md)
- [ ] `spatie/laravel-translatable` is installed (already present — used by translatable fields per memory `reference_translatable_setup`)

---

## Step 1 — Migration

**File:** `database/migrations/tenant/{timestamp}_create_vat_legal_references_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vat_legal_references', function (Blueprint $table) {
            $table->id();
            $table->string('country_code', 2)->index();
            $table->string('vat_scenario')->index();
            $table->string('sub_code')->default('default');
            $table->string('legal_reference');
            $table->json('description');
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['country_code', 'vat_scenario', 'sub_code'], 'vat_legal_refs_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vat_legal_references');
    }
};
```

**Notes:**
- `sub_code` defaults to `'default'` — never NULL. PostgreSQL treats NULL as distinct in unique indexes, so a NULL sub_code would allow duplicates.
- `description` is `json`, NOT `text` with array cast — `HasTranslations` handles JSON encoding; double-encoding breaks translation lookup.
- Generate timestamp via `php artisan make:migration create_vat_legal_references_table --path=database/migrations/tenant --no-interaction`.

Run: `./vendor/bin/sail artisan tenants:migrate` (migrates all existing tenants). For fresh tenants, `TenantOnboardingService` handles this automatically.

---

## Step 2 — Model

**File:** `app/Models/VatLegalReference.php`

```php
<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class VatLegalReference extends Model
{
    use HasFactory;
    use HasTranslations;

    protected $fillable = [
        'country_code',
        'vat_scenario',
        'sub_code',
        'legal_reference',
        'description',
        'is_default',
        'sort_order',
    ];

    /** @var array<int, string> */
    public array $translatable = ['description'];

    protected $casts = [
        'is_default' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function scopeForCountry(Builder $query, string $countryCode): Builder
    {
        return $query->where('country_code', strtoupper($countryCode));
    }

    public function scopeOfScenario(Builder $query, string $scenario): Builder
    {
        return $query->where('vat_scenario', $scenario);
    }

    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    /**
     * Resolve a single reference for a scenario. Exact match first,
     * then fallback to sub_code='default'. Throws when neither found.
     */
    public static function resolve(string $countryCode, string $scenario, string $subCode = 'default'): self
    {
        $country = strtoupper($countryCode);

        $exact = static::where([
            'country_code' => $country,
            'vat_scenario' => $scenario,
            'sub_code' => $subCode,
        ])->first();

        if ($exact) {
            return $exact;
        }

        if ($subCode !== 'default') {
            $fallback = static::where([
                'country_code' => $country,
                'vat_scenario' => $scenario,
                'sub_code' => 'default',
            ])->first();

            if ($fallback) {
                return $fallback;
            }
        }

        throw new DomainException(
            "No VAT legal reference for country={$country}, scenario={$scenario}, sub_code={$subCode}"
        );
    }

    /**
     * List references for a scenario, e.g. to populate a Select.
     * Ordered by is_default DESC (defaults first), then sort_order.
     *
     * @return Collection<int, self>
     */
    public static function listForScenario(string $countryCode, string $scenario): Collection
    {
        return static::forCountry($countryCode)
            ->ofScenario($scenario)
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->get();
    }
}
```

**Reuse pattern:** `app/Models/VatRate.php` — same scope structure, same tenant-scoped model pattern. Check sibling before writing.

---

## Step 3 — Factory

**File:** `database/factories/VatLegalReferenceFactory.php`

```php
<?php

namespace Database\Factories;

use App\Models\VatLegalReference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VatLegalReference>
 */
class VatLegalReferenceFactory extends Factory
{
    protected $model = VatLegalReference::class;

    public function definition(): array
    {
        return [
            'country_code' => 'BG',
            'vat_scenario' => 'exempt',
            'sub_code' => 'default',
            'legal_reference' => 'чл. 113, ал. 9 ЗДДС',
            'description' => ['bg' => 'Освободена доставка', 'en' => 'Exempt supply'],
            'is_default' => false,
            'sort_order' => 0,
        ];
    }

    public function domesticExempt(string $article): self
    {
        return $this->state([
            'vat_scenario' => 'domestic_exempt',
            'sub_code' => "art_{$article}",
            'legal_reference' => "чл. {$article} ЗДДС",
        ]);
    }
}
```

---

## Step 4 — Seeder

**File:** `database/seeders/VatLegalReferenceSeeder.php`

Seeds 16 BG rows. Idempotent via `updateOrCreate` keyed on the unique tuple.

```php
<?php

namespace Database\Seeders;

use App\Models\VatLegalReference;
use Illuminate\Database\Seeder;

class VatLegalReferenceSeeder extends Seeder
{
    /**
     * BG legal references. Each row is {country, scenario, sub_code, citation, description, is_default, sort_order}.
     */
    public function run(): void
    {
        $rows = [
            // 1. Exempt — non-VAT-registered tenant
            ['BG', 'exempt', 'default', 'чл. 113, ал. 9 ЗДДС',
                ['bg' => 'Доставки от лице, което не е регистрирано по ЗДДС', 'en' => 'Supply by a person not registered under VAT Act'],
                true, 0],

            // 2..12. DomesticExempt — Art. 39..49 (11 rows)
            ['BG', 'domestic_exempt', 'art_39', 'чл. 39 ЗДДС',
                ['bg' => 'Доставки, свързани със здравеопазване', 'en' => 'Healthcare-related supplies'],
                true, 10],
            ['BG', 'domestic_exempt', 'art_40', 'чл. 40 ЗДДС',
                ['bg' => 'Доставки, свързани със социални грижи и осигуряване', 'en' => 'Social care and social security supplies'],
                false, 20],
            ['BG', 'domestic_exempt', 'art_41', 'чл. 41 ЗДДС',
                ['bg' => 'Доставки, свързани с образование, спорт или физическо възпитание', 'en' => 'Education, sport and physical education supplies'],
                false, 30],
            ['BG', 'domestic_exempt', 'art_42', 'чл. 42 ЗДДС',
                ['bg' => 'Доставки, свързани с култура', 'en' => 'Cultural supplies'],
                false, 40],
            ['BG', 'domestic_exempt', 'art_43', 'чл. 43 ЗДДС',
                ['bg' => 'Доставки, свързани с вероизповедания', 'en' => 'Religious supplies'],
                false, 50],
            ['BG', 'domestic_exempt', 'art_44', 'чл. 44 ЗДДС',
                ['bg' => 'Доставки с нестопански характер', 'en' => 'Non-profit supplies'],
                false, 60],
            ['BG', 'domestic_exempt', 'art_45', 'чл. 45 ЗДДС',
                ['bg' => 'Доставка, свързана със земя и сгради', 'en' => 'Supply of land and buildings'],
                false, 70],
            ['BG', 'domestic_exempt', 'art_46', 'чл. 46 ЗДДС',
                ['bg' => 'Доставка на финансови услуги', 'en' => 'Financial services'],
                false, 80],
            ['BG', 'domestic_exempt', 'art_47', 'чл. 47 ЗДДС',
                ['bg' => 'Доставка на застрахователни услуги', 'en' => 'Insurance services'],
                false, 90],
            ['BG', 'domestic_exempt', 'art_48', 'чл. 48 ЗДДС',
                ['bg' => 'Доставка на хазартни игри', 'en' => 'Gambling services'],
                false, 100],
            ['BG', 'domestic_exempt', 'art_49', 'чл. 49 ЗДДС',
                ['bg' => 'Доставка на пощенски марки и пощенски услуги', 'en' => 'Postal stamps and postal services'],
                false, 110],

            // 13..14. EU B2B reverse charge — goods / services
            ['BG', 'eu_b2b_reverse_charge', 'goods', 'Art. 138 Directive 2006/112/EC',
                ['bg' => 'Вътреобщностна доставка на стоки (обратно начисляване)', 'en' => 'Intra-Community supply of goods (reverse charge)'],
                true, 10],
            ['BG', 'eu_b2b_reverse_charge', 'services', 'Art. 44 + 196 Directive 2006/112/EC',
                ['bg' => 'Доставка на услуги с място на изпълнение в държава-членка на получателя (обратно начисляване)', 'en' => 'Services with place of supply in recipient Member State (reverse charge)'],
                false, 20],

            // 15..16. Non-EU export — goods / services
            ['BG', 'non_eu_export', 'goods', 'Art. 146 Directive 2006/112/EC',
                ['bg' => 'Износ на стоки извън ЕС (нулева ставка)', 'en' => 'Export of goods outside the EU (zero-rated)'],
                true, 10],
            ['BG', 'non_eu_export', 'services', 'Art. 44 Directive 2006/112/EC (outside scope of EU VAT)',
                ['bg' => 'Услуги към получател извън ЕС — извън обхвата на ДДС', 'en' => 'Services to a non-EU customer — outside the scope of EU VAT'],
                false, 20],
        ];

        foreach ($rows as [$country, $scenario, $subCode, $legalRef, $description, $isDefault, $sortOrder]) {
            VatLegalReference::updateOrCreate(
                ['country_code' => $country, 'vat_scenario' => $scenario, 'sub_code' => $subCode],
                [
                    'legal_reference' => $legalRef,
                    'description' => $description,
                    'is_default' => $isDefault,
                    'sort_order' => $sortOrder,
                ]
            );
        }
    }
}
```

**Caveat on translations:** if mass-assignment of the `description` array misbehaves under `HasTranslations`, split into two calls:
```php
$ref = VatLegalReference::updateOrCreate([...unique...], [...scalars except description...]);
$ref->setTranslations('description', $description);
$ref->save();
```
Write a test that catches incorrect JSON encoding (e.g. `assertSame('Healthcare-related supplies', $ref->getTranslation('description', 'en'))`).

---

## Step 5 — Wire into `TenantOnboardingService`

**File:** `app/Services/TenantOnboardingService.php`

Add a call to the seeder in `provisionTenant()` (or equivalent method name — check the current code):

```php
use Database\Seeders\VatLegalReferenceSeeder;

// ... inside tenant provisioning, after initial schema + base seeders:
$tenant->run(function () {
    (new VatLegalReferenceSeeder())->run();
});
```

Locate the existing call to `VatRateSeeder` and mirror its placement. The two seeders should be adjacent — both are VAT-related tenant-scoped reference data.

---

## Step 6 — Wire into `TenantTemplateManager` (BOTH PLACES)

**File:** `tests/Support/TenantTemplateManager.php`

**Place 1:** `recreateTemplate()` — add `VatLegalReferenceSeeder` run.

```php
use Database\Seeders\VatLegalReferenceSeeder;

// ... inside recreateTemplate(), after VatRateSeeder:
(new VatLegalReferenceSeeder())->run();
```

**Place 2:** `currentHash()` — add the seeder file to the hash inputs so the template rebuilds when the seed data changes.

```php
private function currentHash(): string
{
    $files = [
        // ... existing files
        database_path('seeders/VatLegalReferenceSeeder.php'),
    ];
    // ... existing hash logic
}
```

**CRITICAL:** both changes MUST land in the same commit. If only Place 1 lands, the hash doesn't change → tests use an old template missing the rows → silent test failures (empty table). If only Place 2 lands, the template never re-runs the seeder → same result.

---

## Step 7 — Correct stale Art. 96 citations in docs

**Files:** (already handled in `hotfix.md`'s doc-drift bundle, but verify once Phase A data is live)

- `tasks/vat-vies/blocks.md` — any "Art. 96 ЗДДС" → "чл. 113, ал. 9 ЗДДС"
- `tasks/vat-vies/blocks-credit-debit.md` — same
- `tasks/vat-vies/spec.md` — Area 4 PDF notice line → already updated

Cross-reference added: `[review.md#f-004]`.

---

## Tests

**File:** `tests/Unit/VatLegalReferenceTest.php`

```php
use App\Models\VatLegalReference;
use Database\Seeders\VatLegalReferenceSeeder;
use function Pest\Laravel\artisan;

beforeEach(function () {
    tenancy()->initialize($this->tenant);
    (new VatLegalReferenceSeeder())->run();
});

it('resolves exact match by country + scenario + sub_code', function () {
    $ref = VatLegalReference::resolve('BG', 'eu_b2b_reverse_charge', 'goods');
    expect($ref->legal_reference)->toBe('Art. 138 Directive 2006/112/EC');
});

it('falls back to default sub_code when exact not found', function () {
    // Exempt has only 'default' — asking for any sub_code falls through
    $ref = VatLegalReference::resolve('BG', 'exempt', 'nonexistent');
    expect($ref->legal_reference)->toBe('чл. 113, ал. 9 ЗДДС');
});

it('throws DomainException when neither exact nor default exists', function () {
    expect(fn () => VatLegalReference::resolve('BG', 'eu_b2b_reverse_charge', 'nonexistent'))
        ->toThrow(DomainException::class);
});

it('returns default row first from listForScenario', function () {
    $list = VatLegalReference::listForScenario('BG', 'domestic_exempt');
    expect($list->first()->sub_code)->toBe('art_39')
        ->and($list->first()->is_default)->toBeTrue();
});

it('seeds exactly 16 BG rows', function () {
    expect(VatLegalReference::forCountry('BG')->count())->toBe(16);
});

it('is idempotent — running the seeder twice yields 16 rows', function () {
    (new VatLegalReferenceSeeder())->run();
    expect(VatLegalReference::forCountry('BG')->count())->toBe(16);
});

it('handles Bulgarian translation for domestic_exempt', function () {
    $ref = VatLegalReference::resolve('BG', 'domestic_exempt', 'art_39');
    expect($ref->getTranslation('description', 'bg'))->toBe('Доставки, свързани със здравеопазване')
        ->and($ref->getTranslation('description', 'en'))->toBe('Healthcare-related supplies');
});
```

**File:** `tests/Feature/TenantOnboardingSeedsLegalReferencesTest.php`

```php
use App\Models\VatLegalReference;
use App\Services\TenantOnboardingService;

it('a newly onboarded tenant has 16 BG legal references', function () {
    $tenant = app(TenantOnboardingService::class)->provisionTenant(/* minimal args */);

    $tenant->run(function () {
        expect(VatLegalReference::count())->toBe(16);
    });
});
```

**File:** `tests/Feature/TenantTemplateManagerHashTest.php` (regression for the two-places bug)

```php
it('template hash includes VatLegalReferenceSeeder file', function () {
    $hash = app(TenantTemplateManager::class)->currentHash();
    // Hash changes when seeder file is modified — can't assert exact value,
    // but can assert the seeder is listed among hash inputs.
    $files = app(TenantTemplateManager::class)->hashInputFiles();
    expect($files)->toContain(database_path('seeders/VatLegalReferenceSeeder.php'));
});
```

(The `hashInputFiles()` accessor may need to be added if not present — small refactor.)

---

## Gotchas / load-bearing details

1. **Sub-code sentinel, not NULL.** Postgres unique index treats NULL as distinct. Using `'default'` makes the unique constraint actually prevent duplicates. `[review.md#f-004]` discussion thread.
2. **Two-place template manager wiring.** If you only update one, tests silently use the wrong data. Commit them together; don't split.
3. **Translations via HasTranslations, not array cast.** Spatie's trait handles JSON encode/decode; double-casting breaks it.
4. **`is_default` drives UI preselection**, not the resolver. The resolver always needs an explicit sub_code — `is_default` is a UI hint for `Select` components.
5. **Services vs goods split for EU B2B and Non-EU Export.** The seeder has both rows; `is_default = true` is on `goods` (statistically more common for BG SMEs per plan author). Downstream forms will pick the default but let user override.
6. **Future expansion:** per-tenant countries (DE, FR, …) will need their own seed rows. Structure the seeder so new-country-seed calls are additive. Do NOT assume BG is the only country at consumer sites.

---

## Exit Criteria

- [ ] All tests pass: `./vendor/bin/sail artisan test --parallel --compact --filter=VatLegalReference`
- [ ] `./vendor/bin/sail artisan tenants:seed --class=VatLegalReferenceSeeder` runs cleanly on an existing tenant
- [ ] A fresh tenant created via `TenantOnboardingService` has exactly 16 BG rows
- [ ] Template hash test passes
- [ ] Pint clean: `vendor/bin/pint --dirty --format agent`
- [ ] Checklist in `legal-references.md` fully ticked

---

## Follow-ups (handled in downstream tasks)

- PDF rendering of the resolved reference → `pdf-rewrite.md`
- Form toggle for DomesticExempt sub-code selection → `domestic-exempt.md`
- Block behaviour for non-VAT-registered tenants reading `exempt` row → `blocks.md`
- Non-BG country seed expansion → backlog
