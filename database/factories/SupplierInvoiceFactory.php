<?php

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Enums\PricingMode;
use App\Models\Partner;
use App\Models\SupplierInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SupplierInvoice>
 */
class SupplierInvoiceFactory extends Factory
{
    protected $model = SupplierInvoice::class;

    public function definition(): array
    {
        $issuedAt = fake()->dateTimeBetween('-3 months', 'now');

        return [
            'supplier_invoice_number' => strtoupper(Str::random(10)),
            'internal_number' => 'SI-'.strtoupper(Str::random(8)),
            'purchase_order_id' => null,
            'partner_id' => Partner::factory()->supplier(),
            'status' => DocumentStatus::Draft,
            'currency_code' => 'EUR',
            'exchange_rate' => '1.000000',
            'pricing_mode' => PricingMode::VatExclusive,
            'subtotal' => '0.00',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'total' => '0.00',
            'amount_paid' => '0.00',
            'amount_due' => '0.00',
            'issued_at' => $issuedAt->format('Y-m-d'),
            'received_at' => null,
            'due_date' => (clone $issuedAt)->modify('+30 days')->format('Y-m-d'),
            'payment_method' => null,
            'notes' => null,
            'internal_notes' => null,
            'created_by' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => DocumentStatus::Draft]);
    }

    public function confirmed(): static
    {
        return $this->state(fn () => ['status' => DocumentStatus::Confirmed]);
    }

    public function paid(): static
    {
        return $this->state(function () {
            $total = fake()->randomFloat(2, 10, 1000);

            return [
                'status' => DocumentStatus::Paid,
                'total' => $total,
                'amount_paid' => $total,
                'amount_due' => '0.00',
            ];
        });
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => DocumentStatus::Cancelled]);
    }
}
