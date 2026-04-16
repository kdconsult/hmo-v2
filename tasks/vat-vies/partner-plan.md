# Area 2: Partner VAT Setup — Implementation Plan

> Implementation plan for `tasks/vat-vies/partner.md`. Cross-references spec: `tasks/vat-vies/spec.md` lines 93–142.

## Context

Area 1 (tenant) is done. Area 2 adds VIES-verified VAT to the Partner create/edit flow with a **three-state model**: `not_registered` / `confirmed` / `pending`. Key delta from Area 1: VIES unavailable does NOT reset the toggle — partner is saved as `pending` (treated as non-VAT on invoices until confirmed). Blocking on VIES downtime would halt normal partner creation workflows.

---

## Step 1: `VatStatus` Enum

**File:** `app/Enums/VatStatus.php`

```php
enum VatStatus: string
{
    case NotRegistered = 'not_registered';
    case Confirmed = 'confirmed';
    case Pending = 'pending';
}
```

---

## Step 2: Migration + Factory + Model (atomic)

### 2a. Migration

**File:** `database/migrations/tenant/2026_04_16_200024_add_vat_status_to_partners_table.php`

Adds to `partners`: `is_vat_registered` (bool, default false), `vat_status` (string, default 'not_registered'), `vies_verified_at` (timestamp nullable), `vies_last_checked_at` (timestamp nullable).

**Backfill:** partners with `vat_number IS NOT NULL` and EU `country_code` → set `vat_status = 'confirmed'`, `is_vat_registered = true`.

### 2b. Factory

**File:** `database/factories/PartnerFactory.php`

- Default: `vat_number = null`, `vat_status = NotRegistered`
- `euWithVat($cc)`: adds `vat_status = Confirmed`, `is_vat_registered = true`, `vies_verified_at = now()`
- `vatPending($cc)`: `vat_status = Pending`, `is_vat_registered = true`, `vat_number = null`
- `euWithoutVat($cc)`: adds `vat_status = NotRegistered`

### 2c. Model

**File:** `app/Models/Partner.php`

- New fillable: `is_vat_registered`, `vat_status`, `vies_verified_at`, `vies_last_checked_at`
- New casts: `is_vat_registered => 'boolean'`, `vat_status => VatStatus::class`, timestamps → `'datetime'`
- `hasValidEuVat()` → `return $this->vat_status === VatStatus::Confirmed`
- New `isEligibleForReverseCharge(): bool` — confirmed + EU country_code

---

## Step 3: `PartnerVatService`

**File:** `app/Services/PartnerVatService.php`

**`updateVatRegistration(Partner, array): void`** — three-state logic:
- `is_vat_registered = false` → `not_registered`, clear vat_number + timestamps
- `is_vat_registered = true` + vat_number → `confirmed`, set both timestamps
- `is_vat_registered = true` + no vat_number → `pending`, set `vies_last_checked_at`

**`reVerify(Partner): VatStatus`** — calls `ViesValidationService::validate()`:
- Valid → `confirmed`, store VAT number, update timestamps
- Invalid → `not_registered`, clear vat_number
- Unavailable → leave status unchanged, update `vies_last_checked_at`

---

## Step 4: Unit Tests

**File:** `tests/Unit/PartnerVatServiceTest.php` — 6 tests covering all three update states and all three re-verify outcomes.

---

## Step 5: Trait + Form

### 5a. `HandlesPartnerViesCheck` Trait

**File:** `app/Filament/Resources/Partners/Concerns/HandlesPartnerViesCheck.php`

Used by CreatePartner, EditPartner, and ViewPartner (needed because ViewRecord mounts the form internally).

- `handleViesCheck()` — reads from `$this->data`, validates format, calls VIES:
  - Unavailable → `vat_status = pending`, keep toggle ON (delta from Area 1)
  - Invalid → `is_vat_registered = false`, `vat_status = not_registered`
  - Valid → `vat_number` from VIES, `vat_status = confirmed`, pre-fill name
