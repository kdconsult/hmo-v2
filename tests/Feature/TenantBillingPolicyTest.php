<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\TenantPolicy;

// --- changePlan ---

test('changePlan returns false for non-landlord user', function () {
    $user = User::factory()->create(['is_landlord' => false]);
    $tenant = Tenant::factory()->create();

    expect((new TenantPolicy)->changePlan($user, $tenant))->toBeFalse();
});

test('changePlan returns false for the landlord tenant', function () {
    $user = User::factory()->create(['is_landlord' => true]);
    $tenant = Tenant::factory()->create();
    config(['hmo.landlord_tenant_id' => $tenant->id]);

    expect((new TenantPolicy)->changePlan($user, $tenant))->toBeFalse();
});

test('changePlan returns true for landlord user with a normal tenant', function () {
    $user = User::factory()->create(['is_landlord' => true]);
    $landlordTenant = Tenant::factory()->create();
    config(['hmo.landlord_tenant_id' => $landlordTenant->id]);
    $tenant = Tenant::factory()->create();

    expect((new TenantPolicy)->changePlan($user, $tenant))->toBeTrue();
});

// --- cancelSubscription ---

test('cancelSubscription returns false for non-landlord user', function () {
    $user = User::factory()->create(['is_landlord' => false]);
    $tenant = Tenant::factory()->create();

    expect((new TenantPolicy)->cancelSubscription($user, $tenant))->toBeFalse();
});

test('cancelSubscription returns false for the landlord tenant', function () {
    $user = User::factory()->create(['is_landlord' => true]);
    $tenant = Tenant::factory()->create();
    config(['hmo.landlord_tenant_id' => $tenant->id]);

    expect((new TenantPolicy)->cancelSubscription($user, $tenant))->toBeFalse();
});

test('cancelSubscription returns true for landlord user with a normal tenant', function () {
    $user = User::factory()->create(['is_landlord' => true]);
    $landlordTenant = Tenant::factory()->create();
    config(['hmo.landlord_tenant_id' => $landlordTenant->id]);
    $tenant = Tenant::factory()->create();

    expect((new TenantPolicy)->cancelSubscription($user, $tenant))->toBeTrue();
});

// --- recordPayment ---

test('recordPayment returns false for non-landlord user', function () {
    $user = User::factory()->create(['is_landlord' => false]);
    $tenant = Tenant::factory()->create();

    expect((new TenantPolicy)->recordPayment($user, $tenant))->toBeFalse();
});

test('recordPayment returns false for the landlord tenant', function () {
    $user = User::factory()->create(['is_landlord' => true]);
    $tenant = Tenant::factory()->create();
    config(['hmo.landlord_tenant_id' => $tenant->id]);

    expect((new TenantPolicy)->recordPayment($user, $tenant))->toBeFalse();
});

test('recordPayment returns true for landlord user with a paid-plan tenant', function () {
    $user = User::factory()->create(['is_landlord' => true]);
    $landlordTenant = Tenant::factory()->create();
    config(['hmo.landlord_tenant_id' => $landlordTenant->id]);
    $plan = Plan::factory()->create(['price' => 19.00]);
    $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);

    expect((new TenantPolicy)->recordPayment($user, $tenant))->toBeTrue();
});

test('recordPayment returns false for tenant on free plan', function () {
    $user = User::factory()->create(['is_landlord' => true]);
    $landlordTenant = Tenant::factory()->create();
    config(['hmo.landlord_tenant_id' => $landlordTenant->id]);
    $plan = Plan::factory()->create(['price' => 0.00]);
    $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);

    expect((new TenantPolicy)->recordPayment($user, $tenant))->toBeFalse();
});

test('recordPayment returns false for tenant with no plan', function () {
    $user = User::factory()->create(['is_landlord' => true]);
    $landlordTenant = Tenant::factory()->create();
    config(['hmo.landlord_tenant_id' => $landlordTenant->id]);
    $tenant = Tenant::factory()->create(['plan_id' => null]);

    expect((new TenantPolicy)->recordPayment($user, $tenant))->toBeFalse();
});

// --- sendProformaInvoice ---

test('sendProformaInvoice returns false for non-landlord user', function () {
    $user = User::factory()->create(['is_landlord' => false]);
    $tenant = Tenant::factory()->create();

    expect((new TenantPolicy)->sendProformaInvoice($user, $tenant))->toBeFalse();
});

test('sendProformaInvoice returns false for the landlord tenant', function () {
    $user = User::factory()->create(['is_landlord' => true]);
    $tenant = Tenant::factory()->create();
    config(['hmo.landlord_tenant_id' => $tenant->id]);

    expect((new TenantPolicy)->sendProformaInvoice($user, $tenant))->toBeFalse();
});

test('sendProformaInvoice returns true for landlord user with a paid-plan tenant', function () {
    $user = User::factory()->create(['is_landlord' => true]);
    $landlordTenant = Tenant::factory()->create();
    config(['hmo.landlord_tenant_id' => $landlordTenant->id]);
    $plan = Plan::factory()->create(['price' => 19.00]);
    $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);

    expect((new TenantPolicy)->sendProformaInvoice($user, $tenant))->toBeTrue();
});

test('sendProformaInvoice returns false for tenant on free plan', function () {
    $user = User::factory()->create(['is_landlord' => true]);
    $landlordTenant = Tenant::factory()->create();
    config(['hmo.landlord_tenant_id' => $landlordTenant->id]);
    $plan = Plan::factory()->create(['price' => 0.00]);
    $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);

    expect((new TenantPolicy)->sendProformaInvoice($user, $tenant))->toBeFalse();
});

test('sendProformaInvoice returns false for tenant with no plan', function () {
    $user = User::factory()->create(['is_landlord' => true]);
    $landlordTenant = Tenant::factory()->create();
    config(['hmo.landlord_tenant_id' => $landlordTenant->id]);
    $tenant = Tenant::factory()->create(['plan_id' => null]);

    expect((new TenantPolicy)->sendProformaInvoice($user, $tenant))->toBeFalse();
});
