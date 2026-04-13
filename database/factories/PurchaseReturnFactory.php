<?php

namespace Database\Factories;

use App\Enums\PurchaseReturnStatus;
use App\Models\Partner;
use App\Models\PurchaseReturn;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PurchaseReturn>
 */
class PurchaseReturnFactory extends Factory
{
    protected $model = PurchaseReturn::class;

    public function definition(): array
    {
        return [
            'pr_number' => 'PR-'.strtoupper(Str::random(8)),
            'document_series_id' => null,
            'goods_received_note_id' => null,
            'partner_id' => Partner::factory()->supplier(),
            'warehouse_id' => Warehouse::factory(),
            'status' => PurchaseReturnStatus::Draft,
            'returned_at' => now()->toDateString(),
            'reason' => null,
            'notes' => null,
            'created_by' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => PurchaseReturnStatus::Draft]);
    }

    public function confirmed(): static
    {
        return $this->state(fn () => ['status' => PurchaseReturnStatus::Confirmed]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => PurchaseReturnStatus::Cancelled]);
    }
}
