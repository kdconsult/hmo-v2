<?php

namespace App\Filament\Widgets;

use App\Models\EuOssAccumulation;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OssThresholdWidget extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $year = (int) now()->year;
        $total = (float) EuOssAccumulation::where('year', $year)->sum('accumulated_amount_eur');
        $threshold = 10000.0;
        $percent = $threshold > 0 ? ($total / $threshold) * 100 : 0.0;

        $color = match (true) {
            $total >= $threshold => 'danger',
            $total >= $threshold * 0.8 => 'warning',
            default => 'success',
        };

        return [
            Stat::make('EU OSS Accumulation '.$year, '€'.number_format($total, 2))
                ->description(number_format(min($percent, 100), 1).'% of €10,000 threshold')
                ->color($color),
        ];
    }
}
