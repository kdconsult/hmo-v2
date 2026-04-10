<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->words(2, true),
            'slug' => Str::slug($this->faker->unique()->words(2, true)),
            'price' => $this->faker->randomFloat(2, 9, 99),
            'billing_period' => $this->faker->randomElement(['monthly', 'yearly']),
            'max_users' => $this->faker->numberBetween(1, 50),
            'max_documents' => $this->faker->numberBetween(10, 500),
            'is_active' => true,
            'sort_order' => $this->faker->numberBetween(1, 10),
        ];
    }
}
