<?php

use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockService;
use App\Services\TenantOnboardingService;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($this->tenant, $this->user);
});

afterEach(function () {
    tenancy()->end();
});

it('can transfer stock between warehouses', function () {
    $this->tenant->run(function () {
        $product = Product::factory()->stock()->create();
        $mainWarehouse = Warehouse::where('code', 'MAIN')->first();
        $secondWarehouse = Warehouse::factory()->create();

        app(StockService::class)->receive(
            variant: $product->defaultVariant,
            warehouse: $mainWarehouse,
            quantity: '15.0000',
        );

        [$fromItem, $toItem] = app(StockService::class)->transfer(
            variant: $product->defaultVariant,
            fromWarehouse: $mainWarehouse,
            toWarehouse: $secondWarehouse,
            quantity: '6.0000',
        );

        expect($fromItem->fresh()->quantity)->toBe('9.0000')
            ->and($toItem->fresh()->quantity)->toBe('6.0000');
    });
});

it('throws InsufficientStockException when transferring more than available', function () {
    $this->tenant->run(function () {
        $product = Product::factory()->stock()->create();
        $mainWarehouse = Warehouse::where('code', 'MAIN')->first();
        $secondWarehouse = Warehouse::factory()->create();

        app(StockService::class)->receive(
            variant: $product->defaultVariant,
            warehouse: $mainWarehouse,
            quantity: '3.0000',
        );

        expect(fn () => app(StockService::class)->transfer(
            variant: $product->defaultVariant,
            fromWarehouse: $mainWarehouse,
            toWarehouse: $secondWarehouse,
            quantity: '10.0000',
        ))->toThrow(InsufficientStockException::class);
    });
});
