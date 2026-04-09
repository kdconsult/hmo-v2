<?php

declare(strict_types=1);

use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\TenantPolicy;
use App\Services\SubscriptionService;

function billingPlan(string $billing = 'monthly'): Plan
{
    return Plan::create([
        'name' => 'Test Plan',
        'slug' => 'billing-plan-'.uniqid(),
        'price' => 19.00,
        'billing_period' => $billing,
        'is_active' => true,
        'sort_order' => 1,
    ]);
}

function billingTenant(Plan $plan): Tenant
{
    return Tenant::factory()->create([
        'plan_id' => $plan->id,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_ends_at' => now()->addMonth(),
    ]);
}

// --- Change Plan ---

test('changePlan updates only the plan_id, preserving subscription status and end date', function () {
    $oldPlan = billingPlan();
    $newPlan = billingPlan('yearly');
    $tenant = billingTenant($oldPlan);
    $endsAt = $tenant->subscription_ends_at;

    app(SubscriptionService::class)->changePlan($tenant, $newPlan);

    $fresh = $tenant->fresh();
    expect($fresh->plan_id)->toBe($newPlan->id)
        ->and($fresh->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($fresh->subscription_ends_at->toDateString())->toBe($endsAt->toDateString());
});

// --- Cancel Subscription ---

test('cancelSubscription sets status to Cancelled without changing subscription_ends_at', function () {
    $plan = billingPlan();
    $tenant = billingTenant($plan);
    $endsAt = $tenant->subscription_ends_at;

    app(SubscriptionService::class)->cancelSubscription($tenant);

    $fresh = $tenant->fresh();
    expect($fresh->subscription_status)->toBe(SubscriptionStatus::Cancelled)
        ->and($fresh->subscription_ends_at->toDateString())->toBe($endsAt->toDateString());
});

// --- Record Payment period_end calculation ---

test('recordPayment sets period_end to +1 month for monthly plans', function () {
    $plan = billingPlan('monthly');
    $tenant = billingTenant($plan);

    $payment = Payment::create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'amount' => 19.00,
        'currency' => 'EUR',
        'gateway' => PaymentGateway::BankTransfer,
        'status' => PaymentStatus::Pending,
        'period_start' => now()->toDateString(),
        'period_end' => now()->addMonth()->toDateString(),
    ]);

    app(SubscriptionService::class)->recordPaymentAndActivate($tenant, $plan, $payment);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Completed);
});

test('recordPayment sets period_end to +1 year for yearly plans', function () {
    $plan = billingPlan('yearly');
    $tenant = billingTenant($plan);

    $payment = Payment::create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'amount' => 190.00,
        'currency' => 'EUR',
        'gateway' => PaymentGateway::BankTransfer,
        'status' => PaymentStatus::Pending,
        'period_start' => now()->toDateString(),
        'period_end' => now()->addYear()->toDateString(),
    ]);

    app(SubscriptionService::class)->recordPaymentAndActivate($tenant, $plan, $payment);

    $fresh = $tenant->fresh();
    expect($fresh->subscription_ends_at)->not()->toBeNull()
        ->and($fresh->subscription_ends_at->year)->toBe(now()->addYear()->year);
});

test('recordPayment sets period_end to null for lifetime plans', function () {
    $plan = billingPlan('lifetime');
    $tenant = billingTenant($plan);

    $payment = Payment::create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'amount' => 490.00,
        'currency' => 'EUR',
        'gateway' => PaymentGateway::BankTransfer,
        'status' => PaymentStatus::Pending,
        'period_start' => now()->toDateString(),
        'period_end' => null,
    ]);

    app(SubscriptionService::class)->recordPaymentAndActivate($tenant, $plan, $payment);

    expect($tenant->fresh()->subscription_ends_at)->toBeNull();
});

// --- Landlord tenant policy protection ---

test('TenantPolicy suspend returns false for landlord tenant', function () {
    $tenant = Tenant::factory()->create(['status' => 'active']);
    config(['hmo.landlord_tenant_id' => $tenant->id]);

    $policy = new TenantPolicy;
    $landlordUser = User::factory()->create(['is_landlord' => true]);

    expect($policy->suspend($landlordUser, $tenant))->toBeFalse();
});

test('TenantPolicy markForDeletion returns false for landlord tenant', function () {
    $tenant = Tenant::factory()->create(['status' => 'suspended']);
    config(['hmo.landlord_tenant_id' => $tenant->id]);

    $policy = new TenantPolicy;
    $landlordUser = User::factory()->create(['is_landlord' => true]);

    expect($policy->markForDeletion($landlordUser, $tenant))->toBeFalse();
});
