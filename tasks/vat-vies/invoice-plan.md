# Area 3: Invoice VAT Determination — Implementation Plan

## Context

Area 3 completes the VAT/VIES feature by adding VIES re-validation at invoice confirmation time, storing immutable VAT audit data on confirmed invoices, and implementing the full three-way VIES branch UI (valid/invalid/unavailable) on the ViewCustomerInvoice page. Areas 1 (tenant VAT setup) and 2 (partner VAT setup) are already complete (554 tests passing). The current confirmation flow in `ViewCustomerInvoice.php` has a prototype VIES check that needs to be replaced with the full spec from `tasks/vat-vies/spec.md` Area 3.

---

## Step 1: Create new enums

**1a. `app/Enums/ViesResult.php`** — new file
- Cases: `Valid = 'valid'`, `Invalid = 'invalid'`, `Unavailable = 'unavailable'`
- Add `label(): string` method for UI display

**1b. `app/Enums/ReverseChargeOverrideReason.php`** — new file
- Cases: `ViesUnavailable = 'vies_unavailable'` (single value for now; enum is future-proof)
- Add `label(): string` method

---

## Step 2: Migration — add VAT audit columns to `customer_invoices`

**New tenant migration file** via `php artisan make:migration add_vat_audit_columns_to_customer_invoices_table --path=database/migrations/tenant --no-interaction`

> **Important:** This must be a tenant migration (path: `database/migrations/tenant/`). The artisan command won't place it there by default — use `--path` or move after creation.

Add columns:

| Column | Type | Notes |
|--------|------|-------|
| `vat_scenario` | `string()->nullable()` | Frozen VatScenario value at confirmation |
| `vies_request_id` | `string()->nullable()` | From VIES SOAP `requestIdentifier` |
| `vies_checked_at` | `timestamp()->nullable()` | When the confirmation-time VIES check ran |
| `vies_result` | `string()->nullable()` | ViesResult enum value |
| `reverse_charge_manual_override` | `boolean()->default(false)` | True when user opted into RC despite VIES unavailable |
| `reverse_charge_override_user_id` | `foreignId()->nullable()->constrained('users')->nullOnDelete()` | Who approved |
| `reverse_charge_override_at` | `timestamp()->nullable()` | When approved |
| `reverse_charge_override_reason` | `string()->nullable()` | ReverseChargeOverrideReason value |

Add index: `->index('vat_scenario')` for reporting queries.

---

## Step 3: Update `CustomerInvoice` model

**File:** `app/Models/CustomerInvoice.php`

- Add all 8 new columns to `$fillable`
- Add casts:
  - `vat_scenario` → `VatScenario::class`
  - `vies_result` → `ViesResult::class`
  - `reverse_charge_manual_override` → `'boolean'`
  - `vies_checked_at` → `'datetime'`
  - `reverse_charge_override_at` → `'datetime'`
  - `reverse_charge_override_reason` → `ReverseChargeOverrideReason::class`
- Add `overrideUser(): BelongsTo` relationship (→ `User`, FK `reverse_charge_override_user_id`)
- Add to `getActivitylogOptions()` `logOnly`: `vat_scenario`, `reverse_charge_manual_override`

---

## Step 4: Add `VatScenario::Exempt` case

**File:** `app/Enums/VatScenario.php`

- Add case: `Exempt = 'exempt'`
- Add parameter to `determine()`: `bool $tenantIsVatRegistered = true`
- Add short-circuit as FIRST check in `determine()`: `if (!$tenantIsVatRegistered) return self::Exempt`
- Add to `description()`: `'Exempt — tenant is not VAT registered.'`
- Add to `requiresVatRateChange()`: `Exempt` returns `true` (needs zero-rate applied)
- **Keep `$ignorePartnerVat` parameter** — still needed for "Confirm with VAT" path

**Why add Exempt in Area 3 (not defer to Area 4):** Without it, a non-VAT-registered tenant could get `EuB2bReverseCharge` from `determine()`, which is legally wrong. The heavy UI blocks for non-VAT tenants (form field hiding, product restrictions) stay in Area 4 — only the enum case and determine() short-circuit go in here.

---

## Step 5: Update `ViesValidationService`

**File:** `app/Services/ViesValidationService.php`

**5a. Switch from `checkVat` to `checkVatApprox`**

The VIES `checkVat` SOAP operation does NOT return `requestIdentifier`. Only `checkVatApprox` does. Since the spec requires storing `vies_request_id` for audit trail, we must switch.

- `callVies()` now sends `checkVatApprox` with requester info:
  - `requesterCountryCode` — tenant's country code from `CompanySettings::get('company', 'country_code')`
  - `requesterVatNumber` — tenant's VAT number from `tenancy()->tenant->vat_number`
