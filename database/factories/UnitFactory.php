<?php

namespace Database\Factories;

use App\Enums\UnitType;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    protected $model = Unit::class;

    public function definition(): array
    {
        return [
            'name' => ['en' => fake()->unique()->word()],
            'symbol' => fake()->lexify('??'),
            'type' => UnitType::Piece,
            'is_active' => true,
        ];
    }
}
