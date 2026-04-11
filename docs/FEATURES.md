# Features: Registration, Onboarding & Testing

## 1. Self-Registration Flow

### Route & Access
- **Route**: `/register` (guest-only middleware)
- **Component**: `RegisterTenant` Livewire component (v4)
- **Layout**: Guest layout (`components.layouts.guest`)

### Three-Step Wizard

#### Step 1: Account
Collects user account credentials:
- **name** – Full name (required, max 255 chars)
- **email** – Email address (required, unique across users table, email format)
- **password** – Hashed password (required, confirmed, Laravel Password defaults)
- **password_confirmation** – Password confirmation (must match password)

Validation on `nextStep()` enforces all fields, uniqueness of email, and password confirmation.

#### Step 2: Organization
Collects company and localization details:
- **company_name** – Legal company name (required, max 255 chars)
- **country_code** – ISO 3166-1 alpha-2 code (required, 2-char, chosen from EuCountries)
- **vat_number** – VAT number (optional, max 20 chars)
- **eik** – Company/registration number (optional, max 20 chars)
- **currency_code** – Auto-filled from country (read-only during registration, defaults to EUR)
- **timezone** – Auto-filled from country (read-only during registration)
- **locale** – Auto-filled from country (read-only during registration)

When `country_code` updates (via `updatedCountryCode`), the component automatically populates `currency_code`, `timezone`, and `locale` by calling `EuCountries::get()`.

#### Step 3: Plan
Selects a subscription plan:
- **plan_id** – Plan ID (required, must exist in plans table)
- Pre-selected to the free plan on mount

All plans are queried with `Plan::where('is_active', true)` and sorted by `sort_order`.

### Submit & Tenant Creation

On `submit()`:

1. **Create central User**:
   ```php
   User::create([
       'name' => $this->name,
       'email' => $this->email,
       'password' => Hash::make($this->password),
   ]);
   ```

2. **Generate unique slug** via `Tenant::generateUniqueSlug()` (see Auto-Generated Subdomain section)

3. **Create Tenant** in central database:
   ```php
   Tenant::create([
       'name' => $this->company_name,
       'slug' => $slug,
       'email' => $this->email,
       'country_code' => $this->country_code,
       'locale' => $this->locale,
       'timezone' => $this->timezone,
       'default_currency_code' => $this->currency_code,
       'vat_number' => $this->vat_number ?: null,
       'eik' => $this->eik ?: null,
       'plan_id' => $this->plan_id,
       'subscription_status' => SubscriptionStatus::Trial->value,
       'trial_ends_at' => now()->addDays(14),
   ]);
   ```

4. **Create domain** linking slug to app domain:
   ```php
   $tenant->domains()->create([
       'domain' => "{$slug}.{$appDomain}",
   ]);
   ```
   where `$appDomain = last(config('tenancy.central_domains'))`

5. **Attach user to tenant** in central pivot:
   ```php
   $tenant->users()->attach($user->id);
   ```

6. **Onboard the tenant** (see Tenant Onboarding Service section)

7. **Send welcome email** via `WelcomeTenant` mailable to the user's email

8. **Redirect** to tenant's admin panel: `http://{$slug}.{$appDomain}/admin`

---

## 2. Tenant Onboarding Service

**Namespace**: `App\Services\TenantOnboardingService`  
**Method**: `onboard(Tenant $tenant, User $ownerUser): void`

Runs within tenant context via `$tenant->run()` callback to ensure all database writes target the tenant's database.

### Seeds Applied (in order)

1. **RolesAndPermissionsSeeder** – Creates roles (admin, sales-manager, viewer) and permissions
2. **CurrencySeeder** – Seeds currencies; EUR is marked as default (`is_default = true`)
3. **VatRateSeeder** – Seeds VAT rates for the country

### TenantUser & Role Assignment

- Creates or retrieves TenantUser for the owner:
  ```php
  TenantUser::firstOrCreate(
      ['user_id' => $ownerUser->id],
      ['user_id' => $ownerUser->id],
  );
  ```

- Assigns `admin` role to the owner TenantUser (checks if not already assigned to avoid duplicates)

### Idempotency

The service is idempotent when called multiple times:
- `firstOrCreate` ensures only one TenantUser per owner
- Role assignment includes a `hasRole()` check before assigning

### Integration Points

- Called from `RegisterTenant` component after tenant + domain creation
- Called from `CreateTenant` (Landlord panel) page after creating the tenant manually