- Update return type: `array{available: bool, valid: bool, name: ?string, address: ?string, country_code: string, vat_number: string, request_id: ?string}`
- Parse `$result->requestIdentifier` from the SOAP response; null when unavailable
- **WSDL note:** `checkVatApprox` may require a different WSDL endpoint (`checkVatApproxService.wsdl` instead of `checkVatService.wsdl`). Verify against the live VIES SOAP endpoint during implementation — inspect the existing WSDL to confirm whether `checkVatApprox` is already defined in it or requires a separate one.

**5b. Add `fresh` parameter to `validate()`**

- New signature: `validate(string $countryCode, string $vatNumber, bool $fresh = false): array`
- When `$fresh = true`: call `Cache::forget($cacheKey)` before the lookup
- Confirmation-time calls pass `fresh: true` to bypass the 24h cache

---

## Step 6: Restructure `CustomerInvoiceService`

**File:** `app/Services/CustomerInvoiceService.php`

### 6a. New public method: `runViesPreCheck(CustomerInvoice $invoice): array`

Runs the VIES re-check and determines the VAT scenario. Has a side effect: VIES-invalid updates the partner immediately.

Logic:
1. Load partner, get tenant country from CompanySettings
2. Check if VIES re-check is needed: `partner.country_code != tenantCountry && isEuCountry(partner.country_code) && vat_status ∈ {Confirmed, Pending}`
3. If no re-check needed → return `['needed' => false, 'scenario' => VatScenario::determine(...), 'preview' => computePreview()]`
4. **Cooldown guard**: if `partner.vies_last_checked_at > now()->subMinute()`, return `['needed' => true, 'result' => 'cooldown', 'retry_after' => ...]` — caller shows "please wait" notification
5. If re-check needed → call `ViesValidationService::validate(fresh: true)`, then update `partner.vies_last_checked_at = now()` regardless of result
   - **Valid**: update partner (`vat_status = Confirmed`, `vies_verified_at = now()`, store `vat_number` if pending→confirmed)
   - **Invalid**: update partner **immediately** (`vat_status = NotRegistered`, `vat_number = null`) — this is a side effect
   - **Unavailable**: no partner change
5. Return: `['needed' => true, 'result' => ViesResult, 'request_id' => string|null, 'checked_at' => Carbon, 'scenario' => VatScenario, 'preview' => [...subtotal, tax, total...]]`

Preview computation: in-memory VAT recalculation using the determined scenario's rates, without DB writes.

### 6b. New public method: `confirmWithScenario()`

```php
public function confirmWithScenario(
    CustomerInvoice $invoice,
    ?array $viesData = null,
    bool $treatAsB2c = false,
    ?ManualOverrideData $override = null,
): void
```

Same work as current `confirm()`:
- SO over-invoice guard
- Transaction: determineVatType → status = Confirmed → SO qty updates → service delivery
- Fiscal receipt dispatch
- OSS accumulation

Additional work:
- Store on invoice: `vat_scenario`, `vies_request_id`, `vies_checked_at`, `vies_result` from `$viesData`
- If `$override` provided: store `reverse_charge_manual_override = true`, `reverse_charge_override_user_id`, `reverse_charge_override_at = now()`, `reverse_charge_override_reason`
- Pass `tenantIsVatRegistered` to `VatScenario::determine()` (from `tenancy()->tenant->is_vat_registered`)
- **Skip OSS accumulation when `vat_scenario === Exempt`** — `EuOssService::accumulate()` does not check tenant VAT registration status; calling it for an exempt tenant would incorrectly record OSS threshold data. Guard with `if ($scenario !== VatScenario::Exempt)` before the `accumulate()` call. Apply the same guard to `reverseAccumulation()` in the `cancel()` method.

### 6c. Keep existing `confirm()` as thin wrapper

```php
public function confirm(CustomerInvoice $invoice, bool $treatAsB2c = false): void
{
    $this->confirmWithScenario($invoice, treatAsB2c: $treatAsB2c);
}
```

This preserves backward compatibility — all 20 existing tests continue to pass unchanged.

### 6d. Update `determineVatType()` (private)

- Accept `bool $tenantIsVatRegistered` parameter, pass to `VatScenario::determine()`
- Handle `VatScenario::Exempt`: resolve zero-rate for tenant country, apply to all items (reuse existing `resolveZeroVatRate()`)
- **Add `Exempt` to the `$targetVatRate = match($scenario)` expression** — place it in the same arm as `EuB2bReverseCharge` and `NonEuExport` (all resolve to zero-rate). Without this, `Exempt` hits the `default` arm and throws `LogicException`.
- Store `vat_scenario` on the invoice inside this method

