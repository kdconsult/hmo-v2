<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\VatLegalReference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VatLegalReference>
 */
class VatLegalReferenceFactory extends Factory
{
    protected $model = VatLegalReference::class;

    public function definition(): array
    {
        return [
            'country_code' => 'BG',
            'vat_scenario' => 'exempt',
            'sub_code' => 'default',
            'legal_reference' => 'чл. 113, ал. 9 ЗДДС',
            'description' => ['bg' => 'Освободена доставка', 'en' => 'Exempt supply'],
            'is_default' => false,
            'sort_order' => 0,
        ];
    }

    public function domesticExempt(string $article): self
    {
        return $this->state(fn () => [
            'vat_scenario' => 'domestic_exempt',
            'sub_code' => "art_{$article}",
            'legal_reference' => "чл. {$article} ЗДДС",
        ]);
    }
}
