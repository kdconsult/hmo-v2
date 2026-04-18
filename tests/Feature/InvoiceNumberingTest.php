<?php

declare(strict_types=1);

use App\Enums\DocumentStatus;
use App\Enums\SeriesType;
use App\Models\CompanySettings;
use App\Models\CustomerInvoice;
use App\Models\NumberSeries;
use App\Models\Partner;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CustomerInvoiceService;
use App\Services\TenantOnboardingService;

function invoiceNumberingSetup(): array
{
    CompanySettings::set('company', 'country_code', 'BG');
    $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);
    $series = NumberSeries::factory()->forType(SeriesType::Invoice)->create([
        'prefix' => null,
        'include_year' => false,
        'padding' => 10,
        'separator' => '',
        'next_number' => 1,
    ]);

    return compact('series', 'partner');
}

test('draft invoice is saved without an invoice number', function () {
    $tenant = Tenant::factory()->vatRegistered()->create(['country_code' => 'BG']);
    app(TenantOnboardingService::class)->onboard($tenant, User::factory()->create());

    $tenant->run(function () {
        ['partner' => $partner] = invoiceNumberingSetup();

        $draft = CustomerInvoice::factory()->create([
            'invoice_number' => null,
            'partner_id' => $partner->id,
        ]);

        expect($draft->invoice_number)->toBeNull()
            ->and($draft->status)->toBe(DocumentStatus::Draft);
    });
});

test('invoice number is allocated from the series at confirmation', function () {
    $tenant = Tenant::factory()->vatRegistered()->create(['country_code' => 'BG']);
    app(TenantOnboardingService::class)->onboard($tenant, User::factory()->create());

    $tenant->run(function () {
        ['series' => $series, 'partner' => $partner] = invoiceNumberingSetup();

        $draft = CustomerInvoice::factory()->create([
            'invoice_number' => null,
            'partner_id' => $partner->id,
        ]);

        app(CustomerInvoiceService::class)->confirmWithScenario($draft);

        expect($draft->fresh()->invoice_number)->toBe('0000000001')
            ->and($draft->fresh()->status)->toBe(DocumentStatus::Confirmed)
            ->and($series->fresh()->next_number)->toBe(2);
    });
});

test('deleting a draft does not consume a sequence number — confirmed invoice gets first number', function () {
    $tenant = Tenant::factory()->vatRegistered()->create(['country_code' => 'BG']);
    app(TenantOnboardingService::class)->onboard($tenant, User::factory()->create());

    $tenant->run(function () {
        ['series' => $series, 'partner' => $partner] = invoiceNumberingSetup();

        $draft1 = CustomerInvoice::factory()->create([
            'invoice_number' => null,
            'partner_id' => $partner->id,
        ]);
        $draft2 = CustomerInvoice::factory()->create([
            'invoice_number' => null,
            'partner_id' => $partner->id,
        ]);

        // Delete draft1 — series sequence must not advance
        $draft1->delete();
        expect($series->fresh()->next_number)->toBe(1);

        // Confirm draft2 — must receive the first sequence number, no gap
        app(CustomerInvoiceService::class)->confirmWithScenario($draft2);

        expect($draft2->fresh()->invoice_number)->toBe('0000000001')
            ->and($series->fresh()->next_number)->toBe(2);
    });
});
