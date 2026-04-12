<?php

declare(strict_types=1);

use App\Models\StockItem;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Policies\StockItemPolicy;
use App\Policies\StockLocationPolicy;
use App\Policies\StockMovementPolicy;
use App\Policies\WarehousePolicy;
use App\Services\TenantOnboardingService;

// --- Warehouse Policy ---

test('WarehousePolicy viewAny returns false without tenant context', function () {
    $user = User::factory()->create();
    expect((new WarehousePolicy)->viewAny($user))->toBeFalse();
});

test('WarehousePolicy all methods return true for admin in tenant context', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () use ($user) {
        $policy = new WarehousePolicy;
        $model = new Warehouse;

        expect($policy->viewAny($user))->toBeTrue()
            ->and($policy->view($user, $model))->toBeTrue()
            ->and($policy->create($user))->toBeTrue()
            ->and($policy->update($user, $model))->toBeTrue()
            ->and($policy->delete($user, $model))->toBeTrue()
            ->and($policy->restore($user, $model))->toBeTrue()
            ->and($policy->forceDelete($user, $model))->toBeTrue();
    });
});

// --- StockLocation Policy ---

test('StockLocationPolicy all methods return true for admin in tenant context', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () use ($user) {
        $policy = new StockLocationPolicy;
        $model = new StockLocation;

        expect($policy->viewAny($user))->toBeTrue()
            ->and($policy->create($user))->toBeTrue()
            ->and($policy->update($user, $model))->toBeTrue()
            ->and($policy->delete($user, $model))->toBeTrue();
    });
});

// --- StockItem Policy — delete/forceDelete always false ---

test('StockItemPolicy delete always returns false', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () use ($user) {
        $policy = new StockItemPolicy;
        $model = new StockItem;

        expect($policy->delete($user, $model))->toBeFalse()
            ->and($policy->forceDelete($user, $model))->toBeFalse()
            ->and($policy->restore($user, $model))->toBeFalse();
    });
});

test('StockItemPolicy view/create/update return true for admin', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () use ($user) {
        $policy = new StockItemPolicy;
        $model = new StockItem;

        expect($policy->viewAny($user))->toBeTrue()
            ->and($policy->view($user, $model))->toBeTrue()
            ->and($policy->create($user))->toBeTrue()
            ->and($policy->update($user, $model))->toBeTrue();
    });
});

// --- StockMovement Policy — update/delete always false ---

test('StockMovementPolicy update and delete always return false', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () use ($user) {
        $policy = new StockMovementPolicy;
        $model = new StockMovement;

        expect($policy->update($user, $model))->toBeFalse()
            ->and($policy->delete($user, $model))->toBeFalse()
            ->and($policy->forceDelete($user, $model))->toBeFalse()
            ->and($policy->restore($user, $model))->toBeFalse();
    });
});

test('StockMovementPolicy viewAny and create return true for admin', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () use ($user) {
        $policy = new StockMovementPolicy;
        $model = new StockMovement;

        expect($policy->viewAny($user))->toBeTrue()
            ->and($policy->view($user, $model))->toBeTrue()
            ->and($policy->create($user))->toBeTrue();
    });
});
