<?php

use App\Models\Currency;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\TenantOnboardingService;
use Spatie\Permission\Models\Role;

test('onboard creates TenantUser for the owner in tenant DB', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();

    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () use ($user) {
        $tenantUser = TenantUser::where('user_id', $user->id)->first();
        expect($tenantUser)->not->toBeNull()
            ->and($tenantUser->user_id)->toBe($user->id);
    });
});

test('onboard assigns admin role to the owner', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();

    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () use ($user) {
        $tenantUser = TenantUser::where('user_id', $user->id)->first();
        expect($tenantUser->hasRole('admin'))->toBeTrue();
    });
});

test('onboard seeds roles in tenant DB', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();

    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        expect(Role::where('name', 'admin')->exists())->toBeTrue()
            ->and(Role::where('name', 'sales-manager')->exists())->toBeTrue()
            ->and(Role::where('name', 'viewer')->exists())->toBeTrue();
    });
});

test('onboard seeds EUR as default currency in tenant DB', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();

    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $eur = Currency::where('code', 'EUR')->first();
        expect($eur)->not->toBeNull()
            ->and((bool) $eur->is_default)->toBeTrue();
    });
});

test('onboard does not create duplicate TenantUser if called twice', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $service = app(TenantOnboardingService::class);

    $service->onboard($tenant, $user);
    $service->onboard($tenant, $user);

    $tenant->run(function () use ($user) {
        expect(TenantUser::where('user_id', $user->id)->count())->toBe(1);
    });
});
