<?php

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\StockItem;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockItem>
 */
class StockItemFactory extends Factory
{
    protected $model = StockItem::class;

    public function definition(): array
    {
        return [
            'product_variant_id' => ProductVariant::factory(),
            'warehouse_id' => Warehouse::factory(),
            'stock_location_id' => null,
            'quantity' => fake()->randomFloat(4, 0, 1000),
            'reserved_quantity' => '0.0000',
        ];
    }
}
