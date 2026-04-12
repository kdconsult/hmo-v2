<?php

declare(strict_types=1);

use App\Enums\PricingMode;
use App\Enums\PurchaseOrderStatus;
use App\Models\Partner;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VatRate;
use App\Services\PurchaseOrderService;
use App\Services\TenantOnboardingService;

test('purchase order can be created with required fields', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $partner = Partner::factory()->supplier()->create();

        $po = PurchaseOrder::create([
            'po_number' => 'PO-TEST-001',
            'partner_id' => $partner->id,
            'status' => PurchaseOrderStatus::Draft,
            'currency_code' => 'EUR',
            'exchange_rate' => '1.000000',
            'pricing_mode' => PricingMode::VatExclusive,
            'subtotal' => '0.00',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'total' => '0.00',
            'ordered_at' => now()->toDateString(),
        ]);

        expect($po->po_number)->toBe('PO-TEST-001')
            ->and($po->status)->toBe(PurchaseOrderStatus::Draft)
            ->and($po->isEditable())->toBeTrue();
    });
});

test('purchase order is not editable when confirmed', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $po = PurchaseOrder::factory()->confirmed()->create();

        expect($po->isEditable())->toBeFalse();
    });
});

test('purchase order status transitions are enforced', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $service = app(PurchaseOrderService::class);
        $po = PurchaseOrder::factory()->draft()->create();

        $service->transitionStatus($po, PurchaseOrderStatus::Sent);
        expect($po->fresh()->status)->toBe(PurchaseOrderStatus::Sent);

        $service->transitionStatus($po, PurchaseOrderStatus::Confirmed);
        expect($po->fresh()->status)->toBe(PurchaseOrderStatus::Confirmed);
    });
});

test('purchase order invalid transition throws exception', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $service = app(PurchaseOrderService::class);
        $po = PurchaseOrder::factory()->draft()->create();

        expect(fn () => $service->transitionStatus($po, PurchaseOrderStatus::Confirmed))
            ->toThrow(InvalidArgumentException::class);
    });
});

test('purchase order cannot transition from received status', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $service = app(PurchaseOrderService::class);
        $po = PurchaseOrder::factory()->received()->create();

        expect(fn () => $service->transitionStatus($po, PurchaseOrderStatus::Cancelled))
            ->toThrow(InvalidArgumentException::class);
    });
});

test('purchase order document totals recalculate correctly from items', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $service = app(PurchaseOrderService::class);
        $vatRate = VatRate::factory()->create(['rate' => 20.00]);
        $po = PurchaseOrder::factory()->create([
            'pricing_mode' => PricingMode::VatExclusive,
            'discount_amount' => '0.00',
        ]);

        // qty=2, unit_price=100, no discount, 20% VAT → net=200, VAT=40, total=240
        $item = PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $po->id,
            'quantity' => '2.0000',
            'unit_price' => '100.0000',
            'discount_percent' => '0.00',
            'vat_rate_id' => $vatRate->id,
            'vat_amount' => '0.00',
            'line_total' => '0.00',
            'line_total_with_vat' => '0.00',
        ]);

        $item->loadMissing(['purchaseOrder', 'vatRate']);
        $service->recalculateItemTotals($item);
        $service->recalculateDocumentTotals($po);

        $po->refresh();

        expect((float) $po->subtotal)->toBe(200.0)
            ->and((float) $po->tax_amount)->toBe(40.0)
            ->and((float) $po->total)->toBe(240.0);
    });
});

test('purchase order item remaining quantity decreases with quantity_received', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $vatRate = VatRate::factory()->create(['rate' => 0.00]);
        $po = PurchaseOrder::factory()->create();
        $item = PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $po->id,
            'quantity' => '10.0000',
            'quantity_received' => '3.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        expect($item->remainingQuantity())->toBe('7.0000')
            ->and($item->isFullyReceived())->toBeFalse();

        $item->quantity_received = '10.0000';
        $item->save();

        expect($item->fresh()->isFullyReceived())->toBeTrue();
    });
});

test('partner linked to purchase order must be a supplier', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $supplier = Partner::factory()->supplier()->create();
        $nonSupplier = Partner::factory()->create(['is_supplier' => false]);

        // Supplier partner is in the suppliers scope
        expect(Partner::suppliers()->where('id', $supplier->id)->exists())->toBeTrue();

        // Non-supplier partner is not in the suppliers scope
        expect(Partner::suppliers()->where('id', $nonSupplier->id)->exists())->toBeFalse();
    });
});

test('purchase order can be soft deleted and restored', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $po = PurchaseOrder::factory()->draft()->create(['po_number' => 'PO-DEL']);
        $po->delete();

        expect(PurchaseOrder::where('po_number', 'PO-DEL')->count())->toBe(0);

        $po->restore();
        expect(PurchaseOrder::where('po_number', 'PO-DEL')->count())->toBe(1);
    });
});
