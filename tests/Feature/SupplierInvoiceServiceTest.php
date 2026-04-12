<?php

declare(strict_types=1);

use App\Enums\DocumentStatus;
use App\Enums\PricingMode;
use App\Models\Partner;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VatRate;
use App\Services\SupplierInvoiceService;
use App\Services\TenantOnboardingService;

test('recalculateItemTotals computes correct values for vat-exclusive pricing', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $vatRate = VatRate::factory()->create(['rate' => 20.00]);
        $partner = Partner::factory()->supplier()->create();
        $invoice = SupplierInvoice::factory()->create([
            'partner_id' => $partner->id,
            'pricing_mode' => PricingMode::VatExclusive,
            'discount_amount' => '0.00',
            'amount_paid' => '0.00',
        ]);

        $item = SupplierInvoiceItem::factory()->create([
            'supplier_invoice_id' => $invoice->id,
            'quantity' => '4.0000',
            'unit_price' => '100.0000',
            'discount_percent' => '10.00',
            'vat_rate_id' => $vatRate->id,
        ]);

        $item->loadMissing(['supplierInvoice', 'vatRate']);
        app(SupplierInvoiceService::class)->recalculateItemTotals($item);
        $item->refresh();

        // base = 4 * 100 = 400; discount = 40; net = 360; vat = 72; gross = 432
        expect((float) $item->discount_amount)->toBe(40.0)
            ->and((float) $item->line_total)->toBe(360.0)
            ->and((float) $item->vat_amount)->toBe(72.0)
            ->and((float) $item->line_total_with_vat)->toBe(432.0);
    });
});

test('recalculateItemTotals computes correct values for vat-inclusive pricing', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $vatRate = VatRate::factory()->create(['rate' => 20.00]);
        $partner = Partner::factory()->supplier()->create();
        $invoice = SupplierInvoice::factory()->create([
            'partner_id' => $partner->id,
            'pricing_mode' => PricingMode::VatInclusive,
            'discount_amount' => '0.00',
            'amount_paid' => '0.00',
        ]);

        $item = SupplierInvoiceItem::factory()->create([
            'supplier_invoice_id' => $invoice->id,
            'quantity' => '1.0000',
            'unit_price' => '120.0000',
            'discount_percent' => '0.00',
            'vat_rate_id' => $vatRate->id,
        ]);

        $item->loadMissing(['supplierInvoice', 'vatRate']);
        app(SupplierInvoiceService::class)->recalculateItemTotals($item);
        $item->refresh();

        // gross = 120, vat = 120 * 20/120 = 20, net = 100
        expect((float) $item->discount_amount)->toBe(0.0)
            ->and((float) $item->line_total)->toBe(100.0)
            ->and((float) $item->vat_amount)->toBe(20.0)
            ->and((float) $item->line_total_with_vat)->toBe(120.0);
    });
});

test('recalculateDocumentTotals aggregates items and updates amount_due', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $vatRate = VatRate::factory()->create(['rate' => 20.00]);
        $partner = Partner::factory()->supplier()->create();
        $invoice = SupplierInvoice::factory()->create([
            'partner_id' => $partner->id,
            'pricing_mode' => PricingMode::VatExclusive,
            'discount_amount' => '0.00',
            'amount_paid' => '10.00',
        ]);

        $service = app(SupplierInvoiceService::class);

        $item1 = SupplierInvoiceItem::factory()->create([
            'supplier_invoice_id' => $invoice->id,
            'quantity' => '2.0000',
            'unit_price' => '100.0000',
            'discount_percent' => '0.00',
            'vat_rate_id' => $vatRate->id,
        ]);
        $item1->loadMissing(['supplierInvoice', 'vatRate']);
        $service->recalculateItemTotals($item1);

        $item2 = SupplierInvoiceItem::factory()->create([
            'supplier_invoice_id' => $invoice->id,
            'quantity' => '1.0000',
            'unit_price' => '50.0000',
            'discount_percent' => '0.00',
            'vat_rate_id' => $vatRate->id,
        ]);
        $item2->loadMissing(['supplierInvoice', 'vatRate']);
        $service->recalculateItemTotals($item2);

        $service->recalculateDocumentTotals($invoice);
        $invoice->refresh();

        // item1: net=200, vat=40; item2: net=50, vat=10 → subtotal=250, tax=50, total=300, due=290
        expect((float) $invoice->subtotal)->toBe(250.0)
            ->and((float) $invoice->tax_amount)->toBe(50.0)
            ->and((float) $invoice->total)->toBe(300.0)
            ->and((float) $invoice->amount_due)->toBe(290.0);
    });
});

test('supplier invoice confirmed status makes it non-editable', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $invoice = SupplierInvoice::factory()->draft()->create();
        expect($invoice->isEditable())->toBeTrue();

        $invoice->status = DocumentStatus::Confirmed;
        $invoice->save();

        expect($invoice->fresh()->isEditable())->toBeFalse();
    });
});
