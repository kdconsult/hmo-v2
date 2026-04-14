<?php

declare(strict_types=1);

use App\Enums\DeliveryNoteStatus;
use App\Enums\MovementType;
use App\Enums\ProductType;
use App\Enums\SalesOrderStatus;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteItem;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VatRate;
use App\Models\Warehouse;
use App\Services\DeliveryNoteService;
use App\Services\SalesOrderService;
use App\Services\TenantOnboardingService;

test('confirming delivery note issues reserved stock from warehouse', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create();
        $variant = $product->defaultVariant;
        $warehouse = Warehouse::where('code', 'MAIN')->first();

        // First reserve stock via SalesOrder confirmation
        $vatRate = VatRate::factory()->create(['rate' => 0.00]);
        $so = SalesOrder::factory()->confirmed()->create(['warehouse_id' => $warehouse->id]);
        $soItem = SalesOrderItem::factory()->create([
            'sales_order_id' => $so->id,
            'product_variant_id' => $variant->id,
            'quantity' => '5.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        // Manually put stock in + reserved state (simulating SalesOrderService::reserveAllItems)
        $so->warehouse->stockItems()->updateOrCreate(
            ['product_variant_id' => $variant->id, 'warehouse_id' => $warehouse->id, 'stock_location_id' => null],
            ['quantity' => '10.0000', 'reserved_quantity' => '5.0000']
        );

        $dn = DeliveryNote::factory()->draft()->create(['warehouse_id' => $warehouse->id]);
        DeliveryNoteItem::factory()->create([
            'delivery_note_id' => $dn->id,
            'product_variant_id' => $variant->id,
            'quantity' => '5.0000',
        ]);

        app(DeliveryNoteService::class)->confirm($dn);

        $dn->refresh();
        expect($dn->status)->toBe(DeliveryNoteStatus::Confirmed)
            ->and($dn->delivered_at)->not->toBeNull();

        $stockItem = $variant->stockItems()->where('warehouse_id', $warehouse->id)->first();
        expect($stockItem)->not->toBeNull()
            ->and((float) $stockItem->quantity)->toBe(5.0)
            ->and((float) $stockItem->reserved_quantity)->toBe(0.0);
    });
});

test('confirming delivery note creates stock movement with delivery_note as morph reference', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create();
        $variant = $product->defaultVariant;
        $warehouse = Warehouse::where('code', 'MAIN')->first();

        $warehouse->stockItems()->updateOrCreate(
            ['product_variant_id' => $variant->id, 'warehouse_id' => $warehouse->id, 'stock_location_id' => null],
            ['quantity' => '10.0000', 'reserved_quantity' => '3.0000']
        );

        $dn = DeliveryNote::factory()->draft()->create(['warehouse_id' => $warehouse->id]);
        DeliveryNoteItem::factory()->create([
            'delivery_note_id' => $dn->id,
            'product_variant_id' => $variant->id,
            'quantity' => '3.0000',
        ]);

        app(DeliveryNoteService::class)->confirm($dn);

        $movement = StockMovement::first();
        expect($movement->type)->toBe(MovementType::Sale)
            ->and($movement->reference_type)->toBe('delivery_note')
            ->and($movement->reference_id)->toBe($dn->id);
    });
});

test('confirming delivery note fails when no items', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $warehouse = Warehouse::where('code', 'MAIN')->first();
        $dn = DeliveryNote::factory()->draft()->create(['warehouse_id' => $warehouse->id]);

        expect(fn () => app(DeliveryNoteService::class)->confirm($dn))
            ->toThrow(InvalidArgumentException::class, 'no items');
    });
});

test('confirming already confirmed delivery note throws exception', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $warehouse = Warehouse::where('code', 'MAIN')->first();
        $dn = DeliveryNote::factory()->confirmed()->create(['warehouse_id' => $warehouse->id]);

        expect(fn () => app(DeliveryNoteService::class)->confirm($dn))
            ->toThrow(InvalidArgumentException::class);
    });
});

test('service-type items are skipped during delivery note confirmation', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $serviceProduct = Product::factory()->create(['type' => ProductType::Service]);
        $serviceVariant = $serviceProduct->defaultVariant;
        $warehouse = Warehouse::where('code', 'MAIN')->first();

        $dn = DeliveryNote::factory()->draft()->create(['warehouse_id' => $warehouse->id]);
        DeliveryNoteItem::factory()->create([
            'delivery_note_id' => $dn->id,
            'product_variant_id' => $serviceVariant->id,
            'quantity' => '2.0000',
        ]);

        // Should not throw — no stock reserved needed for services
        app(DeliveryNoteService::class)->confirm($dn);

        expect($dn->fresh()->status)->toBe(DeliveryNoteStatus::Confirmed);
        expect(StockMovement::count())->toBe(0);
    });
});

