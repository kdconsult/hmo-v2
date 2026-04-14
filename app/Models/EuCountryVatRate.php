<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EuCountryVatRate extends Model
{
    protected $fillable = [
        'country_code',
        'country_name',
        'standard_rate',
        'reduced_rate',
    ];

    protected function casts(): array
    {
        return [
            'standard_rate' => 'decimal:2',
            'reduced_rate' => 'decimal:2',
        ];
    }

    public static function getStandardRate(string $countryCode): ?float
    {
        $record = static::where('country_code', $countryCode)->first();

        return $record ? (float) $record->standard_rate : null;
    }
}
