<?php

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseReturnItem>
 */
class PurchaseReturnItemFactory extends Factory
{
    protected $model = PurchaseReturnItem::class;

    public function definition(): array
    {
        return [
            'purchase_return_id' => PurchaseReturn::factory(),
            'goods_received_note_item_id' => null,
            'product_variant_id' => ProductVariant::factory(),
            'quantity' => number_format(fake()->randomFloat(4, 1, 100), 4, '.', ''),
            'unit_cost' => number_format(fake()->randomFloat(4, 1, 500), 4, '.', ''),
            'notes' => null,
        ];
    }
}
