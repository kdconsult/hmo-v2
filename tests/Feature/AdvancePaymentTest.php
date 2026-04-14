<?php

declare(strict_types=1);

use App\Enums\AdvancePaymentStatus;
use App\Enums\DocumentStatus;
use App\Enums\InvoiceType;
use App\Enums\PricingMode;
use App\Enums\SeriesType;
use App\Models\AdvancePayment;
use App\Models\AdvancePaymentApplication;
use App\Models\CustomerInvoice;
use App\Models\NumberSeries;
use App\Models\Partner;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VatRate;
use App\Services\AdvancePaymentService;
use App\Services\TenantOnboardingService;

test('advance payment can be created with required fields', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $partner = Partner::factory()->customer()->create();

        $ap = AdvancePayment::create([
            'ap_number' => 'AP-TEST-001',
            'partner_id' => $partner->id,
            'status' => AdvancePaymentStatus::Open,
            'currency_code' => 'EUR',
            'exchange_rate' => '1.000000',
            'amount' => '1000.00',
            'amount_applied' => '0.00',
            'received_at' => now()->toDateString(),
        ]);

        expect($ap->ap_number)->toBe('AP-TEST-001')
            ->and($ap->status)->toBe(AdvancePaymentStatus::Open)
            ->and($ap->isEditable())->toBeTrue()
            ->and($ap->remainingAmount())->toBe('1000.00');
    });
});

test('advance payment is only editable when open', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $ap = AdvancePayment::factory()->open()->create();
        expect($ap->isEditable())->toBeTrue();

        $ap->status = AdvancePaymentStatus::PartiallyApplied;
        expect($ap->isEditable())->toBeFalse();

        $ap->status = AdvancePaymentStatus::FullyApplied;
        expect($ap->isEditable())->toBeFalse();

        $ap->status = AdvancePaymentStatus::Refunded;
        expect($ap->isEditable())->toBeFalse();
    });
});

test('remainingAmount returns correct balance after partial application', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $ap = AdvancePayment::factory()->create([
            'amount' => '1000.00',
            'amount_applied' => '400.00',
        ]);

        expect($ap->remainingAmount())->toBe('600.00')
            ->and($ap->isFullyApplied())->toBeFalse();

        $ap->amount_applied = '1000.00';
        expect($ap->isFullyApplied())->toBeTrue()
            ->and($ap->remainingAmount())->toBe('0.00');
    });
});

test('createAdvanceInvoice creates a confirmed customer invoice of type advance', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        VatRate::factory()->standard()->create();
        NumberSeries::factory()->forType(SeriesType::Invoice)->create();

        $ap = AdvancePayment::factory()->create([
            'amount' => '1000.00',
            'currency_code' => 'EUR',
            'exchange_rate' => '1.000000',
            'received_at' => '2026-04-14',
        ]);

        $invoice = app(AdvancePaymentService::class)->createAdvanceInvoice($ap);

        expect($invoice)->toBeInstanceOf(CustomerInvoice::class)
            ->and($invoice->status)->toBe(DocumentStatus::Confirmed)
            ->and($invoice->invoice_type)->toBe(InvoiceType::Advance)
            ->and($invoice->partner_id)->toBe($ap->partner_id);
    });
});

test('createAdvanceInvoice links invoice back to advance payment', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        VatRate::factory()->standard()->create();
        NumberSeries::factory()->forType(SeriesType::Invoice)->create();

        $ap = AdvancePayment::factory()->create(['amount' => '500.00']);
        $invoice = app(AdvancePaymentService::class)->createAdvanceInvoice($ap);

        $ap->refresh();
        expect($ap->customer_invoice_id)->toBe($invoice->id);
    });
});

test('createAdvanceInvoice computes correct item and document totals', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        VatRate::factory()->standard()->create(); // 20% standard
        NumberSeries::factory()->forType(SeriesType::Invoice)->create();

        $ap = AdvancePayment::factory()->create(['amount' => '1000.00']);
        $invoice = app(AdvancePaymentService::class)->createAdvanceInvoice($ap);

        $invoice->refresh()->load('items');
        $item = $invoice->items->first();

        // VatExclusive: net=1000, vat=200, gross=1200
        expect((float) $item->unit_price)->toBe(1000.0)
            ->and((float) $item->line_total)->toBe(1000.0)
            ->and((float) $item->vat_amount)->toBe(200.0)
            ->and((float) $item->line_total_with_vat)->toBe(1200.0)
            ->and((float) $invoice->subtotal)->toBe(1000.0)
            ->and((float) $invoice->tax_amount)->toBe(200.0)
            ->and((float) $invoice->total)->toBe(1200.0);
    });
});