---

## 3. Auto-Generated Subdomain

### Algorithm: TenantSlugGenerator

**Namespace**: `App\Support\TenantSlugGenerator`  
**Method**: `generate(): string`

Generates a random slug in the format `{adjective}-{noun}`:

- **40 adjectives**: amber, azure, bold, bright, calm, clear, cool, crisp, deep, fair, fast, firm, fresh, gold, grand, green, keen, kind, light, lush, mild, neat, nova, peak, pure, quick, rich, safe, sharp, slim, smart, solar, solid, still, strong, swift, true, warm, wide, wise
- **48 nouns**: arc, base, bay, bridge, cloud, coast, crest, delta, dome, drift, dune, edge, field, flow, forge, gate, grove, harbor, haven, helm, hill, hub, isle, lake, lane, ledge, marsh, mesa, mist, peak, pier, pine, plain, port, ridge, river, rock, shore, slope, spring, stone, stream, tide, trail, vale, view, wave, wood

Total combinations: 40 × 48 = **1,920 unique slugs** before collision.

### Collision Handling: Tenant::generateUniqueSlug()

**Namespace**: `App\Models\Tenant`  
**Method**: `static generateUniqueSlug(): string`

- **Attempts** (1–10): Calls `TenantSlugGenerator::generate()` and checks `where('slug', $slug)->exists()`. Returns first non-duplicate.
- **Fallback**: If all 10 attempts collide (extremely rare), appends a random 2–3-digit number: `{adjective}-{noun}-{10-999}`. Loops until unique.

### UI Visibility

#### Guest Registration (RegisterTenant)
- Slug is **never shown** or user-editable
- Generated automatically via `Tenant::generateUniqueSlug()`

#### Landlord Panel (CreateTenant/EditTenant)
- **Create page**: Slug field is **hidden**. System generates automatically via `mutateFormDataBeforeCreate()`.
- **Edit page**: Slug field is **visible and editable**. User can change the slug (must remain unique, lowercase with hyphens only).

Field configuration (TenantForm):
```php
TextInput::make('slug')
    ->required()
    ->unique(ignoreRecord: true)
    ->helperText('Used as the subdomain: slug.'.last(config('tenancy.central_domains')))
    ->alphaDash()
    ->maxLength(63)
    ->visibleOn('edit'),
```

---

## 4. Authentication Architecture

### Panel Access Control: canAccessPanel()

**Location**: `App\Models\User` (implements `FilamentUser`)

```php
public function canAccessPanel(Panel $panel): bool
{
    if ($panel->getId() === 'landlord') {
        return $this->is_landlord;
    }

    if ($panel->getId() === 'admin') {
        try {
            return TenantUser::where('user_id', $this->id)->exists();
        } catch (\Exception) {
            return false;
        }
    }

    return false;
}
```

#### Landlord Panel Access
- Gated by `$user->is_landlord` boolean flag (central database)
- Only users with `is_landlord = true` can access the landlord panel

#### Admin Panel Access (Tenant)
- Gated by TenantUser existence in the tenant's database
- User must have a TenantUser record in the current tenant
- Exceptions (e.g., database not initialized) return false

### Subscription-Based Access: EnsureActiveSubscription Middleware

**Location**: `App\Http\Middleware\EnsureActiveSubscription`

Runs on every admin panel request after authentication:

1. Gets current tenant from `tenancy()->tenant`
2. Checks `$tenant->isSubscriptionAccessible()` (delegates to `SubscriptionStatus::isAccessible()`)
3. If subscription is not accessible:
   - Allows logout and subscription.expired route through
   - Redirects all other requests to `subscription.expired` route
4. Allows requests when tenant is null (central domain) or subscription is accessible

**Accessible statuses**: `Trial` and `Active`  
**Blocked statuses**: `PastDue`, `Suspended`, `Cancelled`

### Permission Delegation: User → TenantUser

**Location**: `App\Models\User` methods

The central User doesn't use Spatie roles/permissions directly. Instead, it delegates to the TenantUser:

```php
public function hasPermissionTo(string|\BackedEnum $permission, ?string $guardName = null): bool
{
    try {
        $tenantUser = TenantUser::where('user_id', $this->id)->first();
        return $tenantUser?->hasPermissionTo($permission, $guardName ?? 'web') ?? false;
    } catch (\Throwable) {
        return false;
    }
}

public function hasRole(string|\BackedEnum|array $roles, ?string $guard = null): bool
{
    try {
        $tenantUser = TenantUser::where('user_id', $this->id)->first();
        return $tenantUser?->hasRole($roles, $guard ?? 'web') ?? false;
    } catch (\Throwable) {
        return false;
    }
}
```

