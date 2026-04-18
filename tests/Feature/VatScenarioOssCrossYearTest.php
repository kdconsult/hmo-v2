<?php

declare(strict_types=1);

use App\Enums\VatScenario;
use App\Enums\VatStatus;
use App\Models\EuOssAccumulation;
use App\Models\Partner;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantOnboardingService;

// ─── F-006: VatScenario::determine uses explicit year (not now()->year) ─────

test('VatScenario::determine uses explicit year when provided (cross-year scenario)', function () {
    $tenant = Tenant::factory()->vatRegistered()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        // 2025 is above threshold; current year is not
        EuOssAccumulation::updateOrCreate(
            ['year' => 2025, 'country_code' => 'DE'],
            ['accumulated_amount_eur' => 15000.00]
        );
        EuOssAccumulation::updateOrCreate(
            ['year' => now()->year, 'country_code' => 'DE'],
            ['accumulated_amount_eur' => 0.00]
        );

        $partner = Partner::factory()->create([
            'country_code' => 'DE',
            'vat_status' => VatStatus::NotRegistered,
            'is_vat_registered' => false,
            'vat_number' => null,
        ]);

        // Invoice dated in 2025 → threshold exceeded → OSS rate applies
        $scenario2025 = VatScenario::determine(
            $partner,
            'BG',
            tenantIsVatRegistered: true,
            year: 2025,
        );

        // Same invoice dated in current year → threshold not exceeded → domestic rate
        $scenarioCurrent = VatScenario::determine(
            $partner,
            'BG',
            tenantIsVatRegistered: true,
            year: now()->year,
        );

        expect($scenario2025)->toBe(VatScenario::EuB2cOverThreshold)
            ->and($scenarioCurrent)->toBe(VatScenario::EuB2cUnderThreshold);
    });
});

test('VatScenario::determine defaults to current year when no year given (backward compat)', function () {
    $tenant = Tenant::factory()->vatRegistered()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        EuOssAccumulation::updateOrCreate(
            ['year' => now()->year, 'country_code' => 'DE'],
            ['accumulated_amount_eur' => 0.00]
        );

        $partner = Partner::factory()->create([
            'country_code' => 'DE',
            'vat_status' => VatStatus::NotRegistered,
            'is_vat_registered' => false,
            'vat_number' => null,
        ]);

        $scenario = VatScenario::determine($partner, 'BG', tenantIsVatRegistered: true);

        expect($scenario)->toBe(VatScenario::EuB2cUnderThreshold);
    });
});
