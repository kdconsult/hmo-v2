<?php

namespace Database\Factories;

use App\Models\VatRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VatRate>
 */
class VatRateFactory extends Factory
{
    protected $model = VatRate::class;

    public function definition(): array
    {
        return [
            'country_code' => 'BG',
            'name' => fake()->randomElement(['Standard Rate', 'Reduced Rate', 'Zero Rate']),
            'rate' => fake()->randomElement([20.00, 9.00, 0.00]),
            'type' => fake()->randomElement(['standard', 'reduced', 'zero']),
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 0,
            'effective_from' => null,
            'effective_to' => null,
        ];
    }

    public function standard(): static
    {
        return $this->state(fn () => [
            'name' => 'Standard Rate',
            'rate' => 20.00,
            'type' => 'standard',
            'is_default' => true,
        ]);
    }
}
