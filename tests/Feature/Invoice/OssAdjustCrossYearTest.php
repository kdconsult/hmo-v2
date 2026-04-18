<?php

declare(strict_types=1);

use App\Enums\DocumentStatus;
use App\Enums\VatStatus;
use App\Models\CompanySettings;
use App\Models\CustomerInvoice;
use App\Models\EuOssAccumulation;
use App\Models\Partner;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EuOssService;
use App\Services\TenantOnboardingService;

beforeEach(function () {
    $this->tenant = Tenant::factory()->vatRegistered()->create([
        'country_code' => 'BG',
        'locale' => 'bg',
    ]);
    $this->user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($this->tenant, $this->user);
});

afterEach(function () {
    tenancy()->end();
});

it('uses parent invoice year for OSS accumulation, not current year', function () {
    $this->tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->customer()->create([
            'country_code' => 'DE',
            'vat_number' => '',
        ]);

        // Parent issued in 2025; adjust should accumulate under year 2025
        $parent = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'status' => DocumentStatus::Confirmed,
            'currency_code' => 'EUR',
            'exchange_rate' => '1.000000',
            'total' => '200.00',
            'issued_at' => '2025-06-15',
        ]);
        $parent->load('partner');

        app(EuOssService::class)->adjust($parent, 200.0);

        $record2025 = EuOssAccumulation::where('country_code', 'DE')
            ->where('year', 2025)
            ->first();

        expect($record2025)->not->toBeNull()
            ->and((float) $record2025->accumulated_amount_eur)->toBe(200.0);

        // Year 2026 must have no entry for this country
        expect(EuOssAccumulation::where('country_code', 'DE')->where('year', 2026)->exists())->toBeFalse();
    });
});

it('reduces accumulated balance when a negative delta is applied', function () {
    $this->tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->customer()->create([
            'country_code' => 'DE',
            'vat_number' => '',
        ]);

        $parent = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'status' => DocumentStatus::Confirmed,
            'currency_code' => 'EUR',
            'exchange_rate' => '1.000000',
            'total' => '500.00',
            'issued_at' => now()->toDateString(),
        ]);
        $parent->load('partner');

        // Seed initial accumulation of 500
        EuOssAccumulation::accumulate('DE', (int) now()->year, 500.0);

        // Apply negative delta of -200
        app(EuOssService::class)->adjust($parent, -200.0);

        $record = EuOssAccumulation::where('country_code', 'DE')
            ->where('year', (int) now()->year)
            ->first();

        expect($record)->not->toBeNull()
            ->and((float) $record->accumulated_amount_eur)->toBe(300.0);
    });
});

it('skips accumulation for a domestic BG partner', function () {
    $this->tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->customer()->create([
            'country_code' => 'BG',
            'vat_number' => '',
        ]);

        $parent = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'status' => DocumentStatus::Confirmed,
            'currency_code' => 'EUR',
            'exchange_rate' => '1.000000',
            'total' => '300.00',
            'issued_at' => now()->toDateString(),
        ]);
        $parent->load('partner');

        app(EuOssService::class)->adjust($parent, 300.0);

        expect(EuOssAccumulation::count())->toBe(0);
    });
});

it('skips accumulation for a B2B partner with a valid EU VAT number', function () {
    $this->tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->customer()->create([
            'country_code' => 'DE',
            'vat_number' => 'DE123456789',
            'vat_status' => VatStatus::Confirmed,
            'is_vat_registered' => true,
        ]);

        $parent = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'status' => DocumentStatus::Confirmed,
            'currency_code' => 'EUR',
            'exchange_rate' => '1.000000',
            'total' => '400.00',
            'issued_at' => now()->toDateString(),
        ]);
        $parent->load('partner');

        app(EuOssService::class)->adjust($parent, 400.0);

        expect(EuOssAccumulation::count())->toBe(0);
    });
});
