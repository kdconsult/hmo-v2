<?php

use App\Enums\SubscriptionStatus;
use App\Mail\TrialExpired;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\PlanLimitService;
use Illuminate\Support\Facades\Mail;

// --- CheckTrialExpirations ---

test('check-trial-expirations marks expired trials as past_due', function () {
    Mail::fake();

    $expired = Tenant::factory()->create([
        'subscription_status' => SubscriptionStatus::Trial,
        'trial_ends_at' => now()->subDay(),
    ]);

    $stillValid = Tenant::factory()->create([
        'subscription_status' => SubscriptionStatus::Trial,
        'trial_ends_at' => now()->addDays(5),
    ]);

    $this->artisan('app:check-trial-expirations')->assertSuccessful();

    expect($expired->fresh()->subscription_status)->toBe(SubscriptionStatus::PastDue)
        ->and($stillValid->fresh()->subscription_status)->toBe(SubscriptionStatus::Trial);
});

test('check-trial-expirations sends TrialExpired mail for each expired tenant', function () {
    Mail::fake();

    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create([
        'subscription_status' => SubscriptionStatus::Trial,
        'trial_ends_at' => now()->subDay(),
    ]);
    $tenant->users()->attach($owner->id);

    $this->artisan('app:check-trial-expirations')->assertSuccessful();

    Mail::assertQueued(TrialExpired::class, fn ($mail) => $mail->hasTo($owner->email));
});

test('check-trial-expirations does not affect non-trial tenants', function () {
    Mail::fake();

    $active = Tenant::factory()->create([
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_ends_at' => now()->subDay(), // expired, but not a trial
    ]);

    $this->artisan('app:check-trial-expirations')->assertSuccessful();

    // Should not be touched by trial expiration command
    expect($active->fresh()->subscription_status)->toBe(SubscriptionStatus::Active);
});

// --- CheckSubscriptionExpirations ---

test('check-subscription-expirations marks active tenants past_due when subscription_ends_at has passed', function () {
    $expired = Tenant::factory()->create([
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_ends_at' => now()->subDay(),
    ]);

    $stillValid = Tenant::factory()->create([
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_ends_at' => now()->addDays(5),
    ]);

    $noExpiry = Tenant::factory()->create([
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_ends_at' => null,
    ]);

    $this->artisan('app:check-subscription-expirations')->assertSuccessful();

    expect($expired->fresh()->subscription_status)->toBe(SubscriptionStatus::PastDue)
        ->and($stillValid->fresh()->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($noExpiry->fresh()->subscription_status)->toBe(SubscriptionStatus::Active);
});

// --- PlanLimitService ---

test('PlanLimitService canAddUser respects max_users limit', function () {
    $plan = Plan::create([
        'name' => 'Limited',
        'slug' => 'limited',
        'price' => 0,
        'max_users' => 1,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
    $service = app(PlanLimitService::class);

    // Before adding any user — should be allowed
    expect($service->canAddUser($tenant))->toBeTrue();

    // Simulate 1 existing TenantUser in tenant DB
    $tenant->run(fn () => TenantUser::create(['user_id' => 99]));

    // Now at the limit
    expect($service->canAddUser($tenant))->toBeFalse();
});

test('PlanLimitService canAddUser allows unlimited when max_users is null', function () {
    $plan = Plan::create([
        'name' => 'Unlimited',
        'slug' => 'unlimited',
        'price' => 49,
        'max_users' => null,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
    $service = app(PlanLimitService::class);

    expect($service->canAddUser($tenant))->toBeTrue();
});

test('PlanLimitService canCreateDocument respects limit', function () {
    $plan = Plan::create([
        'name' => 'Basic',
        'slug' => 'basic',
        'price' => 0,
        'max_documents' => 10,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
    $service = app(PlanLimitService::class);

    expect($service->canCreateDocument($tenant, 9))->toBeTrue()
        ->and($service->canCreateDocument($tenant, 10))->toBeFalse()
        ->and($service->canCreateDocument($tenant, 11))->toBeFalse();
});

test('PlanLimitService canCreateDocument allows unlimited when max_documents is null', function () {
    $plan = Plan::create([
        'name' => 'Pro',
        'slug' => 'pro-docs',
        'price' => 49,
        'max_documents' => null,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
    $service = app(PlanLimitService::class);

    expect($service->canCreateDocument($tenant, 999999))->toBeTrue();
});
