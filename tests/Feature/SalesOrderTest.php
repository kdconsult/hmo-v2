<?php

declare(strict_types=1);

use App\Enums\DeliveryNoteStatus;
use App\Enums\DocumentStatus;
use App\Enums\PricingMode;
use App\Enums\SalesOrderStatus;
use App\Exceptions\InsufficientStockException;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteItem;
use App\Models\Partner;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VatRate;
use App\Models\Warehouse;
use App\Services\SalesOrderService;
use App\Services\StockService;
use App\Services\TenantOnboardingService;

test('sales order can be created with required fields', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $partner = Partner::factory()->customer()->create();
        $warehouse = Warehouse::where('is_default', true)->first();

        $order = SalesOrder::create([
            'so_number' => 'SO-TEST-001',
            'partner_id' => $partner->id,
            'warehouse_id' => $warehouse->id,
            'status' => SalesOrderStatus::Draft,
            'currency_code' => 'EUR',
            'exchange_rate' => '1.000000',
            'pricing_mode' => PricingMode::VatExclusive,
            'subtotal' => '0.00',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'total' => '0.00',
        ]);

        expect($order->so_number)->toBe('SO-TEST-001')
            ->and($order->status)->toBe(SalesOrderStatus::Draft)
            ->and($order->isEditable())->toBeTrue();
    });
});

test('sales order is not editable when confirmed', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $order = SalesOrder::factory()->confirmed()->create();

        expect($order->isEditable())->toBeFalse();
    });
});

test('sales order item totals are recalculated correctly', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $service = app(SalesOrderService::class);
        $order = SalesOrder::factory()->draft()->create(['pricing_mode' => PricingMode::VatExclusive]);
        $vatRate = VatRate::factory()->create(['rate' => 20]);
        $item = SalesOrderItem::factory()->for($order)->create([
            'quantity' => '2.0000',
            'unit_price' => '100.0000',
            'discount_percent' => '10.00',
            'vat_rate_id' => $vatRate->id,
        ]);
        $item->load(['salesOrder', 'vatRate']);

        $service->recalculateItemTotals($item);

        // qty=2, price=100 → base=200, disc=10%=20, net=180, vat=20%=36, gross=216
        expect((float) $item->fresh()->line_total)->toBe(180.00)
            ->and((float) $item->fresh()->vat_amount)->toBe(36.00)
            ->and((float) $item->fresh()->line_total_with_vat)->toBe(216.00);
    });
});

test('sales order document totals are recalculated from items', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $service = app(SalesOrderService::class);
        $order = SalesOrder::factory()->draft()->create(['pricing_mode' => PricingMode::VatExclusive]);
        $vatRate = VatRate::factory()->create(['rate' => 20]);

        SalesOrderItem::factory()->for($order)->create([
            'line_total' => '180.00',
            'vat_amount' => '36.00',
            'vat_rate_id' => $vatRate->id,
        ]);
        SalesOrderItem::factory()->for($order)->create([
            'line_total' => '50.00',
            'vat_amount' => '10.00',
            'vat_rate_id' => $vatRate->id,
        ]);

        $service->recalculateDocumentTotals($order);

        expect((float) $order->fresh()->subtotal)->toBe(230.00)
            ->and((float) $order->fresh()->tax_amount)->toBe(46.00)
            ->and((float) $order->fresh()->total)->toBe(276.00);
    });
});

test('sales order transitions draft to confirmed and reserves stock', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $service = app(SalesOrderService::class);
        $product = Product::factory()->stock()->create();
        $variant = $product->defaultVariant;
        $warehouse = Warehouse::where('is_default', true)->first();

        app(StockService::class)->receive($variant, $warehouse, '10.0000');

        $order = SalesOrder::factory()->draft()->create(['warehouse_id' => $warehouse->id]);
        $vatRate = VatRate::factory()->create(['rate' => 20]);
        SalesOrderItem::factory()->for($order)->create([
            'product_variant_id' => $variant->id,
            'quantity' => '3.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        $service->transitionStatus($order, SalesOrderStatus::Confirmed);

        expect($order->fresh()->status)->toBe(SalesOrderStatus::Confirmed);

        $stockItem = $variant->stockItems()->where('warehouse_id', $warehouse->id)->first();
        expect((float) $stockItem->fresh()->reserved_quantity)->toBe(3.0);
    });
});

test('sales order transition without items throws exception', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $service = app(SalesOrderService::class);
        $order = SalesOrder::factory()->draft()->create();

        expect(fn () => $service->transitionStatus($order, SalesOrderStatus::Confirmed))
            ->toThrow(InvalidArgumentException::class, 'no line items');
    });
});

