<?php

declare(strict_types=1);

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\SubscriptionService;
use Carbon\Carbon;

function makeplan(string $billing = 'monthly', float $price = 19.00): Plan
{
    return Plan::create([
        'name' => 'Test Plan',
        'slug' => 'test-plan-'.uniqid(),
        'price' => $price,
        'billing_period' => $billing,
        'is_active' => true,
        'sort_order' => 1,
    ]);
}

function makeSubscribedTenant(Plan $plan): Tenant
{
    return Tenant::factory()->create([
        'plan_id' => $plan->id,
        'subscription_status' => SubscriptionStatus::Trial,
    ]);
}

// --- activateSubscription ---

test('activateSubscription sets plan, status=Active, and end date', function () {
    $plan = makeplan();
    $tenant = makeSubscribedTenant($plan);
    $endsAt = Carbon::now()->addMonth();

    app(SubscriptionService::class)->activateSubscription($tenant, $plan, $endsAt);

    $tenant->refresh();

    expect($tenant->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($tenant->plan_id)->toBe($plan->id)
        ->and($tenant->subscription_ends_at->toDateString())->toBe($endsAt->toDateString());
});

test('activateSubscription with null endsAt stores null', function () {
    $plan = makeplan('lifetime');
    $tenant = makeSubscribedTenant($plan);

    app(SubscriptionService::class)->activateSubscription($tenant, $plan, null);

    $tenant->refresh();

    expect($tenant->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($tenant->subscription_ends_at)->toBeNull();
});

// --- recordPaymentAndActivate ---

test('recordPaymentAndActivate marks payment completed and activates subscription', function () {
    $plan = makeplan('monthly');
    $tenant = makeSubscribedTenant($plan);

    $payment = Payment::create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'amount' => 19.00,
        'currency' => 'EUR',
        'gateway' => 'bank_transfer',
        'status' => PaymentStatus::Pending,
    ]);

    app(SubscriptionService::class)->recordPaymentAndActivate($tenant, $plan, $payment);

    $payment->refresh();
    $tenant->refresh();

    expect($payment->status)->toBe(PaymentStatus::Completed)
        ->and($payment->paid_at)->not->toBeNull()
        ->and($tenant->subscription_status)->toBe(SubscriptionStatus::Active);
});

test('recordPaymentAndActivate calculates monthly end date correctly', function () {
    $plan = makeplan('monthly');
    $tenant = makeSubscribedTenant($plan);
    $payment = Payment::create([
        'tenant_id' => $tenant->id, 'plan_id' => $plan->id,
        'amount' => 19.00, 'currency' => 'EUR',
        'gateway' => 'bank_transfer', 'status' => PaymentStatus::Pending,
    ]);

    app(SubscriptionService::class)->recordPaymentAndActivate($tenant, $plan, $payment);

    $tenant->refresh();
    expect($tenant->subscription_ends_at->isBetween(now()->addMonth()->subDay(), now()->addMonth()->addDay()))->toBeTrue();
});

test('recordPaymentAndActivate calculates yearly end date correctly', function () {
    $plan = makeplan('yearly');
    $tenant = makeSubscribedTenant($plan);
    $payment = Payment::create([
        'tenant_id' => $tenant->id, 'plan_id' => $plan->id,
        'amount' => 199.00, 'currency' => 'EUR',
        'gateway' => 'bank_transfer', 'status' => PaymentStatus::Pending,
    ]);

    app(SubscriptionService::class)->recordPaymentAndActivate($tenant, $plan, $payment);

    $tenant->refresh();
    expect($tenant->subscription_ends_at->isBetween(now()->addYear()->subDay(), now()->addYear()->addDay()))->toBeTrue();
});

test('recordPaymentAndActivate with lifetime plan sets null end date', function () {
    $plan = makeplan('lifetime');
    $tenant = makeSubscribedTenant($plan);
    $payment = Payment::create([
        'tenant_id' => $tenant->id, 'plan_id' => $plan->id,
        'amount' => 499.00, 'currency' => 'EUR',
        'gateway' => 'bank_transfer', 'status' => PaymentStatus::Pending,
    ]);

    app(SubscriptionService::class)->recordPaymentAndActivate($tenant, $plan, $payment);

    $tenant->refresh();
    expect($tenant->subscription_ends_at)->toBeNull();
});

// --- changePlan ---

test('changePlan updates plan_id only', function () {
    $plan1 = makeplan();
    $plan2 = makeplan();
    $tenant = makeSubscribedTenant($plan1);
    $tenant->subscription_status = SubscriptionStatus::Active;
    $tenant->save();

    app(SubscriptionService::class)->changePlan($tenant, $plan2);

    $tenant->refresh();

    expect($tenant->plan_id)->toBe($plan2->id)
        ->and($tenant->subscription_status)->toBe(SubscriptionStatus::Active); // unchanged
});

// --- cancelSubscription ---

test('cancelSubscription sets status to Cancelled', function () {
    $plan = makeplan();
    $tenant = makeSubscribedTenant($plan);
    $tenant->subscription_status = SubscriptionStatus::Active;
    $tenant->subscription_ends_at = now()->addMonth();
    $tenant->save();

    app(SubscriptionService::class)->cancelSubscription($tenant);

    $tenant->refresh();

    expect($tenant->subscription_status)->toBe(SubscriptionStatus::Cancelled)
        ->and($tenant->subscription_ends_at)->not->toBeNull(); // access period preserved
});

// --- handleStripePaymentSucceeded ---

test('handleStripePaymentSucceeded creates payment record and activates subscription', function () {
    $plan = makeplan();
    $tenant = makeSubscribedTenant($plan);
    $intentId = 'pi_test_'.uniqid();

    $payment = app(SubscriptionService::class)->handleStripePaymentSucceeded($tenant, $plan, $intentId, 19.00);

    $tenant->refresh();

    expect($tenant->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($payment->stripe_payment_intent_id)->toBe($intentId)
        ->and($payment->status)->toBe(PaymentStatus::Completed)
        ->and((float) $payment->amount)->toBe(19.00);
});

// --- handleStripePaymentFailed ---

test('handleStripePaymentFailed marks tenant as PastDue and payment as Failed', function () {
    $plan = makeplan();
    $tenant = makeSubscribedTenant($plan);
    $intentId = 'pi_test_'.uniqid();

    Payment::create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'amount' => 19.00,
        'currency' => 'EUR',
        'gateway' => 'stripe',
        'status' => PaymentStatus::Pending,
        'stripe_payment_intent_id' => $intentId,
    ]);

    app(SubscriptionService::class)->handleStripePaymentFailed($tenant, $intentId);

    $tenant->refresh();
    $payment = Payment::where('stripe_payment_intent_id', $intentId)->first();

    expect($tenant->subscription_status)->toBe(SubscriptionStatus::PastDue)
        ->and($payment->status)->toBe(PaymentStatus::Failed);
});
