<?php

declare(strict_types=1);

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Tenant;

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
