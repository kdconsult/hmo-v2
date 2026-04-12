<?php

declare(strict_types=1);

use App\Models\StockLocation;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\TenantOnboardingService;
use Illuminate\Database\QueryException;

test('warehouse can be created', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $warehouse = Warehouse::create([
            'name' => 'Test Warehouse',
            'code' => 'TEST-WH',
            'is_active' => true,
            'is_default' => false,
        ]);

        expect($warehouse->name)->toBe('Test Warehouse')
            ->and($warehouse->code)->toBe('TEST-WH');
    });
});

test('setting is_default unsets the previous default warehouse', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $first = Warehouse::where('is_default', true)->first();
        expect($first)->not->toBeNull();

        $second = Warehouse::factory()->create(['is_default' => false]);
        $second->is_default = true;
        $second->save();

        $first->refresh();
        expect($first->is_default)->toBeFalse()
            ->and($second->fresh()->is_default)->toBeTrue();
    });
});

test('warehouse codes are unique per tenant', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        Warehouse::create(['name' => 'First', 'code' => 'SAME', 'is_default' => false]);

        expect(fn () => Warehouse::create(['name' => 'Second', 'code' => 'SAME', 'is_default' => false]))
            ->toThrow(QueryException::class);
    });
});

test('warehouse can be soft deleted and restored', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $warehouse = Warehouse::factory()->create(['code' => 'DEL-WH', 'is_default' => false]);
        $warehouse->delete();

        expect(Warehouse::where('code', 'DEL-WH')->count())->toBe(0);

        $warehouse->restore();
        expect(Warehouse::where('code', 'DEL-WH')->count())->toBe(1);
    });
});

test('stock location belongs to warehouse', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $warehouse = Warehouse::where('code', 'MAIN')->first();
        $location = StockLocation::create([
            'warehouse_id' => $warehouse->id,
            'name' => 'Shelf A',
            'code' => 'SHF-A',
            'is_active' => true,
        ]);

        expect($location->warehouse->id)->toBe($warehouse->id)
            ->and($warehouse->stockLocations()->count())->toBe(1);
    });
});

test('stock location codes are unique per warehouse', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $warehouse = Warehouse::where('code', 'MAIN')->first();
        StockLocation::create(['warehouse_id' => $warehouse->id, 'name' => 'A', 'code' => 'DUP']);

        expect(fn () => StockLocation::create(['warehouse_id' => $warehouse->id, 'name' => 'B', 'code' => 'DUP']))
            ->toThrow(QueryException::class);
    });
});
