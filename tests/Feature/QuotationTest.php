<?php

declare(strict_types=1);

use App\Enums\PricingMode;
use App\Enums\QuotationStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Partner;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\SalesOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VatRate;
use App\Models\Warehouse;
use App\Services\QuotationService;
use App\Services\TenantOnboardingService;

test('quotation can be created with required fields', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $partner = Partner::factory()->customer()->create();

        $quotation = Quotation::create([
            'quotation_number' => 'QT-TEST-001',
            'partner_id' => $partner->id,
            'status' => QuotationStatus::Draft,
            'currency_code' => 'EUR',
            'exchange_rate' => '1.000000',
            'pricing_mode' => PricingMode::VatExclusive,
            'subtotal' => '0.00',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'total' => '0.00',
        ]);

        expect($quotation->quotation_number)->toBe('QT-TEST-001')
            ->and($quotation->status)->toBe(QuotationStatus::Draft)
            ->and($quotation->isEditable())->toBeTrue();
    });
});

test('quotation is not editable when sent', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $quotation = Quotation::factory()->sent()->create();

        expect($quotation->isEditable())->toBeFalse();
    });
});

test('quotation isExpired returns true when valid_until is past', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $quotation = Quotation::factory()->expired()->create();

        expect($quotation->isExpired())->toBeTrue();
    });
});

test('quotation isExpired returns false when valid_until is in the future', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $quotation = Quotation::factory()->draft()->create(['valid_until' => now()->addDays(30)]);

        expect($quotation->isExpired())->toBeFalse();
    });
});

test('quotation status transitions draft to sent', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $service = app(QuotationService::class);
        $quotation = Quotation::factory()->draft()->create();
        QuotationItem::factory()->for($quotation)->create();

        $service->transitionStatus($quotation, QuotationStatus::Sent);
        expect($quotation->fresh()->status)->toBe(QuotationStatus::Sent);
    });
});

test('quotation status transitions sent to accepted', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $service = app(QuotationService::class);
        $quotation = Quotation::factory()->sent()->create();
        QuotationItem::factory()->for($quotation)->create();

        $service->transitionStatus($quotation, QuotationStatus::Accepted);
        expect($quotation->fresh()->status)->toBe(QuotationStatus::Accepted);
    });
});

test('quotation status transitions sent to rejected', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $service = app(QuotationService::class);
        $quotation = Quotation::factory()->sent()->create();
        QuotationItem::factory()->for($quotation)->create();

        $service->transitionStatus($quotation, QuotationStatus::Rejected);
        expect($quotation->fresh()->status)->toBe(QuotationStatus::Rejected);
    });
});

test('quotation invalid transition throws exception', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $service = app(QuotationService::class);
        $quotation = Quotation::factory()->draft()->create();
        QuotationItem::factory()->for($quotation)->create();

        expect(fn () => $service->transitionStatus($quotation, QuotationStatus::Accepted))
            ->toThrow(InvalidArgumentException::class);
    });
});

test('quotation cannot transition without items', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $service = app(QuotationService::class);
        $quotation = Quotation::factory()->draft()->create();

        expect(fn () => $service->transitionStatus($quotation, QuotationStatus::Sent))
            ->toThrow(InvalidArgumentException::class, 'no line items');
    });
});

test('quotation item totals are recalculated correctly', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $service = app(QuotationService::class);
        $quotation = Quotation::factory()->draft()->create(['pricing_mode' => PricingMode::VatExclusive]);
        $vatRate = VatRate::factory()->create(['rate' => 20]);
        $item = QuotationItem::factory()->for($quotation)->create([
            'quantity' => '2.0000',
            'unit_price' => '100.0000',
            'discount_percent' => '10.00',
            'vat_rate_id' => $vatRate->id,
        ]);
        $item->load(['quotation', 'vatRate']);

        $service->recalculateItemTotals($item);

        // qty=2, price=100 → base=200, disc=10%=20, net=180, vat=20%=36, gross=216
        expect((float) $item->fresh()->line_total)->toBe(180.00)
            ->and((float) $item->fresh()->vat_amount)->toBe(36.00)
            ->and((float) $item->fresh()->line_total_with_vat)->toBe(216.00);
    });
});

test('quotation document totals are recalculated from items', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $service = app(QuotationService::class);
        $quotation = Quotation::factory()->draft()->create(['pricing_mode' => PricingMode::VatExclusive]);
        $vatRate = VatRate::factory()->create(['rate' => 20]);

        QuotationItem::factory()->for($quotation)->create([
            'line_total' => '180.00',
            'vat_amount' => '36.00',
            'vat_rate_id' => $vatRate->id,
        ]);
        QuotationItem::factory()->for($quotation)->create([
            'line_total' => '50.00',
            'vat_amount' => '10.00',
            'vat_rate_id' => $vatRate->id,
        ]);

        $service->recalculateDocumentTotals($quotation);

        expect((float) $quotation->fresh()->subtotal)->toBe(230.00)
            ->and((float) $quotation->fresh()->tax_amount)->toBe(46.00)
            ->and((float) $quotation->fresh()->total)->toBe(276.00);
    });
});

test('convert to sales order creates SO with all items linked', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $service = app(QuotationService::class);
        $quotation = Quotation::factory()->accepted()->create([
            'subtotal' => '500.00',
            'tax_amount' => '100.00',
            'total' => '600.00',
        ]);
        QuotationItem::factory()->for($quotation)->count(2)->create();
        $warehouse = Warehouse::where('is_default', true)->first();

        $salesOrder = $service->convertToSalesOrder($quotation, $warehouse);

        expect($salesOrder)->toBeInstanceOf(SalesOrder::class)
            ->and($salesOrder->partner_id)->toBe($quotation->partner_id)
            ->and($salesOrder->quotation_id)->toBe($quotation->id)
            ->and($salesOrder->warehouse_id)->toBe($warehouse->id)
            ->and($salesOrder->status)->toBe(SalesOrderStatus::Draft)
            ->and($salesOrder->items()->count())->toBe(2);

        // All SO items must be linked back to quotation items
        $salesOrder->items->each(fn ($soItem) => expect($soItem->quotation_item_id)->not->toBeNull());

        // Quotation status unchanged
        expect($quotation->fresh()->status)->toBe(QuotationStatus::Accepted);
    });
});
