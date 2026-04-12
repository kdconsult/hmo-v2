<?php

namespace Database\Factories;

use App\Enums\SeriesType;
use App\Models\NumberSeries;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NumberSeries>
 */
class NumberSeriesFactory extends Factory
{
    protected $model = NumberSeries::class;

    public function definition(): array
    {
        return [
            'series_type' => fake()->randomElement(SeriesType::cases())->value,
            'name' => 'Default Series',
            'prefix' => strtoupper(fake()->lexify('???')),
            'separator' => '-',
            'include_year' => true,
            'year_format' => 'Y',
            'padding' => 5,
            'next_number' => 1,
            'reset_yearly' => true,
            'is_default' => true,
            'is_active' => true,
        ];
    }

    public function forType(SeriesType $type): static
    {
        return $this->state(fn () => [
            'series_type' => $type->value,
        ]);
    }
}
