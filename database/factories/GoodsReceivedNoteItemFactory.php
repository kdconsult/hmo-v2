<?php

namespace Database\Factories;

use App\Models\GoodsReceivedNote;
use App\Models\GoodsReceivedNoteItem;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoodsReceivedNoteItem>
 */
class GoodsReceivedNoteItemFactory extends Factory
{
    protected $model = GoodsReceivedNoteItem::class;

    public function definition(): array
    {
        return [
            'goods_received_note_id' => GoodsReceivedNote::factory(),
            'purchase_order_item_id' => null,
            'product_variant_id' => ProductVariant::factory(),
            'quantity' => number_format(fake()->randomFloat(4, 1, 100), 4, '.', ''),
            'unit_cost' => number_format(fake()->randomFloat(4, 1, 500), 4, '.', ''),
            'notes' => null,
        ];
    }
}
