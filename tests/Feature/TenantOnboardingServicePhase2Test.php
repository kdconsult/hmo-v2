<?php

declare(strict_types=1);

use App\Models\CompanySettings;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\TenantOnboardingService;

test('onboard seeds units in tenant DB', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();

    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        expect(Unit::where('symbol', 'pcs')->exists())->toBeTrue()
            ->and(Unit::where('symbol', 'kg')->exists())->toBeTrue()
            ->and(Unit::where('symbol', 'm²')->exists())->toBeTrue()
            ->and(Unit::count())->toBe(13);
    });
});

test('onboard creates default Main Warehouse', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();

    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $warehouse = Warehouse::where('code', 'MAIN')->first();

        expect($warehouse)->not->toBeNull()
            ->and($warehouse->name)->toBe('Main Warehouse')
            ->and($warehouse->is_default)->toBeTrue();
    });
});

test('onboard is idempotent — calling twice does not create duplicates', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $service = app(TenantOnboardingService::class);

    $service->onboard($tenant, $user);
    $service->onboard($tenant, $user);

    $tenant->run(function () {
        expect(Warehouse::where('code', 'MAIN')->count())->toBe(1)
            ->and(Unit::where('symbol', 'pcs')->count())->toBe(1);
    });
});

test('onboard sets default supported locales', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();

    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        expect(CompanySettings::get('localization', 'locale_en'))->toBe('1');
    });
});