This allows Filament policies to call `$user->hasPermissionTo()` and `$user->hasRole()` without knowing about the tenancy layer.

---

## 5. EuCountries Support Class

**Namespace**: `App\Support\EuCountries`

Static reference data for 27 EU member states. Each country stores:
- **name** – Display name (e.g., "Bulgaria")
- **vat_prefix** – VAT ID prefix (e.g., "BG" for Bulgaria, "EL" for Greece)
- **currency_code** – ISO 4217 code (e.g., "EUR", "PLN", "DKK")
- **timezone** – IANA timezone (e.g., "Europe/Sofia")
- **locale** – POSIX locale (e.g., "bg_BG")

### Methods

| Method | Returns | Purpose |
|--------|---------|---------|
| `all()` | `array<string, array>` | All 27 countries |
| `codes()` | `string[]` | ISO codes: ['AT', 'BE', 'BG', ...] |
| `get(string $code)` | `array\|null` | Country data by code (case-insensitive) |
| `forSelect()` | `array<string, string>` | Code => name map for Filament selects |
| `timezones()` | `string[]` | Unique timezone strings |
| `currencyForCountry(string $code)` | `string\|null` | Currency code for a country |
| `timezoneForCountry(string $code)` | `string\|null` | Timezone for a country |
| `localeForCountry(string $code)` | `string\|null` | Locale for a country |
| `vatPrefixForCountry(string $code)` | `string\|null` | VAT prefix for a country |

### Usage Examples

In RegisterTenant component:
```php
$country = EuCountries::get('DE');
$this->currency_code = $country['currency_code'];  // 'EUR'
$this->timezone = $country['timezone'];             // 'Europe/Berlin'
$this->locale = $country['locale'];                 // 'de_DE'
```

In TenantForm (Filament):
```php
Select::make('country_code')
    ->options(EuCountries::forSelect())
    // ...
    ->afterStateUpdated(function (?string $state, Set $set) {
        $country = EuCountries::get($state);
        $set('default_currency_code', $country['currency_code']);
        $set('timezone', $country['timezone']);
        $set('locale', $country['locale']);
    }),
```

### Countries Included

Austria (AT), Belgium (BE), Bulgaria (BG), Croatia (HR), Cyprus (CY), Czech Republic (CZ), Denmark (DK), Estonia (EE), Finland (FI), France (FR), Germany (DE), Greece (GR), Hungary (HU), Ireland (IE), Italy (IT), Latvia (LV), Lithuania (LT), Luxembourg (LU), Malta (MT), Netherlands (NL), Poland (PL), Portugal (PT), Romania (RO), Slovakia (SK), Slovenia (SI), Spain (ES), Sweden (SE)

### Special Note: Bulgaria & Euro

Bulgaria's `currency_code` is set to `EUR` (Bulgaria adopted the Euro on January 1, 2026). The `vat_prefix` remains `BG`.

---

## 6. Test Coverage

### Test Statistics

| Test File | Type | Count | Focus |
|-----------|------|-------|-------|
| **Unit Tests** |
| `EuCountriesTest.php` | Unit | 14 | EuCountries data integrity, accessors, case-insensitivity, special cases (Greece VAT, non-eurozone currencies) |
| `TenantSlugGeneratorTest.php` | Unit | 3 | Random slug generation, format validation, variety |
| `VatCalculationServiceTest.php` | Unit | 7 | VAT calculations (inclusive/exclusive), document totals, VAT breakdown |
| `FilamentIconEnumTest.php` | Unit | 1 | Heroicon enum instances for Filament enums |
| `ExampleTest.php` | Unit | 1 | Placeholder |
| **Feature Tests** |
| `RegisterTenantTest.php` | Feature | 12 | Full registration flow, step validation, country auto-fill, user/tenant/domain creation, email sending |
| `TenantOnboardingServiceTest.php` | Feature | 5 | TenantUser creation, admin role assignment, seeding (roles, currencies, VAT rates), idempotency |
| `TenantSlugTest.php` | Feature | 3 | Unique slug generation, collision handling, fallback numbering |
| `TenantLifecycleTest.php` | Feature | 18 | Tenant status transitions (suspend, mark for deletion, schedule, reactivate), state validation |
| `SubscriptionPlansTest.php` | Feature | 13 | Plan CRUD, free/paid detection, features JSON, SubscriptionStatus enum, tenant trial/active/accessible logic |
| `SubscriptionCommandsTest.php` | Feature | 8 | Trial/subscription expiration commands, plan limit checks (users & documents) |
| `DocumentSeriesTest.php` | Feature | 2 | Document number generation, sequential incrementing |
| `ExampleTest.php` | Feature | 1 | Placeholder |
| **TOTAL (Phase 1 baseline)** | | **87 tests** | |

