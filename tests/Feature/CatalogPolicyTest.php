<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Policies\CategoryPolicy;
use App\Policies\ProductPolicy;
use App\Policies\ProductVariantPolicy;
use App\Policies\UnitPolicy;
use App\Services\TenantOnboardingService;

// --- Category Policy ---

test('CategoryPolicy viewAny returns false without tenant context', function () {
    $user = User::factory()->create();
    expect((new CategoryPolicy)->viewAny($user))->toBeFalse();
});

test('CategoryPolicy all methods return true for admin in tenant context', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () use ($user) {
        $policy = new CategoryPolicy;
        $model = new Category;

        expect($policy->viewAny($user))->toBeTrue()
            ->and($policy->view($user, $model))->toBeTrue()
            ->and($policy->create($user))->toBeTrue()
            ->and($policy->update($user, $model))->toBeTrue()
            ->and($policy->delete($user, $model))->toBeTrue()
            ->and($policy->restore($user, $model))->toBeTrue()
            ->and($policy->forceDelete($user, $model))->toBeTrue();
    });
});

// --- Unit Policy ---

test('UnitPolicy viewAny returns false without tenant context', function () {
    $user = User::factory()->create();
    expect((new UnitPolicy)->viewAny($user))->toBeFalse();
});

test('UnitPolicy all methods return true for admin in tenant context', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () use ($user) {
        $policy = new UnitPolicy;
        $model = new Unit;

        expect($policy->viewAny($user))->toBeTrue()
            ->and($policy->view($user, $model))->toBeTrue()
            ->and($policy->create($user))->toBeTrue()
            ->and($policy->update($user, $model))->toBeTrue()
            ->and($policy->delete($user, $model))->toBeTrue();
    });
});

// --- Product Policy ---

test('ProductPolicy viewAny returns false without tenant context', function () {
    $user = User::factory()->create();
    expect((new ProductPolicy)->viewAny($user))->toBeFalse();
});

test('ProductPolicy all methods return true for admin in tenant context', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () use ($user) {
        $policy = new ProductPolicy;
        $model = new Product;

        expect($policy->viewAny($user))->toBeTrue()
            ->and($policy->view($user, $model))->toBeTrue()
            ->and($policy->create($user))->toBeTrue()
            ->and($policy->update($user, $model))->toBeTrue()
            ->and($policy->delete($user, $model))->toBeTrue();
    });
});

// --- ProductVariant Policy ---

test('ProductVariantPolicy viewAny returns false without tenant context', function () {
    $user = User::factory()->create();
    expect((new ProductVariantPolicy)->viewAny($user))->toBeFalse();
});

test('ProductVariantPolicy all methods return true for admin in tenant context', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () use ($user) {
        $policy = new ProductVariantPolicy;
        $model = new ProductVariant;

        expect($policy->viewAny($user))->toBeTrue()
            ->and($policy->view($user, $model))->toBeTrue()
            ->and($policy->create($user))->toBeTrue()
            ->and($policy->update($user, $model))->toBeTrue()
            ->and($policy->delete($user, $model))->toBeTrue();
    });
});