### 6e. Create `ManualOverrideData` DTO

**New file:** `app/DTOs/ManualOverrideData.php`

```php
readonly class ManualOverrideData
{
    public function __construct(
        public int $userId,
        public ReverseChargeOverrideReason $reason,
    ) {}
}
```

---

## Step 7: Add RBAC permission

**File:** `database/seeders/RolesAndPermissionsSeeder.php`

- Add permission: `override_reverse_charge_customer_invoice`
- Assign to roles: `admin`, `accountant`
- Post-implementation: run `tenants:seed --class=RolesAndPermissionsSeeder` on existing tenants

---

## Step 8: Rewrite `ViewCustomerInvoice` confirmation UI

**File:** `app/Filament/Resources/CustomerInvoices/Pages/ViewCustomerInvoice.php`

### 8a. Livewire state properties

Remove:
- `$viesInvalidDetected`

Add:
- `public ?array $viesPreCheckResult = null` — result from `runViesPreCheck()`
- `public bool $viesUnavailable = false` — triggers inline error state
- `public ?string $lastViesAttemptAt = null` — for retry cooldown display

### 8b. Header actions — replace confirm actions with:

**Action 1: "Confirm Invoice"** — primary trigger
- Visible: Draft status + `!$viesUnavailable`
- On click (`->action()`):
  1. Call `CustomerInvoiceService::runViesPreCheck($record)`
  2. Result dispatches to one of three paths:
     - **No VIES needed** or **VIES valid**: proceed to confirmation (see below)
     - **VIES invalid**: partner already updated by precheck; send danger notification; return (page re-renders; user re-clicks to confirm normally with updated scenario)
     - **VIES unavailable**: set `$viesUnavailable = true`; send warning notification; return
  3. For the "proceed" path: use Filament Action modal with custom `->schema()` showing:
     - VAT scenario badge (Placeholder component)
     - Partner name + VAT number (if reverse charge)
     - VIES reference: request_id + timestamp (if VIES was checked)
     - Financial summary: subtotal / VAT / total
     - Cancel closes modal; Confirm calls `confirmWithScenario()`
  
  **Implementation note:** The exact Filament v5 mechanism for running logic before the modal opens needs to be verified via `search-docs` during implementation. Options: `mountUsing()` hook, two-step action pattern (click → store state → show second action), or a custom Livewire method + `$this->mountAction()`.

**Action 2: "Retry VIES Check"** — VIES unavailable state
- Visible: `$viesUnavailable === true`
- 1-minute cooldown enforced **server-side** in `runViesPreCheck()` using `partner.vies_last_checked_at`: if `< 1 minute ago`, return early with a "please wait" notification instead of calling VIES. No localStorage needed — the column already exists from the Area 2 migration.
- Button disabled state: derive from `$lastViesAttemptAt` Livewire property (set from `partner.vies_last_checked_at`); Alpine.js countdown timer for UX only (re-enable after 60s)
- Action: re-runs `runViesPreCheck()`; updates state based on result

**Action 3: "Confirm with VAT"** — VIES unavailable fallback
- Visible: `$viesUnavailable === true`
- `requiresConfirmation()` — modal warns no reverse charge will apply
- Any user can use this — no permission gate
- Action: `confirmWithScenario(invoice, viesData: [result: Unavailable, ...], treatAsB2c: true)`

**Action 4: "Confirm with Reverse Charge"** — VIES unavailable + confirmed partner
- Visible: `$viesUnavailable === true && partner.vat_status === VatStatus::Confirmed`
- **Permission gated**: `->authorize('override_reverse_charge_customer_invoice')`
- Custom modal `->schema()`: Checkbox "I acknowledge this reverse charge is applied without current VIES verification" — must be checked to submit
- Action: `confirmWithScenario(invoice, viesData: [...], override: new ManualOverrideData(auth()->id(), ViesUnavailable))`

**Remove:** `confirmWithStandardVat` action entirely.

### 8c. Unchanged actions

Edit, Print Invoice, Create Credit Note, Create Debit Note, Cancel — no modifications.

---

## Step 9: Update `CustomerInvoiceForm`

**File:** `app/Filament/Resources/CustomerInvoices/Schemas/CustomerInvoiceForm.php`

### 9a. Partner select `helperText` — update existing

- Pass `tenantIsVatRegistered: tenancy()->tenant->is_vat_registered` to `VatScenario::determine()`
- For `pending` partners: append "VAT status pending — will be verified at confirmation"
- Inline "Re-check VIES" button: defer to follow-up (complex Filament form integration); show text hint pointing to partner view page

### 9b. `pricing_mode` selector — add constraint

