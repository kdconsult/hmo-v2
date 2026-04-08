<?php

namespace Database\Seeders;

use App\Models\VatRate;
use Illuminate\Database\Seeder;

class VatRateSeeder extends Seeder
{
    public function run(): void
    {
        $rates = [
            [
                'country_code' => 'BG',
                'name' => 'Standard Rate',
                'rate' => 20.00,
                'type' => 'standard',
                'is_default' => true,
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'country_code' => 'BG',
                'name' => 'Reduced Rate (Accommodation)',
                'rate' => 9.00,
                'type' => 'reduced',
                'is_default' => false,
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'country_code' => 'BG',
                'name' => 'Zero Rate',
                'rate' => 0.00,
                'type' => 'zero',
                'is_default' => false,
                'is_active' => true,
                'sort_order' => 3,
            ],
        ];

        foreach ($rates as $rate) {
            VatRate::updateOrCreate(
                ['country_code' => $rate['country_code'], 'type' => $rate['type'], 'name' => $rate['name']],
                $rate
            );
        }
    }
}
