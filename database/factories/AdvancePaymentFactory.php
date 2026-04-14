<?php

namespace Database\Factories;

use App\Enums\AdvancePaymentStatus;
use App\Models\AdvancePayment;
use App\Models\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AdvancePayment>
 */
class AdvancePaymentFactory extends Factory
{
    protected $model = AdvancePayment::class;

    public function definition(): array
    {
        $amount = number_format(fake()->randomFloat(2, 100, 10000), 2, '.', '');

        return [
            'ap_number' => 'AP-'.strtoupper(Str::random(8)),
            'document_series_id' => null,
            'partner_id' => Partner::factory()->customer(),
            'sales_order_id' => null,
            'customer_invoice_id' => null,
            'status' => AdvancePaymentStatus::Open,
            'currency_code' => 'EUR',
            'exchange_rate' => '1.000000',
            'amount' => $amount,
            'amount_applied' => '0.00',
            'payment_method' => null,
            'received_at' => now()->toDateString(),
            'notes' => null,
            'created_by' => null,
        ];
    }

    public function open(): static
    {
        return $this->state(fn () => ['status' => AdvancePaymentStatus::Open]);
    }

    public function partiallyApplied(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AdvancePaymentStatus::PartiallyApplied,
            'amount_applied' => number_format((float) $attributes['amount'] / 2, 2, '.', ''),
        ]);
    }

    public function fullyApplied(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AdvancePaymentStatus::FullyApplied,
            'amount_applied' => $attributes['amount'],
        ]);
    }

    public function refunded(): static
    {
        return $this->state(fn () => ['status' => AdvancePaymentStatus::Refunded]);
    }
}
