# Architectural Decisions Log

## 2026-04-08 — D001: Do NOT use Filament's HasTenants interface

**What:** User model does NOT implement `Filament\Models\Contracts\HasTenants`.

**Why:** `HasTenants` is Filament's built-in shared-DB tenancy (via `->tenant()` on Panel). We use stancl/tenancy which handles DB switching at infrastructure level — these are incompatible approaches.

**How:** User implements only `FilamentUser` (for `canAccessPanel()`). User uses stancl's `CentralConnection` trait so auth always queries central DB even when tenant context is active.

---

## 2026-04-08 — D002: `PreventAccessFromTenantDomains` does not exist in stancl v3

**What:** Landlord panel does NOT use `PreventAccessFromTenantDomains` middleware.

**Why:** This middleware doesn't exist in stancl/tenancy v3. Landlord panel naturally operates on central domain and doesn't need tenant protection.

**How:** Landlord panel simply has no tenancy middleware at all.

---

## 2026-04-08 — D003: stancl middleware must use `isPersistent: true` in Filament

**What:** `InitializeTenancyBySubdomain` and `PreventAccessFromCentralDomains` are registered as persistent middleware on the admin panel.

**Why:** Without `isPersistent: true`, Livewire AJAX requests (subsequent requests) don't trigger the middleware, causing queries to hit the central DB instead of the tenant DB.

---

## 2026-04-08 — D004: TenancyServiceProvider must be registered in bootstrap/providers.php

**What:** Added `TenancyServiceProvider::class` to `bootstrap/providers.php`.

**Why:** stancl's `tenancy:install` generates the provider but doesn't auto-register it. Without registration, `TenantCreated` event has 0 listeners and tenant DB is never created.

---

## 2026-04-08 — D005: Cache table must be in tenant migrations before permission tables

**What:** `create_cache_table` migration is timestamped before `create_permission_tables` in tenant migrations folder.

**Why:** spatie/permission flushes its cache after migration runs. If the `cache` table doesn't exist yet (database driver), the flush fails with `relation "cache" does not exist`. Running cache table first resolves this.

---

## 2026-04-08 — D006: LogsActivity namespace correction

**What:** `use Spatie\Activitylog\Models\Concerns\LogsActivity` (not `Spatie\Activitylog\Traits\LogsActivity`)

**Why:** In spatie/laravel-activitylog v5, the trait was moved to `Models\Concerns` namespace. The old `Traits` path doesn't exist.

---

## 2026-04-08 — D007: $navigationGroup type must be `string|UnitEnum|null`

**What:** Child resource classes that override `$navigationGroup` must use `string|\UnitEnum|null` as the type declaration.

**Why:** Filament's `HasNavigation` trait declares `protected static string | UnitEnum | null $navigationGroup`. PHP enforces invariant property types — child must match parent exactly. Using `?string` or `string|BackedEnum|null` causes a fatal type error.

**How:** Use string literals (`'Settings'`, `'CRM'`) for the value, with `string|\UnitEnum|null` type declaration.
