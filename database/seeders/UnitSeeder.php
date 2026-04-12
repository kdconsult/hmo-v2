<?php

namespace Database\Seeders;

use App\Enums\UnitType;
use App\Models\Unit;
use Illuminate\Database\Seeder;

class UnitSeeder extends Seeder
{
    public function run(): void
    {
        $units = [
            ['symbol' => 'pcs', 'type' => UnitType::Piece, 'name' => 'Piece'],
            ['symbol' => 'kg', 'type' => UnitType::Mass, 'name' => 'Kilogram'],
            ['symbol' => 'g', 'type' => UnitType::Mass, 'name' => 'Gram'],
            ['symbol' => 't', 'type' => UnitType::Mass, 'name' => 'Tonne'],
            ['symbol' => 'l', 'type' => UnitType::Volume, 'name' => 'Litre'],
            ['symbol' => 'ml', 'type' => UnitType::Volume, 'name' => 'Millilitre'],
            ['symbol' => 'm', 'type' => UnitType::Length, 'name' => 'Metre'],
            ['symbol' => 'cm', 'type' => UnitType::Length, 'name' => 'Centimetre'],
            ['symbol' => 'mm', 'type' => UnitType::Length, 'name' => 'Millimetre'],
            ['symbol' => 'm²', 'type' => UnitType::Area, 'name' => 'Square Metre'],
            ['symbol' => 'h', 'type' => UnitType::Time, 'name' => 'Hour'],
            ['symbol' => 'day', 'type' => UnitType::Time, 'name' => 'Day'],
            ['symbol' => 'month', 'type' => UnitType::Time, 'name' => 'Month'],
        ];

        foreach ($units as $unit) {
            Unit::updateOrCreate(
                ['symbol' => $unit['symbol']],
                [
                    'name' => ['en' => $unit['name']],
                    'type' => $unit['type'],
                    'is_active' => true,
                ],
            );
        }
    }
}
