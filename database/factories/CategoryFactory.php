<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Unit;
use App\Models\VatRate;
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
            'default_vat_rate_id' => null,
            'default_unit_id' => null,
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

    public function withDefaultVatRate(?VatRate $vatRate = null): static
    {
        return $this->state(fn () => [
            'default_vat_rate_id' => $vatRate?->id ?? VatRate::factory(),
        ]);
    }

    public function withDefaultUnit(?Unit $unit = null): static
    {
        return $this->state(fn () => [
            'default_unit_id' => $unit?->id ?? Unit::factory(),
        ]);
    }

    public function withDefaults(?VatRate $vatRate = null, ?Unit $unit = null): static
    {
        return $this->withDefaultVatRate($vatRate)->withDefaultUnit($unit);
    }
}
