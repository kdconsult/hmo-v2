<?php

declare(strict_types=1);

use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\TenantPolicy;
use App\Services\TenantDeletionGuard;
use App\Support\EuCountries;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

// --- isLandlordTenant() ---

test('isLandlordTenant returns false when config is null', function () {
    config(['hmo.landlord_tenant_id' => null]);
    $tenant = Tenant::factory()->create();

    expect($tenant->isLandlordTenant())->toBeFalse();
});

test('isLandlordTenant returns true for the configured tenant', function () {
    $tenant = Tenant::factory()->create();
    config(['hmo.landlord_tenant_id' => $tenant->id]);

    expect($tenant->isLandlordTenant())->toBeTrue();
});

test('isLandlordTenant returns false for other tenants when config is set', function () {
    $landlordTenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    config(['hmo.landlord_tenant_id' => $landlordTenant->id]);

    expect($other->isLandlordTenant())->toBeFalse();
});

// --- Tenant::landlordTenant() ---

test('landlordTenant static method returns null when not configured', function () {
    config(['hmo.landlord_tenant_id' => null]);

    expect(Tenant::landlordTenant())->toBeNull();
});

test('landlordTenant static method returns the configured tenant', function () {
    $tenant = Tenant::factory()->create();
    config(['hmo.landlord_tenant_id' => $tenant->id]);

    expect(Tenant::landlordTenant()->id)->toBe($tenant->id);
});

test('landlordTenant static method returns null when id does not exist in db', function () {
    config(['hmo.landlord_tenant_id' => 'nonexistent-id-xyz']);

    expect(Tenant::landlordTenant())->toBeNull();
});

// --- Landlord tenant subscription never expires ---

// --- formattedAddress() ---

test('formattedAddress returns all non-null address parts joined', function () {
    $tenant = Tenant::factory()->create([
        'address_line_1' => 'ул. Тест 1',
        'city' => 'София',
        'postal_code' => '1000',
        'country_code' => 'BG',
    ]);

    expect($tenant->formattedAddress())->toBe('ул. Тест 1, София, 1000, BG');
});

test('formattedAddress skips null parts', function () {
    $tenant = Tenant::factory()->create([
        'address_line_1' => null,
        'city' => 'София',
        'postal_code' => null,
        'country_code' => 'BG',
    ]);

    expect($tenant->formattedAddress())->toBe('София, BG');
});

test('formattedAddress returns only country when all other parts are null', function () {
    $tenant = Tenant::factory()->create([
        'address_line_1' => null,
        'city' => null,
        'postal_code' => null,
        'country_code' => 'BG',
    ]);

    expect($tenant->formattedAddress())->toBe('BG');
});

// --- EIK uniqueness ---