test('confirming delivery note linked to SO updates SO delivered quantities and status', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create();
        $variant = $product->defaultVariant;
        $warehouse = Warehouse::where('code', 'MAIN')->first();
        $vatRate = VatRate::factory()->create(['rate' => 0.00]);

        $so = SalesOrder::factory()->confirmed()->create(['warehouse_id' => $warehouse->id]);
        $soItem = SalesOrderItem::factory()->create([
            'sales_order_id' => $so->id,
            'product_variant_id' => $variant->id,
            'quantity' => '10.0000',
            'qty_delivered' => '0.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        $warehouse->stockItems()->updateOrCreate(
            ['product_variant_id' => $variant->id, 'warehouse_id' => $warehouse->id, 'stock_location_id' => null],
            ['quantity' => '10.0000', 'reserved_quantity' => '6.0000']
        );

        $dn = DeliveryNote::factory()->draft()->create([
            'sales_order_id' => $so->id,
            'warehouse_id' => $warehouse->id,
        ]);
        DeliveryNoteItem::factory()->create([
            'delivery_note_id' => $dn->id,
            'sales_order_item_id' => $soItem->id,
            'product_variant_id' => $variant->id,
            'quantity' => '6.0000',
        ]);

        app(DeliveryNoteService::class)->confirm($dn);

        $soItem->refresh();
        $so->refresh();

        expect((float) $soItem->qty_delivered)->toBe(6.0)
            ->and($so->status)->toBe(SalesOrderStatus::PartiallyDelivered);
    });
});

test('confirming second delivery note completing all items marks SO as Delivered', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create();
        $variant = $product->defaultVariant;
        $warehouse = Warehouse::where('code', 'MAIN')->first();
        $vatRate = VatRate::factory()->create(['rate' => 0.00]);

        $so = SalesOrder::factory()->confirmed()->create(['warehouse_id' => $warehouse->id]);
        $soItem = SalesOrderItem::factory()->create([
            'sales_order_id' => $so->id,
            'product_variant_id' => $variant->id,
            'quantity' => '10.0000',
            'qty_delivered' => '0.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        $warehouse->stockItems()->updateOrCreate(
            ['product_variant_id' => $variant->id, 'warehouse_id' => $warehouse->id, 'stock_location_id' => null],
            ['quantity' => '10.0000', 'reserved_quantity' => '10.0000']
        );

        $service = app(DeliveryNoteService::class);

        // First DN: deliver 6 of 10
        $dn1 = DeliveryNote::factory()->draft()->create([
            'sales_order_id' => $so->id,
            'warehouse_id' => $warehouse->id,
        ]);
        DeliveryNoteItem::factory()->create([
            'delivery_note_id' => $dn1->id,
            'sales_order_item_id' => $soItem->id,
            'product_variant_id' => $variant->id,
            'quantity' => '6.0000',
        ]);
        $service->confirm($dn1);

        // Second DN: deliver remaining 4
        $dn2 = DeliveryNote::factory()->draft()->create([
            'sales_order_id' => $so->id,
            'warehouse_id' => $warehouse->id,
        ]);
        DeliveryNoteItem::factory()->create([
            'delivery_note_id' => $dn2->id,
            'sales_order_item_id' => $soItem->id,
            'product_variant_id' => $variant->id,
            'quantity' => '4.0000',
        ]);
        $service->confirm($dn2);

        $so->refresh();
        expect($so->status)->toBe(SalesOrderStatus::Delivered);
    });
});

test('standalone delivery note (no SO) confirms correctly without error', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create();
        $variant = $product->defaultVariant;
        $warehouse = Warehouse::where('code', 'MAIN')->first();

        $warehouse->stockItems()->updateOrCreate(
            ['product_variant_id' => $variant->id, 'warehouse_id' => $warehouse->id, 'stock_location_id' => null],
            ['quantity' => '5.0000', 'reserved_quantity' => '2.0000']
        );

        $dn = DeliveryNote::factory()->draft()->create([
            'sales_order_id' => null,
            'warehouse_id' => $warehouse->id,
        ]);
        DeliveryNoteItem::factory()->create([
            'delivery_note_id' => $dn->id,
            'product_variant_id' => $variant->id,
            'quantity' => '2.0000',
        ]);

        app(DeliveryNoteService::class)->confirm($dn);

        expect($dn->fresh()->status)->toBe(DeliveryNoteStatus::Confirmed);
    });
});

test('confirmed delivery note is not editable', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $warehouse = Warehouse::where('code', 'MAIN')->first();
        $dn = DeliveryNote::factory()->confirmed()->create(['warehouse_id' => $warehouse->id]);

        expect($dn->isEditable())->toBeFalse()
            ->and($dn->isConfirmed())->toBeTrue();
    });
});

test('draft delivery note can be cancelled', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $warehouse = Warehouse::where('code', 'MAIN')->first();
        $dn = DeliveryNote::factory()->draft()->create(['warehouse_id' => $warehouse->id]);

        app(DeliveryNoteService::class)->cancel($dn);

        expect($dn->fresh()->status)->toBe(DeliveryNoteStatus::Cancelled);
    });
});
