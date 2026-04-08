<?php

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Tenant;

// --- Plan model ---

test('plan is created with correct attributes', function () {
    $plan = Plan::create([
        'name' => 'Starter',
        'slug' => 'starter',
        'price' => 19.99,
        'billing_period' => 'monthly',
        'max_users' => 5,
        'max_documents' => 200,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    expect($plan->name)->toBe('Starter')
        ->and($plan->slug)->toBe('starter')
        ->and($plan->price)->toBe('19.99')
        ->and($plan->billing_period)->toBe('monthly')
        ->and($plan->max_users)->toBe(5)
        ->and($plan->max_documents)->toBe(200)
        ->and($plan->is_active)->toBeTrue();
});

test('free plan isFree() returns true', function () {
    $free = Plan::create([
        'name' => 'Free',
        'slug' => 'free',
        'price' => 0,
        'billing_period' => null,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    expect($free->isFree())->toBeTrue();
});

test('paid plan isFree() returns false', function () {
    $paid = Plan::create([
        'name' => 'Pro',
        'slug' => 'pro',
        'price' => 49.00,
        'billing_period' => 'monthly',
        'is_active' => true,
        'sort_order' => 1,
    ]);

    expect($paid->isFree())->toBeFalse();
});

test('plan stores features as json array', function () {
    $plan = Plan::create([
        'name' => 'Feature Plan',
        'slug' => 'feature-plan',
        'price' => 0,
        'is_active' => true,
        'sort_order' => 0,
        'features' => ['invoicing' => 'true', 'crm' => 'false'],
    ]);

    expect($plan->features)->toBeArray()
        ->toHaveKey('invoicing', 'true')
        ->toHaveKey('crm', 'false');
});

// --- SubscriptionStatus enum ---

test('SubscriptionStatus trial and active are accessible', function () {
    expect(SubscriptionStatus::Trial->isAccessible())->toBeTrue()
        ->and(SubscriptionStatus::Active->isAccessible())->toBeTrue();
});

test('SubscriptionStatus past_due, suspended, cancelled are not accessible', function () {
    expect(SubscriptionStatus::PastDue->isAccessible())->toBeFalse()
        ->and(SubscriptionStatus::Suspended->isAccessible())->toBeFalse()
        ->and(SubscriptionStatus::Cancelled->isAccessible())->toBeFalse();
});

test('SubscriptionStatus has correct labels', function () {
    expect(SubscriptionStatus::Trial->getLabel())->toBe('Trial')
        ->and(SubscriptionStatus::Active->getLabel())->toBe('Active')
        ->and(SubscriptionStatus::PastDue->getLabel())->toBe('Past Due');
});

// --- Tenant subscription helpers ---

test('onTrial() returns true when status is trial and trial date is in future', function () {
    $tenant = Tenant::factory()->create([
        'subscription_status' => SubscriptionStatus::Trial,
        'trial_ends_at' => now()->addDays(7),
    ]);

    expect($tenant->onTrial())->toBeTrue();
});

test('onTrial() returns false when trial has expired', function () {
    $tenant = Tenant::factory()->create([
        'subscription_status' => SubscriptionStatus::Trial,
        'trial_ends_at' => now()->subDay(),
    ]);

    expect($tenant->onTrial())->toBeFalse();
});

test('onTrial() returns false when status is not trial', function () {
    $tenant = Tenant::factory()->create([
        'subscription_status' => SubscriptionStatus::Active,
        'trial_ends_at' => now()->addDays(7),
    ]);

    expect($tenant->onTrial())->toBeFalse();
});

test('hasActiveSubscription() returns true only for active status', function () {
    $active = Tenant::factory()->create(['subscription_status' => SubscriptionStatus::Active]);
    $trial = Tenant::factory()->create(['subscription_status' => SubscriptionStatus::Trial]);

    expect($active->hasActiveSubscription())->toBeTrue()
        ->and($trial->hasActiveSubscription())->toBeFalse();
});

test('isSubscriptionAccessible() returns true for trial and active', function () {
    $trial = Tenant::factory()->create(['subscription_status' => SubscriptionStatus::Trial]);
    $active = Tenant::factory()->create(['subscription_status' => SubscriptionStatus::Active]);
    $pastDue = Tenant::factory()->create(['subscription_status' => SubscriptionStatus::PastDue]);

    expect($trial->isSubscriptionAccessible())->toBeTrue()
        ->and($active->isSubscriptionAccessible())->toBeTrue()
        ->and($pastDue->isSubscriptionAccessible())->toBeFalse();
});

test('tenant belongs to plan', function () {
    $plan = Plan::create([
        'name' => 'Test Plan',
        'slug' => 'test-plan',
        'price' => 0,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);

    expect($tenant->plan)->not->toBeNull()
        ->and($tenant->plan->id)->toBe($plan->id)
        ->and($tenant->plan->name)->toBe('Test Plan');
});
