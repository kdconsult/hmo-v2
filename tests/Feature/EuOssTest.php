<?php

declare(strict_types=1);

use App\Enums\PaymentMethod;
use App\Enums\VatStatus;
use App\Models\CompanySettings;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\EuOssAccumulation;
use App\Models\Partner;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VatRate;
use App\Services\CustomerInvoiceService;
use App\Services\EuOssService;
use App\Services\TenantOnboardingService;

test('accumulate tracks cross-border B2C EU invoice totals', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->customer()->create([
            'country_code' => 'DE',
            'vat_number' => '',
        ]);

        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'currency_code' => 'EUR',
            'exchange_rate' => '1.000000',
            'total' => '500.00',
        ]);
        $invoice->load('partner');

        app(EuOssService::class)->accumulate($invoice);

        $record = EuOssAccumulation::where('country_code', 'DE')->first();
        expect($record)->not->toBeNull()
            ->and((float) $record->accumulated_amount_eur)->toBe(500.0);
    });
});

test('accumulate skips domestic invoices', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->customer()->create([
            'country_code' => 'BG',
            'vat_number' => '',
        ]);

        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'total' => '500.00',
        ]);
        $invoice->load('partner');

        app(EuOssService::class)->accumulate($invoice);

        expect(EuOssAccumulation::count())->toBe(0);
    });
});

test('accumulate skips B2B invoices with valid EU VAT', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->customer()->create([
            'country_code' => 'DE',
            'vat_number' => 'DE123456789',
            'vat_status' => VatStatus::Confirmed,
            'is_vat_registered' => true,
        ]);

        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'total' => '500.00',
        ]);
        $invoice->load('partner');

        app(EuOssService::class)->accumulate($invoice);

        expect(EuOssAccumulation::count())->toBe(0);
    });
});

test('accumulate skips non-EU invoices', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->customer()->create([
            'country_code' => 'US',
            'vat_number' => '',
        ]);

        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'total' => '500.00',
        ]);
        $invoice->load('partner');

        app(EuOssService::class)->accumulate($invoice);

        expect(EuOssAccumulation::count())->toBe(0);
    });
});

test('isThresholdExceeded returns false when total is below 10000', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        EuOssAccumulation::accumulate('DE', (int) now()->year, 3000.0);
        EuOssAccumulation::accumulate('FR', (int) now()->year, 4000.0);

        expect(EuOssAccumulation::isThresholdExceeded((int) now()->year))->toBeFalse();
    });
});

test('isThresholdExceeded returns true when total across all countries exceeds 10000', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        EuOssAccumulation::accumulate('DE', (int) now()->year, 6000.0);
        EuOssAccumulation::accumulate('FR', (int) now()->year, 6000.0);

        expect(EuOssAccumulation::isThresholdExceeded((int) now()->year))->toBeTrue();
    });
});

test('shouldApplyOss returns false for B2B partner with valid EU VAT', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');
        EuOssAccumulation::accumulate('DE', (int) now()->year, 15000.0);

        $partner = Partner::factory()->customer()->create([
            'country_code' => 'DE',
            'vat_number' => 'DE123456789',
            'vat_status' => VatStatus::Confirmed,
            'is_vat_registered' => true,
        ]);

        expect(app(EuOssService::class)->shouldApplyOss($partner))->toBeFalse();
    });
});

test('shouldApplyOss returns false for domestic partner', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');
        EuOssAccumulation::accumulate('BG', (int) now()->year, 15000.0);

        $partner = Partner::factory()->customer()->create([
            'country_code' => 'BG',
            'vat_number' => '',
        ]);

        expect(app(EuOssService::class)->shouldApplyOss($partner))->toBeFalse();
    });
});

test('shouldApplyOss returns false for non-EU partner', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');
        EuOssAccumulation::accumulate('US', (int) now()->year, 15000.0);

        $partner = Partner::factory()->customer()->create([
            'country_code' => 'US',
            'vat_number' => '',
        ]);

        expect(app(EuOssService::class)->shouldApplyOss($partner))->toBeFalse();
    });
});

test('shouldApplyOss returns false when threshold is not yet exceeded', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');
        // Accumulate below threshold
        EuOssAccumulation::accumulate('DE', (int) now()->year, 3000.0);

        $partner = Partner::factory()->customer()->create([
            'country_code' => 'DE',
            'vat_number' => '',
        ]);

        expect(app(EuOssService::class)->shouldApplyOss($partner))->toBeFalse();
    });
});

test('shouldApplyOss returns true when B2C cross-border EU and threshold exceeded', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');
        // Exceed threshold
        EuOssAccumulation::accumulate('DE', (int) now()->year, 11000.0);

        $partner = Partner::factory()->customer()->create([
            'country_code' => 'DE',
            'vat_number' => '',
        ]);

        expect(app(EuOssService::class)->shouldApplyOss($partner))->toBeTrue();
    });
});

// F-022 regression: OSS accumulation must fire for service-type items, not just goods
test('confirmWithScenario triggers OSS accumulation for invoices with service-type items', function () {
    $tenant = Tenant::factory()->vatRegistered()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->customer()->create(['country_code' => 'DE']);

        $product = Product::factory()->service()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $zeroRate = VatRate::where('rate', 0)->first();

        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'currency_code' => 'EUR',
            'exchange_rate' => '1.000000',
            'payment_method' => PaymentMethod::BankTransfer,
            'total' => '200.00',
        ]);

        CustomerInvoiceItem::factory()->create([
            'customer_invoice_id' => $invoice->id,
            'product_variant_id' => $variant->id,
            'quantity' => '1.0000',
            'unit_price' => '200.0000',
            'vat_rate_id' => $zeroRate?->id,
            'line_total' => '200.00',
            'line_total_with_vat' => '200.00',
        ]);

        app(CustomerInvoiceService::class)->confirmWithScenario($invoice);

        $accumulated = EuOssAccumulation::where('year', (int) now()->year)
            ->where('country_code', 'DE')
            ->sum('accumulated_amount_eur');

        expect((float) $accumulated)->toBeGreaterThan(0.0);
    });
});
