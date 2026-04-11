<?php

declare(strict_types=1);

use App\Filament\Pages\CompanySettingsPage;
use App\Filament\Pages\SubscriptionPage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantOnboardingService;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

// --- G-3: CompanySettingsPage::canAccess() ---

test('CompanySettingsPage canAccess returns false for user without tenant context', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(CompanySettingsPage::canAccess())->toBeFalse();
});

test('CompanySettingsPage canAccess returns true for admin in tenant context', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();

    app(TenantOnboardingService::class)->onboard($tenant, $user);
    $this->actingAs($user);

    $tenant->run(function () {
        expect(CompanySettingsPage::canAccess())->toBeTrue();
    });
});

// --- G-4: SubscriptionPage::cancelSubscription() role guard ---

test('cancelSubscription is blocked for user without admin role', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $this->actingAs($user);

    tenancy()->initialize($tenant);

    Livewire::test(SubscriptionPage::class)
        ->call('cancelSubscription')
        ->assertNotified('Unauthorized');
});

test('cancelSubscription is allowed for admin role', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();

    app(TenantOnboardingService::class)->onboard($tenant, $user);
    $this->actingAs($user);

    tenancy()->initialize($tenant);

    // cancelSubscription proceeds past the role check (SubscriptionService is called).
    // We just verify no "Unauthorized" notification is sent.
    Livewire::test(SubscriptionPage::class)
        ->call('cancelSubscription')
        ->assertNotNotified('Unauthorized');
});
