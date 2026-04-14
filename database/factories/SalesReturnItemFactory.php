<?php

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesReturnItem>
 */
class SalesReturnItemFactory extends Factory
{
    protected $model = SalesReturnItem::class;

    public function definition(): array
    {
        return [
            'sales_return_id' => SalesReturn::factory(),
            'delivery_note_item_id' => null,
            'product_variant_id' => ProductVariant::factory(),
            'quantity' => number_format(fake()->randomFloat(4, 1, 100), 4, '.', ''),
            'unit_cost' => number_format(fake()->randomFloat(4, 1, 500), 4, '.', ''),
            'notes' => null,
        ];
    }
}
