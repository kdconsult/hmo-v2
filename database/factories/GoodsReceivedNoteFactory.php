<?php

namespace Database\Factories;

use App\Enums\GoodsReceivedNoteStatus;
use App\Models\GoodsReceivedNote;
use App\Models\Partner;
use App\Models\PurchaseOrder;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GoodsReceivedNote>
 */
class GoodsReceivedNoteFactory extends Factory
{
    protected $model = GoodsReceivedNote::class;

    public function definition(): array
    {
        return [
            'grn_number' => 'GRN-'.strtoupper(Str::random(8)),
            'purchase_order_id' => null,
            'partner_id' => Partner::factory()->supplier(),
            'warehouse_id' => Warehouse::factory(),
            'status' => GoodsReceivedNoteStatus::Draft,
            'received_at' => now()->toDateString(),
            'notes' => null,
            'created_by' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => GoodsReceivedNoteStatus::Draft]);
    }

    public function confirmed(): static
    {
        return $this->state(fn () => ['status' => GoodsReceivedNoteStatus::Confirmed]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => GoodsReceivedNoteStatus::Cancelled]);
    }

    public function forPurchaseOrder(PurchaseOrder $po): static
    {
        return $this->state(fn () => [
            'purchase_order_id' => $po->id,
            'partner_id' => $po->partner_id,
            'warehouse_id' => $po->warehouse_id,
        ]);
    }
}
