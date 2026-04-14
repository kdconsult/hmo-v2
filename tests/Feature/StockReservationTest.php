<?php

declare(strict_types=1);

use App\Enums\MovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockService;
use App\Services\TenantOnboardingService;

test('reserve increases reserved_quantity without creating a movement', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create();
        $variant = $product->defaultVariant;
        $warehouse = Warehouse::where('code', 'MAIN')->first();
        $service = app(StockService::class);

        $service->receive($variant, $warehouse, '20.0000');

        $order = SalesOrder::factory()->create();
        $service->reserve($variant, $warehouse, '8.0000', $order);

        $stockItem = $variant->stockItems()->where('warehouse_id', $warehouse->id)->first();

        expect((float) $stockItem->reserved_quantity)->toBe(8.0)
            ->and((float) $stockItem->quantity)->toBe(20.0)
            ->and($variant->stockMovements()->count())->toBe(1); // only the receive, no reservation movement
    });
});

test('reserve throws InsufficientStockException when available stock is too low', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create();
        $variant = $product->defaultVariant;
        $warehouse = Warehouse::where('code', 'MAIN')->first();
        $service = app(StockService::class);

        $service->receive($variant, $warehouse, '5.0000');

        $order = SalesOrder::factory()->create();

        expect(fn () => $service->reserve($variant, $warehouse, '10.0000', $order))
            ->toThrow(InsufficientStockException::class);
    });
});

test('reserve accounts for already-reserved stock when checking availability', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create();
        $variant = $product->defaultVariant;
        $warehouse = Warehouse::where('code', 'MAIN')->first();
        $service = app(StockService::class);

        $service->receive($variant, $warehouse, '10.0000');
        $order = SalesOrder::factory()->create();
        $service->reserve($variant, $warehouse, '7.0000', $order); // 10 - 7 = 3 available

        // Requesting 5 should fail (only 3 available)
        expect(fn () => $service->reserve($variant, $warehouse, '5.0000', $order))
            ->toThrow(InsufficientStockException::class);
    });
});

test('unreserve decreases reserved_quantity without creating a movement', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create();
        $variant = $product->defaultVariant;
        $warehouse = Warehouse::where('code', 'MAIN')->first();
        $service = app(StockService::class);

        $service->receive($variant, $warehouse, '20.0000');
        $order = SalesOrder::factory()->create();
        $service->reserve($variant, $warehouse, '10.0000', $order);
        $service->unreserve($variant, $warehouse, '4.0000', $order);

        $stockItem = $variant->stockItems()->where('warehouse_id', $warehouse->id)->first();

        expect((float) $stockItem->reserved_quantity)->toBe(6.0)
            ->and((float) $stockItem->quantity)->toBe(20.0)
            ->and($variant->stockMovements()->count())->toBe(1); // only the receive
    });
});

test('unreserve floors at zero to avoid negative reserved_quantity', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create();
        $variant = $product->defaultVariant;
        $warehouse = Warehouse::where('code', 'MAIN')->first();
        $service = app(StockService::class);

        $service->receive($variant, $warehouse, '10.0000');
        $order = SalesOrder::factory()->create();
        $service->reserve($variant, $warehouse, '3.0000', $order);

        // Unreserve more than reserved — should floor at 0, not go negative
        $service->unreserve($variant, $warehouse, '10.0000', $order);

        $stockItem = $variant->stockItems()->where('warehouse_id', $warehouse->id)->first();
        expect((float) $stockItem->reserved_quantity)->toBe(0.0);
    });
});

test('issueReserved decrements both quantity and reserved_quantity atomically', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create();
        $variant = $product->defaultVariant;
        $warehouse = Warehouse::where('code', 'MAIN')->first();
        $service = app(StockService::class);

        $service->receive($variant, $warehouse, '20.0000');
        $order = SalesOrder::factory()->create();
        $service->reserve($variant, $warehouse, '8.0000', $order);

        $service->issueReserved($variant, $warehouse, '8.0000', $order);

        $stockItem = $variant->stockItems()->where('warehouse_id', $warehouse->id)->first();

        expect((float) $stockItem->quantity)->toBe(12.0)
            ->and((float) $stockItem->reserved_quantity)->toBe(0.0);
    });
});

test('issueReserved creates StockMovement with MovementType::Sale and negative quantity', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create();
        $variant = $product->defaultVariant;
        $warehouse = Warehouse::where('code', 'MAIN')->first();
        $service = app(StockService::class);

        $service->receive($variant, $warehouse, '20.0000');
        $order = SalesOrder::factory()->create();
        $service->reserve($variant, $warehouse, '8.0000', $order);

        $movement = $service->issueReserved($variant, $warehouse, '8.0000', $order);

        expect($movement->type)->toBe(MovementType::Sale)
            ->and((float) $movement->quantity)->toBe(-8.0)
            ->and($movement->reference_type)->toBe('sales_order')
            ->and($movement->reference_id)->toBe($order->id);
    });
});

test('issueReserved throws InsufficientStockException when reserved_quantity is insufficient', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create();
        $variant = $product->defaultVariant;
        $warehouse = Warehouse::where('code', 'MAIN')->first();
        $service = app(StockService::class);

        $service->receive($variant, $warehouse, '20.0000');
        $order = SalesOrder::factory()->create();
        $service->reserve($variant, $warehouse, '5.0000', $order);

        // Trying to issue 10 when only 5 are reserved
        expect(fn () => $service->issueReserved($variant, $warehouse, '10.0000', $order))
            ->toThrow(InsufficientStockException::class);
    });
});
