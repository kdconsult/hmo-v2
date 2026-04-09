<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'plan_id' => Plan::factory(),
            'amount' => $this->faker->randomFloat(2, 9, 199),
            'currency' => 'EUR',
            'gateway' => $this->faker->randomElement(PaymentGateway::cases()),
            'status' => PaymentStatus::Completed,
            'bank_transfer_reference' => null,
            'notes' => null,
            'paid_at' => now(),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Pending,
            'paid_at' => null,
        ]);
    }

    public function stripe(): static
    {
        return $this->state(fn (array $attributes) => [
            'gateway' => PaymentGateway::Stripe,
            'stripe_payment_intent_id' => 'pi_'.$this->faker->regexify('[A-Za-z0-9]{24}'),
        ]);
    }

    public function bankTransfer(): static
    {
        return $this->state(fn (array $attributes) => [
            'gateway' => PaymentGateway::BankTransfer,
            'bank_transfer_reference' => $this->faker->bothify('REF-####-????'),
        ]);
    }
}
