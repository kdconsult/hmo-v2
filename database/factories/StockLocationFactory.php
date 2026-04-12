<?php

namespace Database\Factories;

use App\Models\StockLocation;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockLocation>
 */
class StockLocationFactory extends Factory
{
    protected $model = StockLocation::class;

    public function definition(): array
    {
        return [
            'warehouse_id' => Warehouse::factory(),
            'name' => fake()->words(2, true),
            'code' => fake()->bothify('LOC-###'),
            'is_active' => true,
        ];
    }
}
