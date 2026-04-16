# Plan: Area 1 — Tenant Company VAT Setup

## Context

When `is_vat_registered = false` on the tenant, the tenant cannot legally charge VAT, apply reverse charge, or participate in OSS. This task adds VIES-verified VAT registration to the Company Settings page, enforcing that VAT numbers come exclusively from VIES — never from user input. This is the foundation for Areas 2–4 (partner VAT, invoice VAT determination, non-VAT blocks).

**Spec:** `tasks/vat-vies/spec.md` — Area 1
**Task:** `tasks/vat-vies/tenant.md`

---

## Storage Decision

VAT fields go on the **`tenants` table (central DB)**, not the `company_settings` KV store.

- `vat_number` already lives there
- Legal identity fields, not settings — invariant enforcement needs a real model
- Accessible from tenant context via `tenancy()->tenant`
- `country_code` remains dual-stored: `tenants.country_code` (central) + `company_settings.company.country_code` (tenant KV, used by invoice flows)

---

## Step 1: Migration (central DB)

**New file:** `database/migrations/2026_04_16_000001_add_vat_registration_to_tenants_table.php`

```
php artisan make:migration add_vat_registration_to_tenants_table --no-interaction
```

Add to `tenants` table:
- `is_vat_registered` — `boolean`, default `false`, after `vat_number`
- `vies_verified_at` — `nullable timestamp`, after `is_vat_registered`

Down: drop both columns.

---

## Step 2: Tenant Model

**File:** `app/Models/Tenant.php`

- Add `'is_vat_registered'` and `'vies_verified_at'` to `getCustomColumns()` array (after `'vat_number'`)
- Add to `$casts`:
  ```php
  'is_vat_registered' => 'boolean',
  'vies_verified_at' => 'datetime',
  ```

---

## Step 3: TenantFactory

**File:** `database/factories/TenantFactory.php`

- Add `'is_vat_registered' => false` and `'vies_verified_at' => null` to `definition()`
- Add state method:
  ```php
  public function vatRegistered(): static
  {
      return $this->state(fn () => [
          'is_vat_registered' => true,
          'vat_number' => 'BG' . fake()->numerify('#########'),
          'vies_verified_at' => now(),
      ]);
  }
  ```

---

## Step 4: CompanyVatService

**New file:** `app/Services/CompanyVatService.php`

Single write-path for tenant VAT fields. Enforces the spec invariant at the service layer.

```php
class CompanyVatService
{
    /**
     * Update VAT registration state on the tenant.
     *
     * Enforces: is_vat_registered = true ↔ vat_number IS NOT NULL
     *
     * @param array{
     *   is_vat_registered: bool,
     *   vat_number: ?string,
     *   country_code: string,
     *   company_name: ?string,
     *   address: ?string,
     * } $data
     */
    public function updateVatRegistration(Tenant $tenant, array $data): void
```

Logic:
- If `is_vat_registered = true` and `vat_number` is null → throw `\InvalidArgumentException`
- If `is_vat_registered = false` → force `vat_number = null`, `vies_verified_at = null`
- Always update `country_code` on the tenant model (central DB side of dual-write)
- Optionally update `name` if provided and non-empty
- `$tenant->save()`

---

## Step 5: CompanySettingsPage Changes

**File:** `app/Filament/Pages/CompanySettingsPage.php`

This is the largest change. Three areas: `mount()`, form schema, `save()`.

### 5a. mount() — Load VAT state from tenant model

After loading KV groups, merge tenant model data:

```php
$tenant = tenancy()->tenant;

// Fall back to tenant model if KV store has no country_code yet
$companyGroup = CompanySettings::getGroup('company');
if (empty($companyGroup['country_code'])) {
    $companyGroup['country_code'] = $tenant->country_code;
}

$this->form->fill([
    'general' => CompanySettings::getGroup('general'),
    'company' => $companyGroup,
    // ... existing groups ...
    'vat' => [
        'is_vat_registered' => $tenant->is_vat_registered,
        'vat_number' => $tenant->vat_number,
        'vat_lookup' => '',  // placeholder — never loaded from DB
    ],
]);
```

### 5b. Form schema — VAT Section in General tab

Add a **Section** titled "VAT Registration" inside the General tab, after the existing fields.

**Country select** (already exists at `company.country_code`):
- Add `->live()`
- Add `afterStateUpdated`:
  ```php
  ->afterStateUpdated(function (?string $state, Set $set) {
      // Reset VAT state when country changes (Principle 7)
      $set('vat.is_vat_registered', false);
      $set('vat.vat_number', null);
      $set('vat.vat_lookup', '');
  })
  ```

**New fields in the VAT Section** (all under `vat.*` statePath):

1. **Toggle** `vat.is_vat_registered`
   - `Filament\Forms\Components\Toggle`
   - Label: "Company is VAT Registered"
   - `->live()`
   - `->afterStateUpdated`: when toggled OFF, clear `vat.vat_number` and `vat.vat_lookup`
   - `->inline(false)`

