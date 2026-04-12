<?php

namespace Database\Factories;

use App\Enums\MovementType;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    protected $model = StockMovement::class;

    public function definition(): array
    {
        return [
            'product_variant_id' => ProductVariant::factory(),
            'warehouse_id' => Warehouse::factory(),
            'stock_location_id' => null,
            'type' => MovementType::Purchase,
            'quantity' => fake()->randomFloat(4, 1, 100),
            'reference_type' => null,
            'reference_id' => null,
            'notes' => null,
            'moved_at' => now(),
            'moved_by' => null,
        ];
    }
}
