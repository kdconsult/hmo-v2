<?php

declare(strict_types=1);

use App\Models\ExchangeRate;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\ExchangeRatePolicy;
use App\Services\TenantOnboardingService;

// --- Without tenant context (returns false) ---

test('viewAny returns false without tenant context', function () {
    $user = User::factory()->create();

    expect((new ExchangeRatePolicy)->viewAny($user))->toBeFalse();
});

test('create returns false without tenant context', function () {
    $user = User::factory()->create();

    expect((new ExchangeRatePolicy)->create($user))->toBeFalse();
});

test('update returns false without tenant context', function () {
    $user = User::factory()->create();
    $model = new ExchangeRate;

    expect((new ExchangeRatePolicy)->update($user, $model))->toBeFalse();
});

test('delete returns false without tenant context', function () {
    $user = User::factory()->create();
    $model = new ExchangeRate;

    expect((new ExchangeRatePolicy)->delete($user, $model))->toBeFalse();
});

// --- With admin role in tenant context (returns true) ---

test('viewAny returns true for user with view_any_exchange_rate permission', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();

    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () use ($user) {
        expect((new ExchangeRatePolicy)->viewAny($user))->toBeTrue();
    });
});

test('view returns true for user with view_exchange_rate permission', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();

    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () use ($user) {
        expect((new ExchangeRatePolicy)->view($user, new ExchangeRate))->toBeTrue();
    });
});

test('create returns true for user with create_exchange_rate permission', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();

    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () use ($user) {
        expect((new ExchangeRatePolicy)->create($user))->toBeTrue();
    });
});

test('update returns true for user with update_exchange_rate permission', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();

    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () use ($user) {
        expect((new ExchangeRatePolicy)->update($user, new ExchangeRate))->toBeTrue();
    });
});

test('delete returns true for user with delete_exchange_rate permission', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();

    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () use ($user) {
        expect((new ExchangeRatePolicy)->delete($user, new ExchangeRate))->toBeTrue();
    });
});

test('restore returns true for user with delete_exchange_rate permission', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();

    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () use ($user) {
        expect((new ExchangeRatePolicy)->restore($user, new ExchangeRate))->toBeTrue();
    });
});

test('forceDelete returns true for user with delete_exchange_rate permission', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();

    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () use ($user) {
        expect((new ExchangeRatePolicy)->forceDelete($user, new ExchangeRate))->toBeTrue();
    });
});