- Make reactive to `partner_id` (add `->live()` if not already)
- Use `afterStateUpdated` on `partner_id` or a reactive closure on `pricing_mode`:
  - When selected partner triggers any non-Domestic scenario → force `PricingMode::VatExclusive`, disable selector
  - When Domestic or no partner → enable selector, allow user choice

### 9c. `is_reverse_charge` toggle — make reactive

- Set value based on stored partner data: `VatScenario::determine()` → if `EuB2bReverseCharge` then true, else false
- Remains disabled/read-only (actual value is set at confirmation)

---

## Step 10: Tests

### Existing tests — NO regressions

`tests/Feature/VatDeterminationTest.php` (20 tests) — all continue to pass because `confirm()` remains as a thin wrapper.

### New tests

**10a. Add to `tests/Feature/VatDeterminationTest.php`** — Category A:
- `VatScenario::determine returns Exempt when tenant is not VAT registered`
- `VatScenario::determine checks Exempt before partner logic` (Exempt even with EU B2B confirmed partner)

**10b. New file: `tests/Feature/InvoiceViesConfirmationTest.php`**

*VIES re-check outcomes (mock ViesValidationService):*
1. VIES valid + confirmed partner → stays confirmed, scenario = reverse charge, VIES data stored
2. VIES valid + pending partner → promoted to confirmed, scenario = reverse charge
3. VIES invalid + confirmed partner → downgraded to not_registered, vat_number cleared
4. VIES invalid + pending partner → set to not_registered
5. VIES unavailable + confirmed partner → partner unchanged, unavailable state returned
6. VIES unavailable + pending partner → partner unchanged, unavailable state returned
7. No VIES check for domestic partner
8. No VIES check for non-EU partner
9. No VIES check for not_registered partner

*Confirmation with VIES data:*
10. confirmWithScenario stores vat_scenario on invoice
11. confirmWithScenario stores vies_request_id + vies_checked_at + vies_result
12. vat_scenario is immutable after confirmation (verify frozen value)
13. Confirm with VAT (treatAsB2c) still runs OSS threshold check — stores correct scenario
14. Confirm with Reverse Charge override stores audit trail columns
15. Override without `override_reverse_charge_customer_invoice` permission is denied

*Pricing mode constraint:*
16. Non-domestic scenario forces pricing_mode to VatExclusive on form
17. Domestic scenario allows pricing_mode choice

*Exempt short-circuit:*
18. Non-VAT-registered tenant: confirm stores vat_scenario = exempt
19. Non-VAT-registered tenant: no VIES check runs regardless of partner
20. Non-VAT-registered tenant: is_reverse_charge always false

---

## Step 11: Finalize

- `vendor/bin/pint --dirty --format agent`
- `./vendor/bin/sail artisan test --parallel --compact`
- Update `tasks/vat-vies/invoice.md` checklist

---

## Design Decision Log

- **Retry cooldown**: Spec originally said `localStorage` only. Changed to server-side enforcement using existing `partner.vies_last_checked_at` column (from Area 2 migration). Alpine.js countdown is UX-only; the actual 1-minute gate lives in `runViesPreCheck()`. Rationale: VIES calls go through the server anyway, and the column already exists.

---

## Files Summary

| File | Action | Notes |
|------|--------|-------|
| `app/Enums/ViesResult.php` | **Create** | 3 cases: valid/invalid/unavailable |
| `app/Enums/ReverseChargeOverrideReason.php` | **Create** | 1 case: vies_unavailable |
| `app/DTOs/ManualOverrideData.php` | **Create** | Readonly DTO (userId + reason) |
| `database/migrations/tenant/..._add_vat_audit_columns_to_customer_invoices_table.php` | **Create** | 8 new columns on customer_invoices |
| `app/Models/CustomerInvoice.php` | Modify | fillable, casts, overrideUser() relationship |
| `app/Enums/VatScenario.php` | Modify | Add Exempt case + tenantIsVatRegistered param |
| `app/Services/ViesValidationService.php` | Modify | checkVatApprox + fresh param + request_id |
| `app/Services/CustomerInvoiceService.php` | Modify | runViesPreCheck + confirmWithScenario + determineVatType update |
| `app/Filament/Resources/CustomerInvoices/Pages/ViewCustomerInvoice.php` | Modify | Full rewrite of confirmation actions |
| `app/Filament/Resources/CustomerInvoices/Schemas/CustomerInvoiceForm.php` | Modify | Pricing mode constraint + helperText + RC toggle reactive |
| `database/seeders/RolesAndPermissionsSeeder.php` | Modify | Add override_reverse_charge permission |
| `tests/Feature/VatDeterminationTest.php` | Modify | Add 2 Exempt tests |
| `tests/Feature/InvoiceViesConfirmationTest.php` | **Create** | ~20 new tests |
