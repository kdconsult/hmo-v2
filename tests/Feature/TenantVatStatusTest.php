<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Models\VatRate;
use App\Services\TenantOnboardingService;
use App\Support\TenantVatStatus;

test('isRegistered returns false when tenant is_vat_registered is false', function () {
    $tenant = Tenant::factory()->create(); // is_vat_registered = false by default
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        expect(TenantVatStatus::isRegistered())->toBeFalse();
    });
});

test('isRegistered returns true when tenant is VAT-registered', function () {
    $tenant = Tenant::factory()->vatRegistered()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        expect(TenantVatStatus::isRegistered())->toBeTrue();
    });
});

test('country returns the tenant country_code', function () {
    $tenant = Tenant::factory()->create(['country_code' => 'BG']);
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        expect(TenantVatStatus::country())->toBe('BG');
    });
});

test('zeroExemptRate finds the existing seeded zero-type rate for tenant country', function () {
    $tenant = Tenant::factory()->create(['country_code' => 'BG']);
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $rate = TenantVatStatus::zeroExemptRate();

        expect((float) $rate->rate)->toBe(0.0)
            ->and($rate->type)->toBe('zero')
            ->and($rate->country_code)->toBe('BG')
            ->and($rate->is_active)->toBeTrue();
    });
});

test('zeroExemptRate creates 0% rate on demand if missing', function () {
    $tenant = Tenant::factory()->create(['country_code' => 'XY']); // no seeded rate for XY
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $before = VatRate::where('country_code', 'XY')->where('type', 'zero')->count();
        expect($before)->toBe(0);

        $rate = TenantVatStatus::zeroExemptRate();

        expect((float) $rate->rate)->toBe(0.0)
            ->and($rate->country_code)->toBe('XY')
            ->and($rate->type)->toBe('zero');

        // Calling again returns the same row (idempotent)
        $again = TenantVatStatus::zeroExemptRate();
        expect($again->id)->toBe($rate->id);
    });
});

test('isRegistered reflects live tenant state after update', function () {
    $tenant = Tenant::factory()->create(); // non-registered
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () use ($tenant) {
        expect(TenantVatStatus::isRegistered())->toBeFalse();

        // Simulate tenant registering for VAT
        $tenant->update(['is_vat_registered' => true]);

        expect(TenantVatStatus::isRegistered())->toBeTrue();
    });
});
