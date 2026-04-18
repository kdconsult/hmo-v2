<?php

namespace App\Models;

use App\Events\OssThresholdExceeded;
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

        // Capture whether any record for this year already crossed the threshold BEFORE this write.
        $alreadyCrossed = static::where('year', $year)->whereNotNull('threshold_exceeded_at')->exists();

        $record->accumulated_amount_eur = bcadd((string) $record->accumulated_amount_eur, (string) $amountEur, 2);
        $record->save(); // Save first so DB sum reflects the new amount when we query below.

        if (! $alreadyCrossed && static::isThresholdExceeded($year)) {
            $record->threshold_exceeded_at = now();
            $record->save();

            OssThresholdExceeded::dispatch($year);
        }

        return $record;
    }

    public static function isThresholdExceeded(int $year): bool
    {
        $total = (string) static::where('year', $year)->sum('accumulated_amount_eur');

        return bccomp($total, '10000.00', 2) > 0;
    }
}
