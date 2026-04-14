<?php

namespace Database\Factories;

use App\Enums\DeliveryNoteStatus;
use App\Models\DeliveryNote;
use App\Models\Partner;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DeliveryNote>
 */
class DeliveryNoteFactory extends Factory
{
    protected $model = DeliveryNote::class;

    public function definition(): array
    {
        return [
            'dn_number' => 'DN-'.strtoupper(Str::random(8)),
            'document_series_id' => null,
            'sales_order_id' => null,
            'partner_id' => Partner::factory()->customer(),
            'warehouse_id' => Warehouse::factory(),
            'status' => DeliveryNoteStatus::Draft,
            'delivered_at' => null,
            'notes' => null,
            'created_by' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => DeliveryNoteStatus::Draft]);
    }

    public function confirmed(): static
    {
        return $this->state(fn () => [
            'status' => DeliveryNoteStatus::Confirmed,
            'delivered_at' => now()->toDateString(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => DeliveryNoteStatus::Cancelled]);
    }
}