> Phase 1 complete test count: **232 tests** (includes 1.16–1.18 + post-release hardening audit). See `tasks/phase-1.md` for full breakdown.

### Key Test Scenarios

#### Registration (RegisterTenantTest.php, 12 tests)
- Route accessibility (`/register` renders)
- Component initialization (step = 1)
- Step 1 validation (required fields, email uniqueness, password confirmation)
- Step 2 validation (company name required, country validation)
- Country auto-fill (currency, timezone, locale update on country change)
- Full flow: all steps completed, user/tenant/domain created
- Subscription status set to Trial with 14-day expiry
- Welcome email sent

#### Onboarding (TenantOnboardingServiceTest.php, 5 tests)
- TenantUser created in tenant database
- Admin role assigned to owner
- Roles & permissions seeded (admin, sales-manager, viewer)
- EUR marked as default currency
- Idempotency (calling onboard twice creates one TenantUser, not two)

#### Slug Generation (TenantSlugTest.php + TenantSlugGeneratorTest.php, 6 tests)
- TenantSlugGenerator produces `adjective-noun` format
- Generated slugs are valid DNS labels (lowercase alphanumeric + hyphen)
- Randomness verified over 30+ generations
- Tenant::generateUniqueSlug() checks uniqueness and avoids existing slugs
- Fallback numbering when adjective-noun pool is exhausted
- Format: `^[a-z]+-[a-z]+(-\d+)?$`

#### Lifecycle (TenantLifecycleTest.php, 18 tests)
- Default status is Active
- Suspension (active → suspended) with deactivation_at, deactivated_by, reason
- Mark for deletion (suspended → marked) with marked_for_deletion_at
- Schedule deletion (marked → scheduled) with deletion_scheduled_for (default 30 days)
- Reactivation (any pending → active) clearing all lifecycle timestamps
- Invalid transitions throw RuntimeException

#### Subscription & Plans (SubscriptionPlansTest.php + SubscriptionCommandsTest.php, 21 tests)
- Plan creation with pricing, billing period, limits, features (JSON)
- Free plan detection (price = 0)
- SubscriptionStatus enum: Trial, Active, PastDue, Suspended, Cancelled
- isAccessible() returns true for Trial & Active only
- onTrial() checks Trial status AND future trial_ends_at
- hasActiveSubscription() checks Active status exactly
- isSubscriptionAccessible() checks if status is accessible
- Trial expiration command marks expired trials as PastDue, sends mail
- Subscription expiration command marks past-due subscriptions
- PlanLimitService respects max_users and max_documents limits

#### EuCountries (EuCountriesTest.php, 14 tests)
- 27 EU member states present
- ISO codes match country names
- Case-insensitive lookups
- Greece uses EL as VAT prefix (not GR)
- Non-eurozone countries have correct currencies (DKK, PLN, CZK, HUF, SEK, RON)
- Bulgaria has EUR currency (post-2026 adoption)
- forSelect() returns code => name map
- Currency/timezone/locale/VAT helpers return correct values
- Unknown codes return null

#### VAT Calculations (VatCalculationServiceTest.php, 7 tests)
- fromNet(amount, rate) calculates gross and VAT
- fromGross(amount, rate) extracts net and VAT
- calculate() delegates to fromNet/fromGross based on PricingMode
- calculateDocument() sums multiple lines with VAT totals
- VAT breakdown by rate provided
- Zero VAT rate handled

---

## Summary

The HMO ERP registration system implements a user-friendly 3-step Livewire wizard (Account → Organization → Plan) that automates subdomain generation, tenant database initialization, and user onboarding. Authentication is dual-panel (Landlord for admins, Admin for tenant users), with subscription-gated access and role-based permissions delegated from central User to tenant TenantUser. Comprehensive test coverage (87 tests across 15 test files) validates registration flows, tenant lifecycle, subscription management, VAT calculations, and localization support across all 27 EU member states.
