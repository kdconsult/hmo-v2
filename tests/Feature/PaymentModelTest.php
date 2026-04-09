<?php

declare(strict_types=1);

use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;

function paymentPlan(): Plan
{
    return Plan::create([
        'name' => 'Starter', 'slug' => 'starter-'.uniqid(),
        'price' => 19.00, 'billing_period' => 'monthly',
        'is_active' => true, 'sort_order' => 1,
    ]);
}

function paymentTenant(Plan $plan): Tenant
{
    return Tenant::factory()->create(['plan_id' => $plan->id]);
}

function makePayment(array $overrides = []): Payment
{
    $plan = paymentPlan();
    $tenant = paymentTenant($plan);

    return Payment::create(array_merge([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'amount' => 19.00,
        'currency' => 'EUR',
        'gateway' => PaymentGateway::BankTransfer,
        'status' => PaymentStatus::Completed,
        'paid_at' => now(),
    ], $overrides));
}

// --- Casts ---

test('gateway is cast to PaymentGateway enum', function () {
    $payment = makePayment(['gateway' => PaymentGateway::Stripe]);
    expect($payment->fresh()->gateway)->toBe(PaymentGateway::Stripe);
});

test('status is cast to PaymentStatus enum', function () {
    $payment = makePayment(['status' => PaymentStatus::Pending]);
    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

test('paid_at is cast to datetime', function () {
    $payment = makePayment();
    expect($payment->fresh()->paid_at)->toBeInstanceOf(Carbon::class);
});

test('period_start and period_end are cast to dates', function () {
    $payment = makePayment([
        'period_start' => '2026-04-01',
        'period_end' => '2026-04-30',
    ]);

    expect($payment->fresh()->period_start)->toBeInstanceOf(Carbon::class)
        ->and($payment->fresh()->period_end)->toBeInstanceOf(Carbon::class);
});

// --- Relationships ---

test('payment belongs to tenant', function () {
    $payment = makePayment();
    expect($payment->tenant)->toBeInstanceOf(Tenant::class);
});

test('payment belongs to plan', function () {
    $payment = makePayment();
    expect($payment->plan)->toBeInstanceOf(Plan::class);
});

test('payment recordedBy returns user when set', function () {
    $user = User::factory()->create();
    $payment = makePayment(['recorded_by' => $user->id]);
    expect($payment->fresh()->recordedBy)->toBeInstanceOf(User::class)
        ->and($payment->fresh()->recordedBy->id)->toBe($user->id);
});

// --- Scopes ---

test('scopeCompleted filters to completed payments', function () {
    makePayment(['status' => PaymentStatus::Completed]);
    makePayment(['status' => PaymentStatus::Pending]);
    makePayment(['status' => PaymentStatus::Failed]);

    expect(Payment::completed()->count())->toBe(1);
});

test('scopePending filters to pending payments', function () {
    makePayment(['status' => PaymentStatus::Completed]);
    makePayment(['status' => PaymentStatus::Pending]);

    expect(Payment::pending()->count())->toBe(1);
});

test('scopeForTenant filters by tenant', function () {
    $plan = paymentPlan();
    $tenantA = paymentTenant($plan);
    $tenantB = paymentTenant($plan);

    Payment::create(['tenant_id' => $tenantA->id, 'plan_id' => $plan->id, 'amount' => 19, 'currency' => 'EUR', 'gateway' => PaymentGateway::BankTransfer, 'status' => PaymentStatus::Completed]);
    Payment::create(['tenant_id' => $tenantA->id, 'plan_id' => $plan->id, 'amount' => 19, 'currency' => 'EUR', 'gateway' => PaymentGateway::BankTransfer, 'status' => PaymentStatus::Completed]);
    Payment::create(['tenant_id' => $tenantB->id, 'plan_id' => $plan->id, 'amount' => 19, 'currency' => 'EUR', 'gateway' => PaymentGateway::BankTransfer, 'status' => PaymentStatus::Completed]);

    expect(Payment::forTenant($tenantA)->count())->toBe(2)
        ->and(Payment::forTenant($tenantB)->count())->toBe(1);
});
