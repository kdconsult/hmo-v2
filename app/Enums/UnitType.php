<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum UnitType: string implements HasLabel
{
    case Mass = 'mass';
    case Volume = 'volume';
    case Length = 'length';
    case Area = 'area';
    case Time = 'time';
    case Piece = 'piece';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Mass => __('Mass'),
            self::Volume => __('Volume'),
            self::Length => __('Length'),
            self::Area => __('Area'),
            self::Time => __('Time'),
            self::Piece => __('Piece'),
            self::Other => __('Other'),
        };
    }
}
