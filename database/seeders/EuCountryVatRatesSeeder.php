<?php

namespace Database\Seeders;

use App\Models\EuCountryVatRate;
use Illuminate\Database\Seeder;

class EuCountryVatRatesSeeder extends Seeder
{
    public function run(): void
    {
        $rates = [
            ['country_code' => 'AT', 'country_name' => 'Austria', 'standard_rate' => 20.00, 'reduced_rate' => 10.00],
            ['country_code' => 'BE', 'country_name' => 'Belgium', 'standard_rate' => 21.00, 'reduced_rate' => 6.00],
            ['country_code' => 'BG', 'country_name' => 'Bulgaria', 'standard_rate' => 20.00, 'reduced_rate' => 9.00],
            ['country_code' => 'CY', 'country_name' => 'Cyprus', 'standard_rate' => 19.00, 'reduced_rate' => 5.00],
            ['country_code' => 'CZ', 'country_name' => 'Czechia', 'standard_rate' => 21.00, 'reduced_rate' => 12.00],
            ['country_code' => 'DE', 'country_name' => 'Germany', 'standard_rate' => 19.00, 'reduced_rate' => 7.00],
            ['country_code' => 'DK', 'country_name' => 'Denmark', 'standard_rate' => 25.00, 'reduced_rate' => null],
            ['country_code' => 'EE', 'country_name' => 'Estonia', 'standard_rate' => 22.00, 'reduced_rate' => 9.00],
            ['country_code' => 'ES', 'country_name' => 'Spain', 'standard_rate' => 21.00, 'reduced_rate' => 10.00],
            ['country_code' => 'FI', 'country_name' => 'Finland', 'standard_rate' => 25.50, 'reduced_rate' => 10.00],
            ['country_code' => 'FR', 'country_name' => 'France', 'standard_rate' => 20.00, 'reduced_rate' => 5.50],
            ['country_code' => 'GR', 'country_name' => 'Greece', 'standard_rate' => 24.00, 'reduced_rate' => 6.00],
            ['country_code' => 'HR', 'country_name' => 'Croatia', 'standard_rate' => 25.00, 'reduced_rate' => 5.00],
            ['country_code' => 'HU', 'country_name' => 'Hungary', 'standard_rate' => 27.00, 'reduced_rate' => 5.00],
            ['country_code' => 'IE', 'country_name' => 'Ireland', 'standard_rate' => 23.00, 'reduced_rate' => 9.00],
            ['country_code' => 'IT', 'country_name' => 'Italy', 'standard_rate' => 22.00, 'reduced_rate' => 5.00],
            ['country_code' => 'LT', 'country_name' => 'Lithuania', 'standard_rate' => 21.00, 'reduced_rate' => 9.00],
            ['country_code' => 'LU', 'country_name' => 'Luxembourg', 'standard_rate' => 17.00, 'reduced_rate' => 8.00],
            ['country_code' => 'LV', 'country_name' => 'Latvia', 'standard_rate' => 21.00, 'reduced_rate' => 12.00],
            ['country_code' => 'MT', 'country_name' => 'Malta', 'standard_rate' => 18.00, 'reduced_rate' => 5.00],
            ['country_code' => 'NL', 'country_name' => 'Netherlands', 'standard_rate' => 21.00, 'reduced_rate' => 9.00],
            ['country_code' => 'PL', 'country_name' => 'Poland', 'standard_rate' => 23.00, 'reduced_rate' => 5.00],
            ['country_code' => 'PT', 'country_name' => 'Portugal', 'standard_rate' => 23.00, 'reduced_rate' => 6.00],
            ['country_code' => 'RO', 'country_name' => 'Romania', 'standard_rate' => 19.00, 'reduced_rate' => 5.00],
            ['country_code' => 'SE', 'country_name' => 'Sweden', 'standard_rate' => 25.00, 'reduced_rate' => 6.00],
            ['country_code' => 'SI', 'country_name' => 'Slovenia', 'standard_rate' => 22.00, 'reduced_rate' => 9.50],
            ['country_code' => 'SK', 'country_name' => 'Slovakia', 'standard_rate' => 23.00, 'reduced_rate' => 10.00],
        ];

        foreach ($rates as $rate) {
            EuCountryVatRate::updateOrCreate(
                ['country_code' => $rate['country_code']],
                $rate
            );
        }
    }
}
