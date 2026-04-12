<?php

namespace Database\Factories;

use App\Enums\PricingMode;
use App\Enums\PurchaseOrderStatus;
use App\Models\Partner;
use App\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    protected $model = PurchaseOrder::class;

    public function definition(): array
    {
        return [
            'po_number' => 'PO-'.strtoupper(Str::random(8)),
            'partner_id' => Partner::factory()->supplier(),
            'warehouse_id' => null,
            'status' => PurchaseOrderStatus::Draft,
            'currency_code' => 'EUR',
            'exchange_rate' => '1.000000',
            'pricing_mode' => PricingMode::VatExclusive,
            'subtotal' => '0.00',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'total' => '0.00',
            'expected_delivery_date' => null,
            'notes' => null,
            'internal_notes' => null,
            'ordered_at' => null,
            'created_by' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => PurchaseOrderStatus::Draft]);
    }

    public function sent(): static
    {
        return $this->state(fn () => [
            'status' => PurchaseOrderStatus::Sent,
            'ordered_at' => now()->toDateString(),
        ]);
    }

    public function confirmed(): static
    {
        return $this->state(fn () => [
            'status' => PurchaseOrderStatus::Confirmed,
            'ordered_at' => now()->toDateString(),
        ]);
    }

    public function partiallyReceived(): static
    {
        return $this->state(fn () => ['status' => PurchaseOrderStatus::PartiallyReceived]);
    }

    public function received(): static
    {
        return $this->state(fn () => ['status' => PurchaseOrderStatus::Received]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => PurchaseOrderStatus::Cancelled]);
    }
}
