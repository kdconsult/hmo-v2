<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'name' => ['en' => fake()->words(2, true)],
            'sku' => fake()->unique()->bothify('SKU-####'),
            'purchase_price' => null,
            'sale_price' => null,
            'barcode' => null,
            'is_default' => false,
            'is_active' => true,
            'attributes' => null,
        ];
    }

    public function default(): static
    {
        return $this->state(fn () => ['is_default' => true]);
    }
}