2. **TextInput** `vat.vat_lookup` (placeholder field — never persisted)
   - `Filament\Forms\Components\TextInput`
   - Label: "VAT Number Lookup"
   - `->prefix(fn (Get $get) => EuCountries::vatPrefixForCountry($get('../../company.country_code')) ?? 'BG')`
     - Note: `$get` path must traverse up from `vat.*` to reach `company.*`. If relative paths don't work, fall back to reading `$this->data['company']['country_code']` in a Livewire method.
   - `->visible(fn (Get $get) => (bool) $get('is_vat_registered'))` — only show when toggle is ON
   - `->helperText(fn (Get $get) => EuCountries::vatNumberExample($get('../../company.country_code') ?? 'BG') ? 'Format: ' . EuCountries::vatNumberExample($get('../../company.country_code') ?? 'BG') : null)`
   - `->belowContent(...)` with VIES check action (see below)
   - Validation: pattern-match via `EuCountries::vatNumberRegex()` against the full concatenated value (prefix + input)

3. **VIES Check Action** (belowContent on `vat.vat_lookup`):
   ```php
   ->belowContent(Schema::end([
       Icon::make(Heroicon::InformationCircle),
       'Validate against EU VIES database',
       Action::make('check_vies')
           ->label('Check VIES')
           ->rateLimit(5)
           ->action(fn () => $this->handleViesCheck()),
   ]))
   ```
   - Use a **Livewire method** (`$this->handleViesCheck()`), NOT cross-group `$set`/`$get`, because crossing from `vat.*` to `general.*` in `$set` is fragile.

4. **TextInput** `vat.vat_number` (locked display field)
   - `Filament\Forms\Components\TextInput`
   - Label: "Confirmed VAT Number"
   - `->disabled()` — never editable
   - `->visible(fn (Get $get) => filled($get('vat_number')))` — only show when confirmed
   - Shows the VIES-confirmed canonical VAT number

### 5c. handleViesCheck() — Livewire method

```php
public function handleViesCheck(): void
{
    $countryCode = data_get($this->data, 'company.country_code');
    $lookupValue = data_get($this->data, 'vat.vat_lookup');

    if (blank($lookupValue)) {
        Notification::make()->warning()->title('Enter a VAT number first')->send();
        return;
    }

    $prefix = EuCountries::vatPrefixForCountry($countryCode) ?? $countryCode;
    $fullVat = $prefix . $lookupValue;

    // Pattern validation before calling VIES
    $regex = EuCountries::vatNumberRegex($countryCode);
    if ($regex && !preg_match($regex, strtoupper($fullVat))) {
        Notification::make()->danger()->title('Invalid VAT number format')
            ->body('Expected format: ' . EuCountries::vatNumberExample($countryCode))
            ->send();
        return;
    }

    $result = app(ViesValidationService::class)->validate($prefix, $lookupValue);

    if (!$result['available']) {
        // Principle 4: unavailable = invalid
        data_set($this->data, 'vat.is_vat_registered', false);
        data_set($this->data, 'vat.vat_number', null);
        Notification::make()->danger()
            ->title('VIES service is unreachable')
            ->body('Please try again later.')
            ->send();
        return;
    }

    if (!$result['valid']) {
        data_set($this->data, 'vat.is_vat_registered', false);
        data_set($this->data, 'vat.vat_number', null);
        Notification::make()->warning()
            ->title('VAT number is not valid')
            ->body("Checked: {$fullVat}")
            ->send();
        return;
    }

    // Valid — store VIES-confirmed VAT number
    $confirmedVat = $prefix . ($result['vat_number'] ?? $lookupValue);
    data_set($this->data, 'vat.vat_number', $confirmedVat);
    data_set($this->data, 'vat.is_vat_registered', true);

    // Pre-fill company name + address from VIES (editable)
    if (filled($result['name'])) {
        data_set($this->data, 'general.company_name', $result['name']);
    }
    // Address: best-effort fill into address field
    if (filled($result['address'])) {
        // Could parse into address_line_1 — or leave for user to edit
    }

    Notification::make()->success()
        ->title('VAT registration confirmed')
        ->body($confirmedVat)
        ->send();
}
```

### 5d. save() — Extract VAT group before KV loop

```php
public function save(): void
{
    $this->authorize('update', CompanySettings::class);

    // --- Save guard: toggle ON + no confirmed VAT → block ---
    $vatData = $this->data['vat'] ?? [];
    if (($vatData['is_vat_registered'] ?? false) && blank($vatData['vat_number'] ?? null)) {
        Notification::make()->danger()
            ->title('VAT verification required')
            ->body('You must verify your VAT number via VIES before saving.')
            ->send();
        $this->halt();  // If halt() not available, use return
        return;
    }

    // --- Write VAT fields to tenant model (central DB) ---
    $tenant = tenancy()->tenant;
    app(CompanyVatService::class)->updateVatRegistration($tenant, [
        'is_vat_registered' => (bool) ($vatData['is_vat_registered'] ?? false),
        'vat_number' => $vatData['vat_number'] ?? null,
        'country_code' => $this->data['company']['country_code'] ?? $tenant->country_code,
    ]);

    // --- Write KV settings (excluding 'vat' pseudo-group) ---
    foreach ($this->data as $group => $settings) {
        if ($group === 'vat') {
            continue;  // Already handled above
        }
        if (is_array($settings)) {
            foreach ($settings as $key => $value) {
                CompanySettings::set($group, $key, is_array($value) ? json_encode($value) : ($value ?? ''));
            }
        }
    }

    Notification::make()->success()->title('Settings saved')->send();
}
```

