<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => ['en' => ucwords($name)],
            'slug' => Str::slug($name),
            'parent_id' => null,
            'description' => ['en' => fake()->sentence()],
            'is_active' => true,
        ];
    }

    public function root(): static
    {
        return $this->state(fn () => ['parent_id' => null]);
    }

    public function child(): static
    {
        return $this->state(fn () => [
            'parent_id' => Category::factory()->root(),
        ]);
    }

    public function grandchild(): static
    {
        return $this->state(fn () => [
            'parent_id' => Category::factory()->child(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