test('createAdvanceInvoice throws if advance invoice already exists', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        VatRate::factory()->standard()->create();
        NumberSeries::factory()->forType(SeriesType::Invoice)->create();

        $ap = AdvancePayment::factory()->create(['amount' => '500.00']);
        app(AdvancePaymentService::class)->createAdvanceInvoice($ap);

        // Second call should throw
        expect(fn () => app(AdvancePaymentService::class)->createAdvanceInvoice($ap))
            ->toThrow(InvalidArgumentException::class);
    });
});

test('createAdvanceInvoice throws if no invoice number series configured', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        VatRate::factory()->standard()->create();
        // No number series created intentionally

        $ap = AdvancePayment::factory()->create(['amount' => '500.00']);

        expect(fn () => app(AdvancePaymentService::class)->createAdvanceInvoice($ap))
            ->toThrow(InvalidArgumentException::class);
    });
});

test('applyToFinalInvoice creates a negative deduction row on the invoice', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        NumberSeries::factory()->forType(SeriesType::Invoice)->create();

        $partner = Partner::factory()->customer()->create();
        $ap = AdvancePayment::factory()->create([
            'partner_id' => $partner->id,
            'amount' => '1000.00',
        ]);
        app(AdvancePaymentService::class)->createAdvanceInvoice($ap);

        $finalInvoice = CustomerInvoice::create([
            'invoice_number' => 'INV-FINAL-001',
            'partner_id' => $partner->id,
            'status' => DocumentStatus::Draft,
            'invoice_type' => InvoiceType::Standard,
            'currency_code' => 'EUR',
            'exchange_rate' => '1.000000',
            'pricing_mode' => PricingMode::VatExclusive,
            'subtotal' => '0.00',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'total' => '0.00',
            'amount_paid' => '0.00',
            'amount_due' => '0.00',
        ]);

        $ap->refresh()->loadMissing('advanceInvoice.items');
        $expectedVatRateId = $ap->advanceInvoice->items->first()->vat_rate_id;

        app(AdvancePaymentService::class)->applyToFinalInvoice($ap, $finalInvoice, '500.00');

        $finalInvoice->refresh()->load('items');
        $deductionItem = $finalInvoice->items->first();

        expect($deductionItem)->not->toBeNull()
            ->and((float) $deductionItem->quantity)->toBe(-1.0)
            ->and((float) $deductionItem->unit_price)->toBe(500.0)
            ->and($deductionItem->vat_rate_id)->toBe($expectedVatRateId)
            ->and((float) $deductionItem->line_total)->toBeLessThan(0.0)
            ->and((float) $deductionItem->vat_amount)->toBeLessThan(0.0);
    });
});

test('applyToFinalInvoice creates an application record', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        VatRate::factory()->standard()->create();
        NumberSeries::factory()->forType(SeriesType::Invoice)->create();

        $partner = Partner::factory()->customer()->create();
        $ap = AdvancePayment::factory()->create([
            'partner_id' => $partner->id,
            'amount' => '1000.00',
        ]);
        app(AdvancePaymentService::class)->createAdvanceInvoice($ap);

        $finalInvoice = CustomerInvoice::create([
            'invoice_number' => 'INV-FINAL-002',
            'partner_id' => $partner->id,
            'status' => DocumentStatus::Draft,
            'invoice_type' => InvoiceType::Standard,
            'currency_code' => 'EUR',
            'exchange_rate' => '1.000000',
            'pricing_mode' => PricingMode::VatExclusive,
            'subtotal' => '0.00',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'total' => '0.00',
            'amount_paid' => '0.00',
            'amount_due' => '0.00',
        ]);

        $ap->refresh();
        $application = app(AdvancePaymentService::class)->applyToFinalInvoice($ap, $finalInvoice, '400.00');

        expect($application)->toBeInstanceOf(AdvancePaymentApplication::class)
            ->and($application->advance_payment_id)->toBe($ap->id)
            ->and($application->customer_invoice_id)->toBe($finalInvoice->id)
            ->and((float) $application->amount_applied)->toBe(400.0);
    });
});