### 5e. New imports needed

```php
use App\Services\CompanyVatService;
use App\Services\ViesValidationService;
use App\Support\EuCountries;
use Filament\Actions\Action;
use Filament\Schemas\Components\Icon;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
```

Note: `EuCountries`, `Action`, `Select` are already imported. Only add missing ones.

---

## Step 6: Tests

### 6a. Unit: CompanyVatService

**New file:** `tests/Unit/CompanyVatServiceTest.php`

```
php artisan make:test --pest --unit CompanyVatServiceTest --no-interaction
```

4 tests, mocking the Tenant model:

1. **registers VAT** — `is_vat_registered=true` + valid `vat_number` → tenant saved correctly, `vies_verified_at` set
2. **deregisters VAT** — `is_vat_registered=false` → `vat_number` and `vies_verified_at` nulled
3. **invariant enforced** — `is_vat_registered=true` + `vat_number=null` → throws `InvalidArgumentException`
4. **country change updates tenant** — `country_code` in data updates `tenants.country_code`

### 6b. Feature: CompanySettingsPage VAT flow

**New file:** `tests/Feature/CompanyVatSetupTest.php`

```
php artisan make:test --pest CompanyVatSetupTest --no-interaction
```

Uses Livewire test helper. Must mock `ViesValidationService` in the container.

Set up: create tenant + user via factories, `$tenant->run()` context, `Filament::setCurrentPanel('admin')`, `actingAs($tenantUser)`.

4 tests:

1. **Happy path** — Toggle ON → fill lookup → VIES returns valid → save → assert `tenants.is_vat_registered = true`, `tenants.vat_number` = VIES response value, `tenants.vies_verified_at` not null
2. **VIES invalid** — VIES returns `available=true, valid=false` → assert toggle reset, vat_number null
3. **VIES unavailable** — VIES returns `available=false` → assert same as invalid (Principle 4)
4. **Save guard** — Toggle ON, no VIES check done, attempt save → assert notification with error, `is_vat_registered` stays false in DB

### Mock setup pattern

```php
$mock = Mockery::mock(ViesValidationService::class);
$mock->shouldReceive('validate')->andReturn([
    'available' => true,
    'valid' => true,
    'name' => 'Test Company Ltd',
    'address' => 'Sofia, Bulgaria',
    'country_code' => 'BG',
    'vat_number' => '123456789',
]);
app()->instance(ViesValidationService::class, $mock);
```

---

## Implementation Order

1. Migration + model + factory (Steps 1–3) — can run `migrate` immediately
2. `CompanyVatService` (Step 4) — unit tests first
3. `CompanySettingsPage` changes (Step 5) — mount, form, handleViesCheck, save
4. Feature tests (Step 6b)
5. Run `vendor/bin/pint --dirty --format agent`
6. Run `./vendor/bin/sail artisan test --parallel --compact`
7. Browser test manually

---

## Out of Scope (explicitly deferred)

- **Registration flow** (`app/Livewire/RegisterTenant.php`) stores user-typed VAT numbers directly to `tenants.vat_number` — violates Principle 5. Must be fixed in a separate task. Note in `tenant.md` refactor findings.
- **Onboarding wizard** — no VIES check during registration for now
- **`company_settings.company.country_code` initial seeding** — `TenantOnboardingService` doesn't seed this KV key. The `mount()` fallback to `tenancy()->tenant->country_code` handles it. Could be improved later.
- **Area 4 blocks** (non-VAT-registered tenant cascading effects) — separate task (`blocks.md`)

---

## Key Files

| File | Action |
|------|--------|
| `database/migrations/2026_04_16_*_add_vat_registration_to_tenants_table.php` | Create |
| `app/Models/Tenant.php` | Edit (columns + casts) |
| `database/factories/TenantFactory.php` | Edit (defaults + state) |
| `app/Services/CompanyVatService.php` | Create |
| `app/Filament/Pages/CompanySettingsPage.php` | Edit (mount, form, save, handleViesCheck) |
| `app/Services/ViesValidationService.php` | Read only (reuse as-is) |
| `app/Support/EuCountries.php` | Read only (reuse as-is) |
| `tests/Unit/CompanyVatServiceTest.php` | Create |
| `tests/Feature/CompanyVatSetupTest.php` | Create |

---

## Verification

1. `./vendor/bin/sail artisan migrate` — migration runs without error
2. `./vendor/bin/sail artisan test --parallel --compact --filter=CompanyVat` — all 8 tests pass
3. `vendor/bin/pint --dirty --format agent` — clean
4. Browser: navigate to Company Settings → General tab → VAT section → toggle ON → enter VAT lookup → click Check VIES → verify locked field populated → save → reload → confirm persistence
5. Browser: toggle OFF → save → reload → confirm vat_number is null
6. Browser: change country → confirm toggle and VAT field reset