test('sales order invalid transition throws exception', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $service = app(SalesOrderService::class);
        $order = SalesOrder::factory()->draft()->create();
        SalesOrderItem::factory()->for($order)->create();

        expect(fn () => $service->transitionStatus($order, SalesOrderStatus::Delivered))
            ->toThrow(InvalidArgumentException::class);
    });
});

test('sales order confirmation fails when insufficient stock', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $service = app(SalesOrderService::class);
        $product = Product::factory()->stock()->create();
        $variant = $product->defaultVariant;
        $warehouse = Warehouse::where('is_default', true)->first();

        $order = SalesOrder::factory()->draft()->create(['warehouse_id' => $warehouse->id]);
        $vatRate = VatRate::factory()->create(['rate' => 20]);
        SalesOrderItem::factory()->for($order)->create([
            'product_variant_id' => $variant->id,
            'quantity' => '5.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        expect(fn () => $service->transitionStatus($order, SalesOrderStatus::Confirmed))
            ->toThrow(InsufficientStockException::class);
    });
});

test('reserveAllItems skips non-stock type products', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $service = app(SalesOrderService::class);
        $serviceProduct = Product::factory()->service()->create();
        $variant = $serviceProduct->defaultVariant;
        $warehouse = Warehouse::where('is_default', true)->first();

        $order = SalesOrder::factory()->draft()->create(['warehouse_id' => $warehouse->id]);
        $vatRate = VatRate::factory()->create(['rate' => 20]);
        SalesOrderItem::factory()->for($order)->create([
            'product_variant_id' => $variant->id,
            'quantity' => '2.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        // Should not throw InsufficientStockException for service items
        $service->transitionStatus($order, SalesOrderStatus::Confirmed);

        expect($order->fresh()->status)->toBe(SalesOrderStatus::Confirmed);
    });
});

test('cancelled order unreserves remaining items using qty minus qty_delivered', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $service = app(SalesOrderService::class);
        $product = Product::factory()->stock()->create();
        $variant = $product->defaultVariant;
        $warehouse = Warehouse::where('is_default', true)->first();

        app(StockService::class)->receive($variant, $warehouse, '10.0000');

        $order = SalesOrder::factory()->confirmed()->create(['warehouse_id' => $warehouse->id]);
        $vatRate = VatRate::factory()->create(['rate' => 20]);
        SalesOrderItem::factory()->for($order)->create([
            'product_variant_id' => $variant->id,
            'quantity' => '5.0000',
            'qty_delivered' => '2.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        // Manually reserve the full qty to simulate confirmed state
        $stockService = app(StockService::class);
        $stockService->reserve($variant, $warehouse, '5.0000', $order);

        $stockItem = $variant->stockItems()->where('warehouse_id', $warehouse->id)->first();
        $reservedBefore = (float) $stockItem->fresh()->reserved_quantity;

        $service->transitionStatus($order, SalesOrderStatus::Cancelled);

        // Should unreserve 3 (qty 5 - qty_delivered 2), not 5
        $reservedAfter = (float) $stockItem->fresh()->reserved_quantity;
        expect($reservedAfter)->toBe($reservedBefore - 3.0);
    });
});

test('cannot cancel order with confirmed delivery notes', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $service = app(SalesOrderService::class);
        $order = SalesOrder::factory()->confirmed()->create();

        DeliveryNote::factory()->confirmed()->create([
            'sales_order_id' => $order->id,
            'partner_id' => $order->partner_id,
            'warehouse_id' => $order->warehouse_id,
        ]);

        expect(fn () => $service->transitionStatus($order, SalesOrderStatus::Cancelled))
            ->toThrow(InvalidArgumentException::class, 'deliveries have already been confirmed');
    });
});

test('cancel cascades to draft delivery notes and draft customer invoices', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $service = app(SalesOrderService::class);
        $order = SalesOrder::factory()->confirmed()->create();

        $dn = DeliveryNote::factory()->draft()->create([
            'sales_order_id' => $order->id,
            'partner_id' => $order->partner_id,
            'warehouse_id' => $order->warehouse_id,
        ]);
        $invoice = CustomerInvoice::factory()->draft()->create([
            'sales_order_id' => $order->id,
            'partner_id' => $order->partner_id,
        ]);

        $service->transitionStatus($order, SalesOrderStatus::Cancelled);

        expect($dn->fresh()->status)->toBe(DeliveryNoteStatus::Cancelled)
            ->and($invoice->fresh()->status)->toBe(DocumentStatus::Cancelled)
            ->and($order->fresh()->status)->toBe(SalesOrderStatus::Cancelled);
    });
});