test('two tenants cannot share the same EIK', function () {
    Tenant::factory()->create(['eik' => '123456789']);

    expect(fn () => Tenant::factory()->create(['eik' => '123456789']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('eik cannot be null', function () {
    expect(fn () => Tenant::factory()->create(['eik' => null]))
        ->toThrow(QueryException::class);
});

// --- EuCountries VAT helpers ---

test('vatNumberRegex returns correct pattern for BG', function () {
    expect(EuCountries::vatNumberRegex('BG'))->toBe('/^BG\d{9,10}$/i');
});

test('vatNumberRegex returns null for unknown country code', function () {
    expect(EuCountries::vatNumberRegex('XX'))->toBeNull();
});

test('vatNumberExample returns example for known country', function () {
    expect(EuCountries::vatNumberExample('BG'))->toBe('BG123456789');
});

test('extractMainVatNumber strips BG subdivision suffix', function () {
    // 9-digit legal entity: unchanged
    expect(EuCountries::extractMainVatNumber('BG', '123456789'))->toBe('123456789');
    // 10-digit individual: unchanged
    expect(EuCountries::extractMainVatNumber('BG', '1234567890'))->toBe('1234567890');
    // 11-digit subdivision: strip to first 9
    expect(EuCountries::extractMainVatNumber('BG', '12345678901'))->toBe('123456789');
});

test('extractMainVatNumber leaves other country EIKs unchanged', function () {
    expect(EuCountries::extractMainVatNumber('DE', '12345678901'))->toBe('12345678901');
});

// --- landlordTenant() caching ---

test('landlordTenant is cached after first call', function () {
    $tenant = Tenant::factory()->create();
    config(['hmo.landlord_tenant_id' => $tenant->id]);
    Tenant::clearLandlordTenantCache();

    DB::enableQueryLog();
    Tenant::landlordTenant(); // first call — hits DB
    Tenant::landlordTenant(); // second call — should hit cache
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $tenantQueries = collect($log)->filter(fn ($q) => str_contains($q['query'], 'tenants'))->count();
    expect($tenantQueries)->toBe(1);
});

test('clearLandlordTenantCache forces fresh DB fetch', function () {
    $tenant = Tenant::factory()->create(['name' => 'Original Name']);
    config(['hmo.landlord_tenant_id' => $tenant->id]);
    Tenant::clearLandlordTenantCache();

    Tenant::landlordTenant(); // prime cache

    DB::statement('UPDATE tenants SET name = ? WHERE id = ?', ['Updated Name', $tenant->id]);

    // Without clearing: still sees 'Original Name'
    expect(Tenant::landlordTenant()->name)->toBe('Original Name');

    Tenant::clearLandlordTenantCache();

    // After clearing: sees 'Updated Name'
    expect(Tenant::landlordTenant()->name)->toBe('Updated Name');
});

test('saving landlord tenant clears the cache automatically', function () {
    $tenant = Tenant::factory()->create(['name' => 'Before Save']);
    config(['hmo.landlord_tenant_id' => $tenant->id]);
    Tenant::clearLandlordTenantCache();

    Tenant::landlordTenant(); // prime cache

    $tenant->update(['name' => 'After Save']); // triggers booted saved event

    expect(Tenant::landlordTenant()->name)->toBe('After Save');
});

// --- landlord tenant subscription never expires ---

test('landlord tenant with null subscription_ends_at is skipped by CheckSubscriptionExpirations', function () {
    $plan = Plan::create([
        'name' => 'Professional', 'slug' => 'pro-'.uniqid(),
        'price' => 49.00, 'billing_period' => 'monthly', 'is_active' => true, 'sort_order' => 3,
    ]);
    $tenant = Tenant::factory()->create([
        'plan_id' => $plan->id,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_ends_at' => null,
    ]);
    config(['hmo.landlord_tenant_id' => $tenant->id]);

    $this->artisan('app:check-subscription-expirations');

    expect($tenant->fresh()->subscription_status)->toBe(SubscriptionStatus::Active);
});

// --- TenantPolicy forceDelete / restore (1.18.2) ---

test('TenantPolicy forceDelete always returns false', function () {
    $landlord = User::factory()->create(['is_landlord' => true]);
    $tenant = Tenant::factory()->create();

    expect((new TenantPolicy)->forceDelete($landlord, $tenant))->toBeFalse();
});

test('TenantPolicy restore always returns false', function () {
    $landlord = User::factory()->create(['is_landlord' => true]);
    $tenant = Tenant::factory()->create();

    expect((new TenantPolicy)->restore($landlord, $tenant))->toBeFalse();
});

// --- Gate::before scoped to tenant context (1.18.4) ---

test('Gate::before does not grant super-admin bypass when tenancy is not initialized', function () {
    // tenancy() is never initialized in this (central) test context
    $landlord = User::factory()->create(['is_landlord' => true]);

    // forceDelete always returns false in policy — super-admin bypass must not override it
    expect(Gate::forUser($landlord)->allows('forceDelete', Tenant::factory()->create()))->toBeFalse();
});

// --- TenantDeletionGuard hardening (1.18.7) ---

test('TenantDeletionGuard rejects the landlord tenant', function () {
    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::ScheduledForDeletion,
        'deletion_scheduled_for' => now()->subDay(),
    ]);
    config(['hmo.landlord_tenant_id' => $tenant->id]);

    expect(fn () => TenantDeletionGuard::check($tenant))
        ->toThrow(RuntimeException::class, 'landlord tenant');
});

test('TenantDeletionGuard rejects null deletion_scheduled_for', function () {
    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::ScheduledForDeletion,
        'deletion_scheduled_for' => null,
    ]);

    expect(fn () => TenantDeletionGuard::check($tenant))
        ->toThrow(RuntimeException::class, 'deletion_scheduled_for is not set');
});