- `resetVatState()` — clears toggle, vat_number, vat_lookup, vat_status
- `vatCountryPrefix()`, `vatLookupHelperText()` — defensive against null country_code

### 5b. `PartnerForm`

**File:** `app/Filament/Resources/Partners/Schemas/PartnerForm.php`

Changes:
- Remove plain `TextInput::make('vat_number')` from General Info
- Make `country_code` live + `afterStateUpdated` → `resetVatState()`
- Add `Section::make('VAT Registration')` containing:
  - `Hidden::make('vat_status')` — tracks state through Livewire; read by save guard in pages
  - `Toggle::make('is_vat_registered')` — live; OFF → resetVatState
  - `TextInput::make('vat_lookup')` — ephemeral (`dehydrated(false)`), prefix + VIES action
  - `TextInput::make('vat_number')` — disabled + `dehydrated()` (critical for Filament v5)

---

## Step 6: Page Classes

### `CreatePartner.php`

- `use HandlesPartnerViesCheck`
- `beforeCreate()` — save guard: toggle ON + no vat_number + not pending → halt + notification
- `mutateFormDataBeforeCreate()` — strip `vat_lookup`; derive `vat_status` + timestamps from `is_vat_registered` + `vat_number`

### `EditPartner.php`

- `use HandlesPartnerViesCheck`
- `beforeSave()` — same save guard as CreatePartner
- `mutateFormDataBeforeSave()` — strip `vat_lookup`; derive `vat_status` + timestamps (preserves `vies_verified_at` when VAT number unchanged)

### `ViewPartner.php`

- `use HandlesPartnerViesCheck` — needed because ViewRecord mounts the form schema
- "Validate VAT" header action — visible only when `vat_status === confirmed`:
  - Calls `PartnerVatService::reVerify()` + notification
  - Calls `$this->refreshFormData([...])`
- Hidden for `pending` partners (no stored VAT number to re-verify)

---

## Step 7: Feature Tests

**File:** `tests/Feature/PartnerVatSetupTest.php` — 10 Livewire tests:

1. VIES valid → confirmed, vat_number stored, timestamps set
2. VIES invalid → not_registered, fields cleared
3. VIES unavailable → pending saved (toggle stays ON, no vat_number)
4. Save guard: toggle ON, no check → halted
5. Country change → fields reset
6. Edit: confirmed partner loads with vat_number + toggle ON
7. Re-verify: VIES valid → stays confirmed
8. Re-verify: VIES invalid → not_registered, vat_number cleared
9. Validate VAT action hidden for pending partners
10. Pending partner allowed to save without guard block

---

## Files Modified/Created

| Action | File |
|--------|------|
| Create | `app/Enums/VatStatus.php` |
| Create | `database/migrations/tenant/2026_04_16_200024_add_vat_status_to_partners_table.php` |
| Modify | `database/factories/PartnerFactory.php` |
| Modify | `app/Models/Partner.php` |
| Create | `app/Services/PartnerVatService.php` |
| Create | `tests/Unit/PartnerVatServiceTest.php` |
| Create | `app/Filament/Resources/Partners/Concerns/HandlesPartnerViesCheck.php` |
| Modify | `app/Filament/Resources/Partners/Schemas/PartnerForm.php` |
| Modify | `app/Filament/Resources/Partners/Pages/CreatePartner.php` |
| Modify | `app/Filament/Resources/Partners/Pages/EditPartner.php` |
| Modify | `app/Filament/Resources/Partners/Pages/ViewPartner.php` |
| Create | `tests/Feature/PartnerVatSetupTest.php` |
| Modify | `tests/Feature/EuOssTest.php` (2 partners needed `vat_status = confirmed`) |

## Reused Without Changes

- `app/Services/ViesValidationService.php`
- `app/Support/EuCountries.php`
- `app/Enums/VatScenario.php` (calls `hasValidEuVat()` — change is transparent)