test('updateDeliveredQuantities sums confirmed dn items and transitions to partially delivered', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $service = app(SalesOrderService::class);
        $order = SalesOrder::factory()->confirmed()->create();
        $vatRate = VatRate::factory()->create(['rate' => 20]);
        $item = SalesOrderItem::factory()->for($order)->create([
            'quantity' => '10.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        $dn = DeliveryNote::factory()->confirmed()->create([
            'sales_order_id' => $order->id,
            'partner_id' => $order->partner_id,
            'warehouse_id' => $order->warehouse_id,
        ]);
        DeliveryNoteItem::factory()->for($dn)->create([
            'sales_order_item_id' => $item->id,
            'product_variant_id' => $item->product_variant_id,
            'quantity' => '4.0000',
        ]);

        $service->updateDeliveredQuantities($order);

        expect((float) $item->fresh()->qty_delivered)->toBe(4.0)
            ->and($order->fresh()->status)->toBe(SalesOrderStatus::PartiallyDelivered);
    });
});

test('updateDeliveredQuantities transitions to delivered when fully delivered', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $service = app(SalesOrderService::class);
        $order = SalesOrder::factory()->confirmed()->create();
        $vatRate = VatRate::factory()->create(['rate' => 20]);
        $item = SalesOrderItem::factory()->for($order)->create([
            'quantity' => '5.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        $dn = DeliveryNote::factory()->confirmed()->create([
            'sales_order_id' => $order->id,
            'partner_id' => $order->partner_id,
            'warehouse_id' => $order->warehouse_id,
        ]);
        DeliveryNoteItem::factory()->for($dn)->create([
            'sales_order_item_id' => $item->id,
            'product_variant_id' => $item->product_variant_id,
            'quantity' => '5.0000',
        ]);

        $service->updateDeliveredQuantities($order);

        expect((float) $item->fresh()->qty_delivered)->toBe(5.0)
            ->and($order->fresh()->status)->toBe(SalesOrderStatus::Delivered);
    });
});

test('updateDeliveredQuantities ignores cancelled delivery note items', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $service = app(SalesOrderService::class);
        $order = SalesOrder::factory()->confirmed()->create();
        $vatRate = VatRate::factory()->create(['rate' => 20]);
        $item = SalesOrderItem::factory()->for($order)->create([
            'quantity' => '5.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        $cancelledDn = DeliveryNote::factory()->cancelled()->create([
            'sales_order_id' => $order->id,
            'partner_id' => $order->partner_id,
            'warehouse_id' => $order->warehouse_id,
        ]);
        DeliveryNoteItem::factory()->for($cancelledDn)->create([
            'sales_order_item_id' => $item->id,
            'product_variant_id' => $item->product_variant_id,
            'quantity' => '5.0000',
        ]);

        $service->updateDeliveredQuantities($order);

        expect((float) $item->fresh()->qty_delivered)->toBe(0.0)
            ->and($order->fresh()->status)->toBe(SalesOrderStatus::Confirmed);
    });
});

test('updateInvoicedQuantities sums confirmed invoices and transitions to invoiced', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $service = app(SalesOrderService::class);
        $order = SalesOrder::factory()->delivered()->create();
        $vatRate = VatRate::factory()->create(['rate' => 20]);
        $item = SalesOrderItem::factory()->for($order)->create([
            'quantity' => '3.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        $invoice = CustomerInvoice::factory()->confirmed()->create([
            'sales_order_id' => $order->id,
            'partner_id' => $order->partner_id,
        ]);
        CustomerInvoiceItem::factory()->for($invoice)->create([
            'sales_order_item_id' => $item->id,
            'product_variant_id' => $item->product_variant_id,
            'quantity' => '3.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        $service->updateInvoicedQuantities($order);

        expect((float) $item->fresh()->qty_invoiced)->toBe(3.0)
            ->and($order->fresh()->status)->toBe(SalesOrderStatus::Invoiced);
    });
});

test('updateInvoicedQuantities ignores draft invoices', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $service = app(SalesOrderService::class);
        $order = SalesOrder::factory()->delivered()->create();
        $vatRate = VatRate::factory()->create(['rate' => 20]);
        $item = SalesOrderItem::factory()->for($order)->create([
            'quantity' => '3.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        $draftInvoice = CustomerInvoice::factory()->draft()->create([
            'sales_order_id' => $order->id,
            'partner_id' => $order->partner_id,
        ]);
        CustomerInvoiceItem::factory()->for($draftInvoice)->create([
            'sales_order_item_id' => $item->id,
            'product_variant_id' => $item->product_variant_id,
            'quantity' => '3.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        $service->updateInvoicedQuantities($order);

        expect((float) $item->fresh()->qty_invoiced)->toBe(0.0)
            ->and($order->fresh()->status)->toBe(SalesOrderStatus::Delivered);
    });
});
