<?php

namespace Database\Factories;

use App\Models\DeliveryNote;
use App\Models\DeliveryNoteItem;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryNoteItem>
 */
class DeliveryNoteItemFactory extends Factory
{
    protected $model = DeliveryNoteItem::class;

    public function definition(): array
    {
        return [
            'delivery_note_id' => DeliveryNote::factory(),
            'sales_order_item_id' => null,
            'product_variant_id' => ProductVariant::factory(),
            'quantity' => number_format(fake()->randomFloat(4, 1, 100), 4, '.', ''),
            'unit_cost' => number_format(fake()->randomFloat(4, 1, 500), 4, '.', ''),
            'notes' => null,
        ];
    }
}
