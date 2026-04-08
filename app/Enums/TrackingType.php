<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TrackingType: string implements HasLabel
{
    case None = 'none';
    case Serial = 'serial';
    case Batch = 'batch';

    public function getLabel(): string
    {
        return match ($this) {
            self::None => __('None'),
            self::Serial => __('Serial Number'),
            self::Batch => __('Batch'),
        };
    }
}
