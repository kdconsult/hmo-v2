<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EuOssAccumulation extends Model
{
    protected $fillable = [
        'year',
        'country_code',
        'accumulated_amount_eur',
        'threshold_exceeded_at',
    ];

    protected function casts(): array
    {
        return [
            'accumulated_amount_eur' => 'decimal:2',
            'threshold_exceeded_at' => 'datetime',
        ];
    }

    public static function accumulate(string $countryCode, int $year, float $amountEur): self
    {
        $record = static::firstOrCreate(
            ['year' => $year, 'country_code' => $countryCode],
            ['accumulated_amount_eur' => 0]
        );

        $record->accumulated_amount_eur = bcadd((string) $record->accumulated_amount_eur, (string) $amountEur, 2);

        if ($record->threshold_exceeded_at === null && static::isThresholdExceeded($year)) {
            $record->threshold_exceeded_at = now();
        }

        $record->save();

        return $record;
    }

    public static function isThresholdExceeded(int $year): bool
    {
        $total = (string) static::where('year', $year)->sum('accumulated_amount_eur');

        return bccomp($total, '10000.00', 2) > 0;
    }
}
