<?php

namespace Database\Factories;

use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Contract>
 */
class ContractFactory extends Factory
{
    protected $model = Contract::class;

    public function definition(): array
    {
        return [
            'contract_number' => 'CTR-'.strtoupper(Str::random(8)),
            'document_series_id' => null,
            'partner_id' => Partner::factory(),
            'status' => ContractStatus::Active->value,
            'type' => fake()->randomElement(['maintenance', 'sla', 'subscription']),
            'start_date' => fake()->dateTimeBetween('-1 year', 'now'),
            'end_date' => fake()->dateTimeBetween('now', '+2 years'),
            'auto_renew' => false,
            'monthly_fee' => fake()->randomFloat(2, 100, 5000),
            'currency_code' => 'BGN',
        ];
    }
}
