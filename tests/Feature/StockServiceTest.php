<?php

declare(strict_types=1);

use App\Enums\MovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockService;
use App\Services\TenantOnboardingService;

test('receive creates stock item and movement', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create();
        $variant = $product->defaultVariant;
        $warehouse = Warehouse::where('code', 'MAIN')->first();

        $stockItem = app(StockService::class)->receive($variant, $warehouse, '10.0000');

        expect($stockItem->quantity)->toBe('10.0000')
            ->and($variant->stockMovements()->count())->toBe(1)
            ->and($variant->stockMovements()->first()->type)->toBe(MovementType::Purchase)
            ->and((float) $variant->stockMovements()->first()->quantity)->toBeGreaterThan(0);
    });
});

test('receive increments existing stock item', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create();
        $variant = $product->defaultVariant;
        $warehouse = Warehouse::where('code', 'MAIN')->first();
        $service = app(StockService::class);

        $service->receive($variant, $warehouse, '10.0000');
        $stockItem = $service->receive($variant, $warehouse, '5.0000');

        expect($stockItem->quantity)->toBe('15.0000');
    });
});

test('issue decrements stock and creates movement', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create();
        $variant = $product->defaultVariant;
        $warehouse = Warehouse::where('code', 'MAIN')->first();
        $service = app(StockService::class);

        $service->receive($variant, $warehouse, '20.0000');
        $stockItem = $service->issue($variant, $warehouse, '5.0000');

        expect($stockItem->quantity)->toBe('15.0000')
            ->and($variant->stockMovements()->where('type', MovementType::Sale->value)->count())->toBe(1);
    });
});

test('issue throws InsufficientStockException when stock is not enough', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create();
        $variant = $product->defaultVariant;
        $warehouse = Warehouse::where('code', 'MAIN')->first();

        $service = app(StockService::class);
        $service->receive($variant, $warehouse, '5.0000');

        expect(fn () => $service->issue($variant, $warehouse, '10.0000'))
            ->toThrow(InsufficientStockException::class);
    });
});

test('adjust with positive quantity increments stock', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create();
        $variant = $product->defaultVariant;
        $warehouse = Warehouse::where('code', 'MAIN')->first();

        $stockItem = app(StockService::class)->adjust($variant, $warehouse, '10.0000', 'Opening balance');

        expect($stockItem->quantity)->toBe('10.0000')
            ->and($variant->stockMovements()->where('type', MovementType::Adjustment->value)->count())->toBe(1);
    });
});

test('adjust with negative quantity decrements stock', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create();
        $variant = $product->defaultVariant;
        $warehouse = Warehouse::where('code', 'MAIN')->first();
        $service = app(StockService::class);

        $service->receive($variant, $warehouse, '20.0000');
        $stockItem = $service->adjust($variant, $warehouse, '-5.0000', 'Damage write-off');

        expect($stockItem->quantity)->toBe('15.0000');
    });
});

test('transfer moves stock between warehouses', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create();
        $variant = $product->defaultVariant;
        $from = Warehouse::where('code', 'MAIN')->first();
        $to = Warehouse::factory()->create(['code' => 'WH-02', 'is_default' => false]);
        $service = app(StockService::class);

        $service->receive($variant, $from, '30.0000');
        [$fromItem, $toItem] = $service->transfer($variant, $from, $to, '10.0000');

        expect($fromItem->quantity)->toBe('20.0000')
            ->and($toItem->quantity)->toBe('10.0000')
            ->and($variant->stockMovements()->where('type', MovementType::TransferOut->value)->count())->toBe(1)
            ->and($variant->stockMovements()->where('type', MovementType::TransferIn->value)->count())->toBe(1);
    });
});

test('transfer throws InsufficientStockException when source has insufficient stock', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create();
        $variant = $product->defaultVariant;
        $from = Warehouse::where('code', 'MAIN')->first();
        $to = Warehouse::factory()->create(['code' => 'WH-03', 'is_default' => false]);
        $service = app(StockService::class);

        $service->receive($variant, $from, '5.0000');

        expect(fn () => $service->transfer($variant, $from, $to, '10.0000'))
            ->toThrow(InsufficientStockException::class);
    });
});

test('receive with custom movement type records correct type', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create();
        $variant = $product->defaultVariant;
        $warehouse = Warehouse::where('code', 'MAIN')->first();

        app(StockService::class)->receive($variant, $warehouse, '10.0000', null, null, MovementType::Return);

        expect($variant->stockMovements()->first()->type)->toBe(MovementType::Return);
    });
});
