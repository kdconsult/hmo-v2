<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Policies\TenantPolicy;

// --- updateBankDetails ---

test('updateBankDetails returns false for non-landlord user', function () {
    $user = User::factory()->create(['is_landlord' => false]);
    $tenant = Tenant::factory()->create();
    config(['hmo.landlord_tenant_id' => $tenant->id]);

    expect((new TenantPolicy)->updateBankDetails($user, $tenant))->toBeFalse();
});

test('updateBankDetails returns true for landlord user with the landlord tenant', function () {
    $user = User::factory()->create(['is_landlord' => true]);
    $tenant = Tenant::factory()->create();
    config(['hmo.landlord_tenant_id' => $tenant->id]);

    expect((new TenantPolicy)->updateBankDetails($user, $tenant))->toBeTrue();
});

test('updateBankDetails returns false for landlord user with a normal tenant', function () {
    $user = User::factory()->create(['is_landlord' => true]);
    $landlordTenant = Tenant::factory()->create();
    config(['hmo.landlord_tenant_id' => $landlordTenant->id]);
    $tenant = Tenant::factory()->create();

    expect((new TenantPolicy)->updateBankDetails($user, $tenant))->toBeFalse();
});
