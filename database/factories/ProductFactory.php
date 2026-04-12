<?php

namespace Database\Factories;

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('PRD-####'),
            'name' => ['en' => fake()->words(3, true)],
            'description' => ['en' => fake()->sentence()],
            'type' => ProductType::Stock,
            'category_id' => null,
            'unit_id' => null,
            'purchase_price' => fake()->randomFloat(4, 1, 100),
            'sale_price' => fake()->randomFloat(4, 1, 200),
            'vat_rate_id' => null,
            'status' => ProductStatus::Active,
            'is_stockable' => true,
            'barcode' => null,
            'attributes' => null,
        ];
    }

    public function stock(): static
    {
        return $this->state(fn () => ['type' => ProductType::Stock, 'is_stockable' => true]);
    }

    public function service(): static
    {
        return $this->state(fn () => ['type' => ProductType::Service, 'is_stockable' => false]);
    }

    public function bundle(): static
    {
        return $this->state(fn () => ['type' => ProductType::Bundle, 'is_stockable' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => ProductStatus::Discontinued]);
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => ProductStatus::Draft]);
    }
}
