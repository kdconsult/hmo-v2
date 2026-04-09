<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Tenant;
use Carbon\Carbon;

class SubscriptionService
{
    /**
     * Set the tenant's subscription to Active on the given plan.
     */
    public function activateSubscription(Tenant $tenant, Plan $plan, ?Carbon $endsAt): void
    {
        $tenant->plan_id = $plan->id;
        $tenant->subscription_status = SubscriptionStatus::Active;
        $tenant->subscription_ends_at = $endsAt;
        $tenant->save();
    }

    /**
     * Mark a payment as completed, calculate its end date, and activate the subscription.
     */
    public function recordPaymentAndActivate(Tenant $tenant, Plan $plan, Payment $payment): void
    {
        $payment->status = PaymentStatus::Completed;
        $payment->paid_at = now();
        $payment->save();

        $this->activateSubscription($tenant, $plan, $this->calculateEndDate($plan));
    }

    /**
     * Switch the tenant to a different plan (preserves subscription status and end date).
     */
    public function changePlan(Tenant $tenant, Plan $plan): void
    {
        $tenant->plan_id = $plan->id;
        $tenant->save();
    }

    /**
     * Cancel the subscription — tenant retains access until subscription_ends_at.
     */
    public function cancelSubscription(Tenant $tenant): void
    {
        $tenant->subscription_status = SubscriptionStatus::Cancelled;
        $tenant->save();
    }

    /**
     * Handle a successful Stripe payment — creates a completed Payment record and activates.
     */
    public function handleStripePaymentSucceeded(Tenant $tenant, Plan $plan, string $paymentIntentId, float $amount): Payment
    {
        $periodEnd = $this->calculateEndDate($plan);

        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'amount' => $amount,
            'currency' => 'EUR',
            'gateway' => PaymentGateway::Stripe,
            'status' => PaymentStatus::Pending,
            'stripe_payment_intent_id' => $paymentIntentId,
            'paid_at' => now(),
            'period_start' => now()->toDateString(),
            'period_end' => $periodEnd?->toDateString(),
        ]);

        $this->recordPaymentAndActivate($tenant, $plan, $payment);

        return $payment->fresh();
    }

    /**
     * Handle a failed Stripe payment — marks any matching pending payment as failed
     * and sets the tenant to PastDue.
     */
    public function handleStripePaymentFailed(Tenant $tenant, string $paymentIntentId): void
    {
        Payment::where('stripe_payment_intent_id', $paymentIntentId)
            ->where('status', PaymentStatus::Pending)
            ->update(['status' => PaymentStatus::Failed]);

        $tenant->subscription_status = SubscriptionStatus::PastDue;
        $tenant->save();
    }

    private function calculateEndDate(Plan $plan): ?Carbon
    {
        return match ($plan->billing_period) {
            'monthly' => now()->addMonth(),
            'yearly' => now()->addYear(),
            'lifetime' => null,
            default => null,
        };
    }
}