test('applyToFinalInvoice transitions to PartiallyApplied then FullyApplied', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        VatRate::factory()->standard()->create();
        NumberSeries::factory()->forType(SeriesType::Invoice)->create();

        $partner = Partner::factory()->customer()->create();
        $ap = AdvancePayment::factory()->create([
            'partner_id' => $partner->id,
            'amount' => '1000.00',
        ]);
        app(AdvancePaymentService::class)->createAdvanceInvoice($ap);
        $ap->refresh();

        $makeInvoice = fn (string $number) => CustomerInvoice::create([
            'invoice_number' => $number,
            'partner_id' => $partner->id,
            'status' => DocumentStatus::Draft,
            'invoice_type' => InvoiceType::Standard,
            'currency_code' => 'EUR',
            'exchange_rate' => '1.000000',
            'pricing_mode' => PricingMode::VatExclusive,
            'subtotal' => '0.00',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'total' => '0.00',
            'amount_paid' => '0.00',
            'amount_due' => '0.00',
        ]);

        $inv1 = $makeInvoice('INV-PA-001');
        app(AdvancePaymentService::class)->applyToFinalInvoice($ap, $inv1, '600.00');
        $ap->refresh();

        expect($ap->status)->toBe(AdvancePaymentStatus::PartiallyApplied)
            ->and((float) $ap->amount_applied)->toBe(600.0);

        $inv2 = $makeInvoice('INV-FA-001');
        app(AdvancePaymentService::class)->applyToFinalInvoice($ap, $inv2, '400.00');
        $ap->refresh();

        expect($ap->status)->toBe(AdvancePaymentStatus::FullyApplied)
            ->and((float) $ap->amount_applied)->toBe(1000.0);
    });
});

test('applyToFinalInvoice throws when amount exceeds remaining balance', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        VatRate::factory()->standard()->create();
        NumberSeries::factory()->forType(SeriesType::Invoice)->create();

        $partner = Partner::factory()->customer()->create();
        $ap = AdvancePayment::factory()->create([
            'partner_id' => $partner->id,
            'amount' => '500.00',
        ]);
        app(AdvancePaymentService::class)->createAdvanceInvoice($ap);
        $ap->refresh();

        $finalInvoice = CustomerInvoice::create([
            'invoice_number' => 'INV-OVR-001',
            'partner_id' => $partner->id,
            'status' => DocumentStatus::Draft,
            'invoice_type' => InvoiceType::Standard,
            'currency_code' => 'EUR',
            'exchange_rate' => '1.000000',
            'pricing_mode' => PricingMode::VatExclusive,
            'subtotal' => '0.00',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'total' => '0.00',
            'amount_paid' => '0.00',
            'amount_due' => '0.00',
        ]);

        expect(fn () => app(AdvancePaymentService::class)->applyToFinalInvoice($ap, $finalInvoice, '600.00'))
            ->toThrow(InvalidArgumentException::class);
    });
});

test('applyToFinalInvoice throws if advance payment is refunded', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        VatRate::factory()->standard()->create();
        NumberSeries::factory()->forType(SeriesType::Invoice)->create();

        $partner = Partner::factory()->customer()->create();
        $ap = AdvancePayment::factory()->refunded()->create([
            'partner_id' => $partner->id,
        ]);

        $finalInvoice = CustomerInvoice::create([
            'invoice_number' => 'INV-REF-001',
            'partner_id' => $partner->id,
            'status' => DocumentStatus::Draft,
            'invoice_type' => InvoiceType::Standard,
            'currency_code' => 'EUR',
            'exchange_rate' => '1.000000',
            'pricing_mode' => PricingMode::VatExclusive,
            'subtotal' => '0.00',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'total' => '0.00',
            'amount_paid' => '0.00',
            'amount_due' => '0.00',
        ]);

        expect(fn () => app(AdvancePaymentService::class)->applyToFinalInvoice($ap, $finalInvoice, '100.00'))
            ->toThrow(InvalidArgumentException::class);
    });
});

test('refund transitions status to Refunded', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $ap = AdvancePayment::factory()->open()->create();
        app(AdvancePaymentService::class)->refund($ap);

        expect($ap->status)->toBe(AdvancePaymentStatus::Refunded);
    });
});

test('refund throws if advance payment is already fully applied', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $ap = AdvancePayment::factory()->fullyApplied()->create();

        expect(fn () => app(AdvancePaymentService::class)->refund($ap))
            ->toThrow(InvalidArgumentException::class);
    });
});

test('partially applied advance payment can be refunded', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $ap = AdvancePayment::factory()->partiallyApplied()->create();
        app(AdvancePaymentService::class)->refund($ap);

        expect($ap->status)->toBe(AdvancePaymentStatus::Refunded);
    });
});
