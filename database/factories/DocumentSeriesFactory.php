<?php

namespace Database\Factories;

use App\Enums\DocumentType;
use App\Models\DocumentSeries;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentSeries>
 */
class DocumentSeriesFactory extends Factory
{
    protected $model = DocumentSeries::class;

    public function definition(): array
    {
        return [
            'document_type' => fake()->randomElement(DocumentType::cases())->value,
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

    public function forType(DocumentType $type): static
    {
        return $this->state(fn () => [
            'document_type' => $type->value,
        ]);
    }
}
