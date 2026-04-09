<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\Payment;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\SubscriptionService;
use Laravel\Cashier\Events\WebhookReceived;

class StripeWebhookListener
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
    ) {}

    public function handle(WebhookReceived $event): void
    {
        match ($event->payload['type']) {
            'checkout.session.completed' => $this->handleCheckoutCompleted($event->payload),
            'payment_intent.payment_failed' => $this->handlePaymentFailed($event->payload),
            default => null,
        };
    }

    private function handleCheckoutCompleted(array $payload): void
    {
        $session = $payload['data']['object'];
        $tenantId = $session['metadata']['tenant_id'] ?? null;
        $planId = $session['metadata']['plan_id'] ?? null;

        if (! $tenantId || ! $planId) {
            return;
        }

        $tenant = Tenant::find($tenantId);
        $plan = Plan::find($planId);

        if (! $tenant || ! $plan) {
            return;
        }

        $paymentIntentId = $session['payment_intent'] ?? null;
        $amount = ($session['amount_total'] ?? 0) / 100;

        $this->subscriptionService->handleStripePaymentSucceeded($tenant, $plan, $paymentIntentId, (float) $amount);
    }

    private function handlePaymentFailed(array $payload): void
    {
        $intent = $payload['data']['object'];
        $intentId = $intent['id'];

        $payment = Payment::where('stripe_payment_intent_id', $intentId)->first();

        if (! $payment) {
            return;
        }

        $tenant = Tenant::find($payment->tenant_id);

        if (! $tenant) {
            return;
        }

        $this->subscriptionService->handleStripePaymentFailed($tenant, $intentId);
    }
}
