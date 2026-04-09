<?php

declare(strict_types=1);

use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Listeners\StripeWebhookListener;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Tenant;
use Laravel\Cashier\Events\WebhookReceived;

function webhookPlan(): Plan
{
    return Plan::create([
        'name' => 'Pro', 'slug' => 'pro-'.uniqid(),
        'price' => 49.00, 'billing_period' => 'monthly',
        'is_active' => true, 'sort_order' => 1,
    ]);
}

function webhookTenant(Plan $plan): Tenant
{
    return Tenant::factory()->create([
        'plan_id' => $plan->id,
        'subscription_status' => SubscriptionStatus::PastDue,
    ]);
}

test('checkout.session.completed activates subscription', function () {
    $plan = webhookPlan();
    $tenant = webhookTenant($plan);
    $intentId = 'pi_test_'.uniqid();

    $listener = app(StripeWebhookListener::class);
    $listener->handle(new WebhookReceived([
        'type' => 'checkout.session.completed',
        'data' => [
            'object' => [
                'payment_intent' => $intentId,
                'amount_total' => 4900,
                'payment_status' => 'paid',
                'metadata' => [
                    'tenant_id' => $tenant->id,
                    'plan_id' => $plan->id,
                ],
            ],
        ],
    ]));

    $tenant->refresh();

    expect($tenant->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and(Payment::where('stripe_payment_intent_id', $intentId)->exists())->toBeTrue();
});

test('checkout.session.completed is ignored when metadata is missing', function () {
    $plan = webhookPlan();
    $tenant = webhookTenant($plan);

    $listener = app(StripeWebhookListener::class);
    $listener->handle(new WebhookReceived([
        'type' => 'checkout.session.completed',
        'data' => [
            'object' => [
                'payment_intent' => 'pi_test',
                'amount_total' => 4900,
                'metadata' => [], // no tenant_id / plan_id
            ],
        ],
    ]));

    $tenant->refresh();

    // Status unchanged
    expect($tenant->subscription_status)->toBe(SubscriptionStatus::PastDue);
});

test('payment_intent.payment_failed marks tenant as PastDue', function () {
    $plan = webhookPlan();
    $tenant = Tenant::factory()->create([
        'plan_id' => $plan->id,
        'subscription_status' => SubscriptionStatus::Active,
    ]);
    $intentId = 'pi_test_'.uniqid();

    Payment::create([
        'tenant_id' => $tenant->id, 'plan_id' => $plan->id,
        'amount' => 49.00, 'currency' => 'EUR',
        'gateway' => PaymentGateway::Stripe,
        'status' => PaymentStatus::Pending,
        'stripe_payment_intent_id' => $intentId,
    ]);

    $listener = app(StripeWebhookListener::class);
    $listener->handle(new WebhookReceived([
        'type' => 'payment_intent.payment_failed',
        'data' => [
            'object' => ['id' => $intentId],
        ],
    ]));

    $tenant->refresh();
    $payment = Payment::where('stripe_payment_intent_id', $intentId)->first();

    expect($tenant->subscription_status)->toBe(SubscriptionStatus::PastDue)
        ->and($payment->status)->toBe(PaymentStatus::Failed);
});

test('unknown event types are silently ignored', function () {
    $listener = app(StripeWebhookListener::class);

    expect(fn () => $listener->handle(new WebhookReceived([
        'type' => 'some.unknown.event',
        'data' => ['object' => []],
    ])))->not->toThrow(Throwable::class);
});
