<?php

namespace Database\Factories;

use App\Enums\PricingMode;
use App\Enums\SalesOrderStatus;
use App\Models\Partner;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SalesOrder>
 */
class SalesOrderFactory extends Factory
{
    protected $model = SalesOrder::class;

    public function definition(): array
    {
        return [
            'so_number' => 'SO-'.strtoupper(Str::random(8)),
            'document_series_id' => null,
            'partner_id' => Partner::factory()->customer(),
            'quotation_id' => null,
            'warehouse_id' => Warehouse::factory(),
            'status' => SalesOrderStatus::Draft,
            'currency_code' => 'EUR',
            'exchange_rate' => '1.000000',
            'pricing_mode' => PricingMode::VatExclusive,
            'subtotal' => '0.00',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'total' => '0.00',
            'expected_delivery_date' => null,
            'issued_at' => null,
            'notes' => null,
            'internal_notes' => null,
            'created_by' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => SalesOrderStatus::Draft]);
    }

    public function confirmed(): static
    {
        return $this->state(fn () => [
            'status' => SalesOrderStatus::Confirmed,
            'issued_at' => now()->toDateString(),
        ]);
    }

    public function partiallyDelivered(): static
    {
        return $this->state(fn () => ['status' => SalesOrderStatus::PartiallyDelivered]);
    }

    public function delivered(): static
    {
        return $this->state(fn () => ['status' => SalesOrderStatus::Delivered]);
    }

    public function invoiced(): static
    {
        return $this->state(fn () => ['status' => SalesOrderStatus::Invoiced]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => SalesOrderStatus::Cancelled]);
    }
}
