<?php

declare(strict_types=1);

use App\Enums\MovementType;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockService;
use App\Services\TenantOnboardingService;

test('stock movement cannot be updated', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create();
        $variant = $product->defaultVariant;
        $warehouse = Warehouse::where('code', 'MAIN')->first();

        app(StockService::class)->receive($variant, $warehouse, '10.0000');
        $movement = StockMovement::first();

        expect(fn () => $movement->update(['notes' => 'tampered']))
            ->toThrow(RuntimeException::class, 'Stock movements are immutable.');
    });
});

test('stock movement cannot be deleted', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create();
        $variant = $product->defaultVariant;
        $warehouse = Warehouse::where('code', 'MAIN')->first();

        app(StockService::class)->receive($variant, $warehouse, '10.0000');
        $movement = StockMovement::first();

        expect(fn () => $movement->delete())
            ->toThrow(RuntimeException::class, 'Stock movements are immutable.');
    });
});

test('stock movement records correct type and quantity', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create();
        $variant = $product->defaultVariant;
        $warehouse = Warehouse::where('code', 'MAIN')->first();

        app(StockService::class)->receive($variant, $warehouse, '25.5000');
        $movement = StockMovement::first();

        expect($movement->type)->toBe(MovementType::Purchase)
            ->and($movement->quantity)->toBe('25.5000')
            ->and($movement->product_variant_id)->toBe($variant->id)
            ->and($movement->warehouse_id)->toBe($warehouse->id);
    });
});

test('stock movement reference morph links to a model', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create();
        $variant = $product->defaultVariant;
        $warehouse = Warehouse::where('code', 'MAIN')->first();

        // Use the product itself as a stand-in reference (represents a future Invoice/PO)
        app(StockService::class)->receive($variant, $warehouse, '5.0000', null, $product);
        $movement = StockMovement::first();

        expect($movement->reference)->not->toBeNull()
            ->and($movement->reference->id)->toBe($product->id);
    });
});
